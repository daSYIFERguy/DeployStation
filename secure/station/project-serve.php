<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';

station_require_setup();

$slug = station_safe_name((string) ($_GET['project'] ?? ''));
$relativePath = trim(str_replace('\\', '/', (string) ($_GET['path'] ?? '')), '/');

if ($slug === '') {
    $pathInfo = trim((string) ($_SERVER['PATH_INFO'] ?? ''), '/');
    $parts = $pathInfo !== '' ? explode('/', $pathInfo) : [];
    $slug = station_safe_name((string) array_shift($parts));
    $relativePath = implode('/', array_map(static fn ($segment): string => rawurldecode((string) $segment), $parts));
}

if ($slug === '' || !station_project_exists($slug)) {
    http_response_code(404);
    exit('Project not found.');
}

$user = station_current_user();
if (!station_user_may_access_project($user, $slug)) {
    if (!$user) {
        header('Location: ' . station_station_url('index.php'));
        exit;
    }

    header('Location: ' . station_station_url('access-denied.php?project=' . urlencode($slug)));
    exit;
}

$projectPath = station_project_path($slug);

if ($relativePath !== '' && !station_is_safe_relative_path($relativePath)) {
    http_response_code(400);
    exit('Invalid path.');
}

$target = $relativePath !== '' ? $projectPath . '/' . $relativePath : $projectPath;

if (is_dir($target)) {
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $requestPath = (string) (parse_url($requestUri, PHP_URL_PATH) ?: '');
    $usesLegacyPathInfo = str_contains($requestPath, '/project-serve.php/');
    if ($usesLegacyPathInfo && $requestPath !== '' && !str_ends_with($requestPath, '/')) {
        $canonical = station_project_serve_path($slug, $relativePath);
        header('Location: ' . $canonical);
        exit;
    }

    foreach (['index.php', 'index.html', 'index.htm'] as $indexFile) {
        $candidate = rtrim($target, '/') . '/' . $indexFile;
        if (is_file($candidate)) {
            $target = $candidate;
            break;
        }
    }
}

if (!is_file($target) || !station_realpath_inside($projectPath, $target)) {
    http_response_code(404);
    exit('File not found.');
}

$extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));
if ($extension === 'php') {
    $cwd = getcwd();
    chdir(dirname($target));
    include $target;
    if (is_string($cwd) && $cwd !== '') {
        chdir($cwd);
    }
    exit;
}

$mime = station_project_asset_mime_type($target, $extension);
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($target));
readfile($target);
exit;

function station_project_asset_mime_type(string $target, string $extension): string
{
    return match ($extension) {
        'css' => 'text/css; charset=utf-8',
        'js', 'mjs' => 'application/javascript; charset=utf-8',
        'json', 'map' => 'application/json; charset=utf-8',
        'html', 'htm' => 'text/html; charset=utf-8',
        'txt', 'log' => 'text/plain; charset=utf-8',
        'svg' => 'image/svg+xml',
        'xml' => 'application/xml; charset=utf-8',
        'csv' => 'text/csv; charset=utf-8',
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        default => (string) (mime_content_type($target) ?: 'application/octet-stream'),
    };
}