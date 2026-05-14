<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/docker.php';

station_require_builder();
$user = station_current_user();
$isAdmin = station_is_admin($user);
$isOwner = station_is_owner($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $project = station_safe_name((string) ($_POST['project'] ?? ''));
    $archiveName = (string) ($_POST['archive_name'] ?? '');

    if ($action === 'upload_backup_zip') {
        if (!$isOwner) {
            station_flash_set('error', 'Only the station owner can upload backup zips.');
            header('Location: station.php');
            exit;
        }
        if (!isset($_FILES['backup_zip']) || !is_array($_FILES['backup_zip'])) {
            station_flash_set('error', 'Choose a .zip file to upload.');
            header('Location: station.php');
            exit;
        }
        $f = $_FILES['backup_zip'];
        if ((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            station_flash_set('error', 'Upload failed (code ' . (string) ($f['error'] ?? '') . ').');
            header('Location: station.php');
            exit;
        }
        $orig = basename((string) ($f['name'] ?? 'backup.zip'));
        $targetName = station_sanitize_stored_backup_zip_name($orig);
        if ($targetName === null) {
            $targetName = 'uploaded-' . gmdate('Ymd-His') . '.zip';
        }
        $dest = station_archives_dir() . '/' . $targetName;
        if (is_file($dest)) {
            $targetName = 'uploaded-' . gmdate('Ymd-His') . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '', $orig);
            if (!str_ends_with(strtolower($targetName), '.zip')) {
                $targetName .= '.zip';
            }
            $dest = station_archives_dir() . '/' . $targetName;
        }
        station_ensure_data_dir();
        if (!@move_uploaded_file((string) $f['tmp_name'], $dest)) {
            station_flash_set('error', 'Could not save uploaded backup to archives directory.');
            header('Location: station.php');
            exit;
        }
        @chmod($dest, 0640);
        station_log_event('project.backup.uploaded', ['file' => $targetName]);
        station_flash_set('ok', 'Backup zip saved as ' . $targetName . '.');
        header('Location: station.php');
        exit;
    }

    if ($action === 'backup_project') {
        $result = station_create_project_backup($project);
        station_log_event('project.backup.created', ['project' => $project, 'ok' => !empty($result['ok'])]);
        station_flash_set(!empty($result['ok']) ? 'ok' : 'error', (string) ($result['message'] ?? 'Backup failed.'));
        header('Location: station.php');
        exit;
    }

    if ($action === 'archive_project') {
        $result = station_archive_project($project);
        station_log_event('project.archived', ['project' => $project, 'ok' => !empty($result['ok'])]);
        if (!empty($result['ok'])) {
            station_touch_nginx_routes_after_project_mutation();
        }
        station_flash_set(!empty($result['ok']) ? 'ok' : 'error', (string) ($result['message'] ?? 'Archive failed.'));
        header('Location: station.php');
        exit;
    }

    if ($action === 'restore_archive') {
        $result = station_restore_archived_project($archiveName);
        station_log_event('project.archive.restored', ['archive' => $archiveName, 'ok' => !empty($result['ok'])]);
        if (!empty($result['ok'])) {
            station_touch_nginx_routes_after_project_mutation();
        }
        station_flash_set(!empty($result['ok']) ? 'ok' : 'error', (string) ($result['message'] ?? 'Restore failed.'));
        header('Location: station.php');
        exit;
    }

    if ($action === 'restore_backup') {
        $result = station_restore_backup_zip($archiveName);
        station_log_event('project.backup.restored', ['archive' => $archiveName, 'ok' => !empty($result['ok'])]);
        if (!empty($result['ok'])) {
            station_touch_nginx_routes_after_project_mutation();
        }
        station_flash_set(!empty($result['ok']) ? 'ok' : 'error', (string) ($result['message'] ?? 'Backup restore failed.'));
        header('Location: station.php');
        exit;
    }

    if ($action === 'delete_project' && $isAdmin) {
        $result = station_delete_project_permanently($project);
        station_log_event('project.deleted', ['project' => $project, 'ok' => !empty($result['ok'])]);
        if (!empty($result['ok'])) {
            station_touch_nginx_routes_after_project_mutation();
        }
        station_flash_set(!empty($result['ok']) ? 'ok' : 'error', (string) ($result['message'] ?? 'Delete failed.'));
        header('Location: station.php');
        exit;
    }
}

header('Location: station.php');
exit;
