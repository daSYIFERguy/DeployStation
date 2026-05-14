<?php

declare(strict_types=1);

/**
 * Nginx `auth_request` subrequest handler for `/p/<slug>/` reverse-proxy routes.
 *
 * Returns 204 when the browser's session is allowed to reach this dockerized
 * project (same rules as project-serve.php). Returns 403 otherwise.
 *
 * @see station_render_nginx_projects_conf() in lib/docker.php
 */

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

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

$user = station_current_user();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$accessMode = station_project_access_mode($slug);

if (!station_can_access_project($user, $accessMode)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

http_response_code(204);
exit;
