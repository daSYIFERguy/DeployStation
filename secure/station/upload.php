<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/templates.php';

@set_time_limit(0);
ignore_user_abort(true);

$projectPath = null;
$expectsJson = station_upload_expects_json();

register_shutdown_function(static function () use (&$projectPath, $expectsJson): void {
    $error = error_get_last();
    if (!is_array($error) || !station_is_fatal_upload_error((int) ($error['type'] ?? 0))) {
        return;
    }

    if (is_string($projectPath) && $projectPath !== '' && is_dir($projectPath)) {
        station_rrmdir($projectPath);
    }

    if ($expectsJson && !headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => 'The server failed while processing that upload. Try a smaller zip or check PHP/server upload limits.',
            'redirectUrl' => 'station.php'
        ], JSON_UNESCAPED_SLASHES);
    }
});

station_require_builder();
$user = station_current_user();
$owner = (string) ($user['username'] ?? 'unknown');
$adminSettings = station_admin_settings();
$defaultAccessMode = (string) ($adminSettings['defaultProjectAccessMode'] ?? 'admin');
$defaultAccessMode = isset(station_allowed_project_access_modes()[$defaultAccessMode]) ? $defaultAccessMode : 'admin';
$defaultVisibility = $defaultAccessMode === 'public' ? 'public' : 'private';

if (station_request_content_length_exceeds_post_limit()) {
    station_upload_finish(false, 'The upload was larger than the server allows. Increase PHP upload limits or use a smaller zip.', 'station.php', $expectsJson);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    station_upload_finish(false, 'Invalid request method.', 'station.php', $expectsJson);
    exit;
}

$action = (string) ($_POST['action'] ?? '');
$projectName = trim((string) ($_POST['project_name'] ?? ''));
if ($projectName === '') {
    station_upload_finish(false, 'Project name is required.', 'station.php', $expectsJson);
    exit;
}

$slug = station_unique_slug($projectName);
$projectPath = station_project_path($slug);
if (!mkdir($projectPath, 0755, true) && !is_dir($projectPath)) {
    station_upload_finish(false, 'Could not create project folder.', 'station.php', $expectsJson);
    exit;
}

$templateType = '';
$result = ['ok' => false, 'message' => 'Deploy failed. Check file type/permissions and try again.'];
if ($action === 'upload_zip') {
    $result = station_handle_zip_upload($projectPath);
} elseif ($action === 'upload_folder') {
    $result = station_handle_folder_upload($projectPath);
} elseif ($action === 'upload_single') {
    $result = station_handle_single_upload($projectPath);
} elseif ($action === 'create_template') {
    $templateType = (string) ($_POST['template_type'] ?? 'static-js');
    $result = station_handle_template_create($projectPath, $templateType, $projectName);
}

if (empty($result['ok'])) {
    station_rrmdir($projectPath);
    station_upload_finish(false, (string) ($result['message'] ?? 'Deploy failed. Check file type/permissions and try again.'), 'station.php', $expectsJson);
    exit;
}

station_write_project_access_router($slug, $projectPath);

$project = [
    'slug' => $slug,
    'owner' => $owner,
    'sourceType' => $action,
    'templateType' => $templateType,
    'visibility' => !empty($adminSettings['allowPublicProjects']) ? $defaultVisibility : 'private',
    'accessMode' => !empty($adminSettings['allowPublicProjects']) ? $defaultAccessMode : 'admin',
    'createdAt' => gmdate('c'),
    'updatedAt' => gmdate('c')
];
station_upsert_project_meta($project);
station_log_event('project.deployed', ['slug' => $slug, 'sourceType' => $action, 'owner' => $owner]);

station_upload_finish(true, 'Project deployed: ' . $slug, 'viewer.php?project=' . urlencode($slug), $expectsJson);
exit;

function station_handle_zip_upload(string $projectPath): array
{
    if (!isset($_FILES['zip_file']) || !is_array($_FILES['zip_file'])) {
        return ['ok' => false, 'message' => 'Choose a zip file to upload.'];
    }
    $file = $_FILES['zip_file'];
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => station_upload_error_message((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE), 'zip')];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'The uploaded zip file was not available on the server.'];
    }

    return station_extract_zip_to_project($tmp, $projectPath);
}

function station_request_content_length_exceeds_post_limit(): bool
{
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postLimit = station_parse_ini_size((string) ini_get('post_max_size'));
    return $contentLength > 0 && $postLimit > 0 && $contentLength > $postLimit;
}

function station_parse_ini_size(string $value): int
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return 0;
    }

    $suffix = strtolower(substr($trimmed, -1));
    $number = (float) $trimmed;

    return match ($suffix) {
        'g' => (int) round($number * 1024 * 1024 * 1024),
        'm' => (int) round($number * 1024 * 1024),
        'k' => (int) round($number * 1024),
        default => (int) round((float) $trimmed),
    };
}

function station_is_fatal_upload_error(int $type): bool
{
    return in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
}

function station_extract_zip_to_project(string $zipPath, string $projectPath): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['ok' => false, 'message' => 'Could not open that zip archive.'];
    }

    $entries = [];
    $rootPrefix = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $relative = station_normalize_archive_path($name);
        if ($relative === null) {
            $zip->close();
            return ['ok' => false, 'message' => 'The zip contains an invalid path: ' . $name];
        }
        if ($relative === '') {
            continue;
        }

        $entries[] = $relative;

        $segments = explode('/', rtrim($relative, '/'));
        $first = $segments[0] ?? '';
        if ($first === '') {
            $rootPrefix = '';
            continue;
        }
        if ($rootPrefix === null) {
            $rootPrefix = $first;
            continue;
        }
        if ($rootPrefix !== $first) {
            $rootPrefix = '';
        }
    }

    foreach ($entries as $relative) {
        $targetRelative = $relative;
        if ($rootPrefix !== null && $rootPrefix !== '') {
            $targetRelative = ltrim(substr($relative, strlen($rootPrefix)), '/');
        }

        if ($targetRelative === '') {
            continue;
        }

        $targetPath = $projectPath . '/' . $targetRelative;
        if (str_ends_with($relative, '/')) {
            if (!is_dir($targetPath) && !mkdir($targetPath, 0755, true) && !is_dir($targetPath)) {
                $zip->close();
                return ['ok' => false, 'message' => 'Could not create project folders from the zip.'];
            }
            continue;
        }

        $parent = dirname($targetPath);
        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
            $zip->close();
            return ['ok' => false, 'message' => 'Could not prepare folders for the zip contents.'];
        }

        $stream = $zip->getStream($relative);
        if ($stream === false) {
            $zip->close();
            return ['ok' => false, 'message' => 'Could not read a file from the zip archive.'];
        }

        $targetHandle = fopen($targetPath, 'wb');
        if ($targetHandle === false) {
            fclose($stream);
            $zip->close();
            return ['ok' => false, 'message' => 'Could not write extracted files to the project folder.'];
        }

        stream_copy_to_stream($stream, $targetHandle);
        fclose($stream);
        fclose($targetHandle);
    }

    $zip->close();
    return ['ok' => true, 'message' => 'Zip extracted.'];
}

function station_handle_folder_upload(string $projectPath): array
{
    if (!isset($_FILES['folder_files'])) {
        return ['ok' => false, 'message' => 'Choose a folder to upload.'];
    }

    $names = $_FILES['folder_files']['name'] ?? [];
    $fullPaths = $_FILES['folder_files']['full_path'] ?? [];
    $tmpNames = $_FILES['folder_files']['tmp_name'] ?? [];
    $errors = $_FILES['folder_files']['error'] ?? [];

    if (!is_array($names) || !is_array($tmpNames) || !is_array($errors)) {
        return ['ok' => false, 'message' => 'The folder upload payload was not recognized by the server.'];
    }

    $count = count($names);
    if ($count === 0) {
        return ['ok' => false, 'message' => 'The selected folder did not include any files.'];
    }

    $entries = [];

    for ($i = 0; $i < $count; $i++) {
        if ((int) ($errors[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => station_upload_error_message((int) ($errors[$i] ?? UPLOAD_ERR_NO_FILE), 'folder')];
        }

        $name = (string) ($names[$i] ?? '');
        $tmp = (string) ($tmpNames[$i] ?? '');
        if ($name === '' || $tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'message' => 'One of the uploaded folder files was not available on the server.'];
        }

        $relativeSource = is_array($fullPaths) ? (string) ($fullPaths[$i] ?? '') : '';
        $relative = station_normalize_archive_path($relativeSource !== '' ? $relativeSource : $name);
        if ($relative === null) {
            return ['ok' => false, 'message' => 'The uploaded folder contains an invalid path.'];
        }
        if ($relative === '') {
            continue;
        }

        $entries[] = [
            'relative' => $relative,
            'tmp' => $tmp
        ];
    }

    if ($entries === []) {
        return ['ok' => false, 'message' => 'The selected folder only contained ignored system files.'];
    }

    $rootPrefix = station_detect_common_root_prefix(array_map(
        static fn (array $entry): string => (string) $entry['relative'],
        $entries
    ));

    foreach ($entries as $entry) {
        $relative = (string) $entry['relative'];
        $tmp = (string) $entry['tmp'];
        $targetRelative = $rootPrefix !== '' ? ltrim(substr($relative, strlen($rootPrefix)), '/') : $relative;
        if ($targetRelative === '') {
            continue;
        }

        $target = $projectPath . '/' . $targetRelative;
        $parent = dirname($target);
        if (!is_dir($parent)) {
            mkdir($parent, 0755, true);
        }

        if (!move_uploaded_file($tmp, $target)) {
            return ['ok' => false, 'message' => 'Could not move one of the uploaded folder files into the project.'];
        }
    }

    return ['ok' => true, 'message' => 'Folder uploaded.'];
}

function station_handle_single_upload(string $projectPath): array
{
    if (!isset($_FILES['single_file']) || !is_array($_FILES['single_file'])) {
        return ['ok' => false, 'message' => 'Choose a file to upload.'];
    }

    $file = $_FILES['single_file'];
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => station_upload_error_message((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE), 'file')];
    }

    $name = basename((string) ($file['name'] ?? 'upload.file'));
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'The uploaded file was not available on the server.'];
    }

    if (station_should_ignore_upload_path($name)) {
        return ['ok' => false, 'message' => 'That file is ignored automatically.'];
    }

    return move_uploaded_file($tmp, $projectPath . '/' . $name)
        ? ['ok' => true, 'message' => 'File uploaded.']
        : ['ok' => false, 'message' => 'Could not move the uploaded file into the project.'];
}

function station_handle_template_create(string $projectPath, string $templateType, string $projectName): array
{
    $catalog = station_template_catalog();
    if (!isset($catalog[$templateType])) {
        return ['ok' => false, 'message' => 'That template is not available.'];
    }

    $files = station_template_files($templateType, $projectName);
    foreach ($files as $relative => $content) {
        if (!station_is_safe_relative_path($relative)) {
            return ['ok' => false, 'message' => 'The template contains an invalid file path.'];
        }

        $target = $projectPath . '/' . $relative;
        $parent = dirname($target);
        if (!is_dir($parent)) {
            mkdir($parent, 0755, true);
        }
        if (file_put_contents($target, $content, LOCK_EX) === false) {
            return ['ok' => false, 'message' => 'Could not write one of the template files.'];
        }
    }

    return ['ok' => true, 'message' => 'Template created.'];
}

function station_normalize_archive_path(string $path): ?string
{
    $clean = trim(str_replace('\\', '/', $path));
    $clean = ltrim($clean, '/');
    if ($clean === '') {
        return '';
    }
    if (station_should_ignore_upload_path($clean)) {
        return '';
    }
    if (!station_is_safe_relative_path($clean)) {
        return null;
    }

    return $clean;
}

function station_should_ignore_upload_path(string $path): bool
{
    $clean = trim(str_replace('\\', '/', $path));
    if ($clean === '') {
        return false;
    }

    $parts = explode('/', trim($clean, '/'));
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '.DS_Store' || $part === '__MACOSX' || str_starts_with($part, '._')) {
            return true;
        }
    }

    return false;
}

function station_upload_error_message(int $code, string $label): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The ' . $label . ' upload was too large for the server limits.',
        UPLOAD_ERR_PARTIAL => 'The ' . $label . ' upload was interrupted before it finished.',
        UPLOAD_ERR_NO_FILE => 'No ' . $label . ' was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server is missing a temporary upload folder.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded ' . $label . ' to disk.',
        UPLOAD_ERR_EXTENSION => 'A server extension stopped the ' . $label . ' upload.',
        default => 'The ' . $label . ' upload failed unexpectedly.'
    };
}

function station_detect_common_root_prefix(array $paths): string
{
    $rootPrefix = null;
    foreach ($paths as $path) {
        $trimmed = trim($path, '/');
        if ($trimmed === '' || !str_contains($trimmed, '/')) {
            return '';
        }
        $first = explode('/', $trimmed, 2)[0] ?? '';
        if ($first === '') {
            return '';
        }
        if ($rootPrefix === null) {
            $rootPrefix = $first;
            continue;
        }
        if ($rootPrefix !== $first) {
            return '';
        }
    }

    return $rootPrefix !== null ? $rootPrefix . '/' : '';
}

function station_upload_expects_json(): bool
{
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
}

function station_upload_finish(bool $ok, string $message, string $redirectUrl, bool $expectsJson): void
{
    if ($expectsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => $ok,
            'message' => $message,
            'redirectUrl' => $redirectUrl
        ], JSON_UNESCAPED_SLASHES);
        return;
    }

    station_flash_set($ok ? 'ok' : 'error', $message);
    header('Location: ' . $redirectUrl);
}

function station_write_project_access_router(string $slug, string $projectPath): void
{
    $slug = station_safe_name($slug);
    if ($slug === '') {
        return;
    }

    $htaccessPath = $projectPath . '/.htaccess';
    if (!file_exists($htaccessPath)) {
        $projectServePath = station_project_serve_path($slug);
        $rewrite = "RewriteEngine On\nRewriteRule ^$ " . $projectServePath . " [L,QSA]\nRewriteRule ^(.*)$ " . $projectServePath . "&path=$1 [L,QSA,B]\n";
        @file_put_contents($htaccessPath, $rewrite, LOCK_EX);
    }
}

function station_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($it as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}
