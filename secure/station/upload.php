<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/templates.php';

station_require_builder();
$user = station_current_user();
$owner = (string) ($user['username'] ?? 'unknown');
$adminSettings = station_admin_settings();
$defaultAccessMode = (string) ($adminSettings['defaultProjectAccessMode'] ?? 'admin');
$defaultAccessMode = isset(station_allowed_project_access_modes()[$defaultAccessMode]) ? $defaultAccessMode : 'admin';
$defaultVisibility = $defaultAccessMode === 'public' ? 'public' : 'private';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: station.php');
    exit;
}

$action = (string) ($_POST['action'] ?? '');
$projectName = trim((string) ($_POST['project_name'] ?? ''));
if ($projectName === '') {
    station_flash_set('error', 'Project name is required.');
    header('Location: station.php');
    exit;
}

$slug = station_unique_slug($projectName);
$projectPath = station_project_path($slug);
if (!mkdir($projectPath, 0755, true) && !is_dir($projectPath)) {
    station_flash_set('error', 'Could not create project folder.');
    header('Location: station.php');
    exit;
}

$templateType = '';
$ok = false;
if ($action === 'upload_zip') {
    $ok = station_handle_zip_upload($projectPath);
} elseif ($action === 'upload_folder') {
    $ok = station_handle_folder_upload($projectPath);
} elseif ($action === 'upload_single') {
    $ok = station_handle_single_upload($projectPath);
} elseif ($action === 'create_template') {
    $templateType = (string) ($_POST['template_type'] ?? 'static-js');
    $ok = station_handle_template_create($projectPath, $templateType, $projectName);
}

if (!$ok) {
    station_rrmdir($projectPath);
    station_flash_set('error', 'Deploy failed. Check file type/permissions and try again.');
    header('Location: station.php');
    exit;
}

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

station_flash_set('ok', 'Project deployed: ' . $slug);
header('Location: viewer.php?project=' . urlencode($slug));
exit;

function station_handle_zip_upload(string $projectPath): bool
{
    if (!isset($_FILES['zip_file']) || !is_array($_FILES['zip_file'])) {
        return false;
    }
    $file = $_FILES['zip_file'];
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return false;
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        return false;
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (!station_is_safe_relative_path($name)) {
            $zip->close();
            return false;
        }
    }

    $ok = $zip->extractTo($projectPath);
    $zip->close();
    return $ok;
}

function station_handle_folder_upload(string $projectPath): bool
{
    if (!isset($_FILES['folder_files'])) {
        return false;
    }

    $names = $_FILES['folder_files']['name'] ?? [];
    $tmpNames = $_FILES['folder_files']['tmp_name'] ?? [];
    $errors = $_FILES['folder_files']['error'] ?? [];

    if (!is_array($names) || !is_array($tmpNames) || !is_array($errors)) {
        return false;
    }

    $count = count($names);
    if ($count === 0) {
        return false;
    }

    for ($i = 0; $i < $count; $i++) {
        if ((int) ($errors[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return false;
        }

        $name = (string) ($names[$i] ?? '');
        $tmp = (string) ($tmpNames[$i] ?? '');
        if ($name === '' || $tmp === '' || !is_uploaded_file($tmp)) {
            return false;
        }

        $relative = str_replace('\\', '/', ltrim($name, '/'));
        if (!station_is_safe_relative_path($relative)) {
            return false;
        }

        $target = $projectPath . '/' . $relative;
        $parent = dirname($target);
        if (!is_dir($parent)) {
            mkdir($parent, 0755, true);
        }

        if (!move_uploaded_file($tmp, $target)) {
            return false;
        }
    }

    return true;
}

function station_handle_single_upload(string $projectPath): bool
{
    if (!isset($_FILES['single_file']) || !is_array($_FILES['single_file'])) {
        return false;
    }

    $file = $_FILES['single_file'];
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return false;
    }

    $name = basename((string) ($file['name'] ?? 'upload.file'));
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return false;
    }

    return move_uploaded_file($tmp, $projectPath . '/' . $name);
}

function station_handle_template_create(string $projectPath, string $templateType, string $projectName): bool
{
    $catalog = station_template_catalog();
    if (!isset($catalog[$templateType])) {
        return false;
    }

    $files = station_template_files($templateType, $projectName);
    foreach ($files as $relative => $content) {
        if (!station_is_safe_relative_path($relative)) {
            return false;
        }

        $target = $projectPath . '/' . $relative;
        $parent = dirname($target);
        if (!is_dir($parent)) {
            mkdir($parent, 0755, true);
        }
        if (file_put_contents($target, $content, LOCK_EX) === false) {
            return false;
        }
    }

    return true;
}

function station_is_safe_relative_path(string $path): bool
{
    $clean = trim(str_replace('\\', '/', $path));
    if ($clean === '' || str_starts_with($clean, '/') || str_contains($clean, '..')) {
        return false;
    }
    return !preg_match('/[[:cntrl:]]/', $clean);
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
