<?php

declare(strict_types=1);

/**
 * JSON workspace API for scripts and integrations (same auth rules as the web UI).
 *
 * GET  ?project=SLUG&action=list
 * GET  ?project=SLUG&action=file&path=relative/path.txt
 * POST JSON or form: project, path, content — requires builder (same as editor.php).
 */

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';

station_require_login();
$user = station_current_user();

header('Cache-Control: no-store, no-cache, must-revalidate');

$jsonError = static function (int $http, string $message): void {
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
};

$project = station_safe_name((string) ($_GET['project'] ?? ''));
if ($project === '') {
    $jsonError(400, 'Missing project parameter.');
}
if (!station_project_exists($project)) {
    $jsonError(404, 'Project not found.');
}
if (!station_user_may_access_project($user, $project)) {
    $jsonError(403, 'Forbidden.');
}

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $action = (string) ($_GET['action'] ?? '');
    if ($action === 'list') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'project' => $project,
            'files' => station_scan_project_files($project),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($action === 'file') {
        $path = trim((string) ($_GET['path'] ?? ''));
        if ($path === '') {
            $jsonError(400, 'Missing path parameter.');
        }
        $content = station_read_project_file($project, $path);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'project' => $project,
            'path' => $path,
            'readable' => $content !== null,
            'content' => $content,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $jsonError(400, 'Unknown action. Use list or file.');
}

if ($method === 'POST') {
    if (!station_can_build($user)) {
        $jsonError(403, 'Builder access required to save files.');
    }
    $raw = (string) file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }
    $postProject = station_safe_name((string) ($body['project'] ?? ''));
    if ($postProject !== '' && $postProject !== $project) {
        $jsonError(400, 'Project mismatch between URL and body.');
    }
    $path = trim((string) ($body['path'] ?? ''));
    if ($path === '') {
        $jsonError(400, 'Missing path in body.');
    }
    $content = (string) ($body['content'] ?? '');
    if (!station_write_project_file($project, $path, $content)) {
        $jsonError(500, 'Save failed (permissions, path, or unsupported extension).');
    }
    station_log_event('project.file.saved.api', ['project' => $project, 'file' => $path]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'project' => $project, 'path' => $path], JSON_UNESCAPED_SLASHES);
    exit;
}

$jsonError(405, 'Method not allowed.');
