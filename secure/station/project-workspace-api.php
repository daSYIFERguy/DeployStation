<?php

declare(strict_types=1);

/**
 * Project workspace API (IDE / file explorer).
 *
 * GET  ?project=SLUG&action=browse&dir=
 * GET  ?project=SLUG&action=file&path=
 * GET  ?project=SLUG&action=list  (legacy flat index)
 * POST JSON: action=save|mkdir|delete|rename — builder required
 * POST multipart: action=upload, dir=, file=
 */

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/docker.php';

station_require_login();
$user = station_current_user();

header('Cache-Control: no-store, no-cache, must-revalidate');

$jsonOut = static function (array $payload, int $http = 200): void {
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
};

$jsonError = static function (int $http, string $message) use ($jsonOut): void {
    $jsonOut(['ok' => false, 'error' => $message], $http);
};

$project = station_safe_name((string) ($_GET['project'] ?? $_POST['project'] ?? ''));
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
    $action = (string) ($_GET['action'] ?? 'browse');
    if ($action === 'list') {
        $jsonOut(['ok' => true, 'project' => $project, 'files' => station_scan_project_files($project)]);
    }
    if ($action === 'browse') {
        $dir = trim(str_replace('\\', '/', (string) ($_GET['dir'] ?? '')), '/');
        $mode = (string) ($_GET['mode'] ?? 'explorer');
        $listing = $mode === 'entrypoint'
            ? station_project_list_workspace_dir($project, $dir)
            : station_project_explorer_browse($project, $dir);
        $jsonOut(array_merge(['project' => $project], $listing), !empty($listing['ok']) ? 200 : 400);
    }
    if ($action === 'file') {
        $path = trim(str_replace('\\', '/', (string) ($_GET['path'] ?? '')));
        if ($path === '' || !station_is_safe_relative_path($path)) {
            $jsonError(400, 'Missing or invalid path.');
        }
        $content = station_read_project_file($project, $path);
        $projectPath = station_project_path($project);
        $full = $projectPath . '/' . $path;
        $jsonOut([
            'ok' => true,
            'project' => $project,
            'path' => $path,
            'readable' => $content !== null,
            'content' => $content,
            'size' => is_file($full) ? (int) filesize($full) : 0,
            'modifiedAt' => is_file($full) ? gmdate('c', (int) filemtime($full)) : null,
        ]);
    }
    $jsonError(400, 'Unknown action. Use browse, file, or list.');
}

if ($method === 'POST') {
    if (!station_can_build($user)) {
        $jsonError(403, 'Builder access required to modify files.');
    }

    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    $isMultipart = str_contains($contentType, 'multipart/form-data');

    if ($isMultipart) {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'upload') {
            $dir = trim(str_replace('\\', '/', (string) ($_POST['dir'] ?? '')), '/');
            $file = $_FILES['file'] ?? null;
            if (!is_array($file)) {
                $jsonError(400, 'Missing file upload.');
            }
            $result = station_project_workspace_upload($project, $dir, $file);
            station_log_event('project.file.uploaded', ['project' => $project, 'path' => $result['path'] ?? '']);
            $jsonOut(array_merge(['project' => $project], $result), !empty($result['ok']) ? 200 : 400);
        }
        $jsonError(400, 'Unknown multipart action.');
    }

    $raw = (string) file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    $action = (string) ($body['action'] ?? 'save');

    if ($action === 'save') {
        $path = trim(str_replace('\\', '/', (string) ($body['path'] ?? '')));
        if ($path === '' || !station_is_safe_relative_path($path)) {
            $jsonError(400, 'Missing or invalid path.');
        }
        $content = (string) ($body['content'] ?? '');
        if (!station_write_project_file($project, $path, $content)) {
            $jsonError(500, 'Save failed.');
        }
        station_log_event('project.file.saved.api', ['project' => $project, 'file' => $path]);
        $jsonOut(['ok' => true, 'project' => $project, 'path' => $path]);
    }

    if ($action === 'mkdir') {
        $dir = trim(str_replace('\\', '/', (string) ($body['path'] ?? $body['dir'] ?? '')));
        $result = station_project_workspace_mkdir($project, $dir);
        $jsonOut(array_merge(['project' => $project], $result), !empty($result['ok']) ? 200 : 400);
    }

    if ($action === 'delete') {
        $path = trim(str_replace('\\', '/', (string) ($body['path'] ?? '')));
        $result = station_project_workspace_delete($project, $path);
        station_log_event('project.file.deleted', ['project' => $project, 'path' => $path]);
        $jsonOut(array_merge(['project' => $project], $result), !empty($result['ok']) ? 200 : 400);
    }

    if ($action === 'rename') {
        $from = trim(str_replace('\\', '/', (string) ($body['from'] ?? '')));
        $to = trim(str_replace('\\', '/', (string) ($body['to'] ?? '')));
        $result = station_project_workspace_rename($project, $from, $to);
        $jsonOut(array_merge(['project' => $project], $result), !empty($result['ok']) ? 200 : 400);
    }

    $jsonError(400, 'Unknown action.');
}

$jsonError(405, 'Method not allowed.');
