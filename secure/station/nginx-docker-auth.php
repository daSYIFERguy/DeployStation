<?php

declare(strict_types=1);

/**
 * Nginx `auth_request` subrequest handler for `/p/<slug>/` reverse-proxy routes.
 *
 * Returns HTTP 200 (empty body) when the browser is **signed in to Station** and
 * may open this project (same rules as project-serve.php). Anonymous requests always
 * get 403 here — Public on the dashboard still allows anonymous launch.php / files,
 * but not the docker reverse proxy at /p/&lt;slug&gt;/. Returns 403 otherwise.
 * We use 200 instead of 204 for maximum compatibility with nginx auth_request.
 *
 * Session: bootstrap detects this script (`station_script_is_nginx_docker_auth`) and either
 * starts with `read_and_close` or, if the session is already active, calls `session_write_close()`
 * only (never a second `session_start()` — that can 500). `$_SESSION` stays readable for auth.
 *
 * Emergency bypass (not for production): enable **Admin → Projects → Bypass docker
 * auth_request** or set `STATION_DOCKER_AUTH_BYPASS=1` in php-fpm / nginx `fastcgi_param`
 * on Station PHP — then any existing project slug returns 200 without session checks.
 *
 * Optional **signed query token** `?dp_t=` (Admin → Projects, on by default): when the
 * generated `auth_request` URI forwards `dp_t=$arg_dp_t` from the browser request,
 * a short-lived HMAC token allows 200 **without** a Station session cookie — useful when a CDN
 * or proxy mishandles cookies on `/p/…` while the main app still works. Treat issued URLs like secrets.
 *
 * @see station_render_nginx_projects_conf() in lib/docker.php
 */

if (!defined('STATION_AUTH_REQUEST_SESSION_READ_AND_CLOSE')) {
    define('STATION_AUTH_REQUEST_SESSION_READ_AND_CLOSE', true);
}

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/docker-proxy-token.php';

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

    $dpT = isset($_GET['dp_t']) ? (string) $_GET['dp_t'] : '';
    if ($dpT !== '' && !empty(station_admin_settings()['nginxDockerProxySignedQueryToken'])
        && station_docker_proxy_token_valid_for_slug($slug, $dpT)) {
        http_response_code(200);
        header('Content-Length: 0');
        exit;
    }

    $user = station_current_user();
    if ($user === null) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }

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
