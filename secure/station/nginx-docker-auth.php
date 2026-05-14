<?php

declare(strict_types=1);

/**
 * Nginx `auth_request` subrequest handler for `/p/<slug>/` reverse-proxy routes.
 *
 * Returns HTTP 200 (empty body) when the browser's session is allowed to reach
 * this dockerized project (same rules as project-serve.php). Returns 403 otherwise.
 * We use 200 instead of 204 for maximum compatibility with nginx auth_request.
 *
 * Emergency bypass (not for production): enable **Admin → Projects → Bypass docker
 * auth_request** or set `STATION_DOCKER_AUTH_BYPASS=1` in php-fpm / nginx `fastcgi_param`
 * on Station PHP — then any existing project slug returns 200 without session checks.
 *
 * @see station_render_nginx_projects_conf() in lib/docker.php
 */

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    if (!station_is_setup_complete()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Setup incomplete';
        exit;
    }

    $slug = station_safe_name((string) ($_GET['project'] ?? ''));
    if ($slug === '' || !station_project_exists($slug)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }

    if (station_nginx_docker_auth_bypass_active()) {
        http_response_code(200);
        header('Content-Length: 0');
        exit;
    }

    $user = station_current_user();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if (!station_user_may_access_project($user, $slug)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }

    http_response_code(200);
    header('Content-Length: 0');
} catch (\Throwable $e) {
    error_log('nginx-docker-auth: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
}
exit;
