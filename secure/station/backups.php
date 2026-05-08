<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';

station_require_builder();
$user = station_current_user();
$isAdmin = station_is_admin($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $project = station_safe_name((string) ($_POST['project'] ?? ''));
    $archiveName = (string) ($_POST['archive_name'] ?? '');

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
        station_flash_set(!empty($result['ok']) ? 'ok' : 'error', (string) ($result['message'] ?? 'Archive failed.'));
        header('Location: station.php');
        exit;
    }

    if ($action === 'restore_archive') {
        $result = station_restore_archived_project($archiveName);
        station_log_event('project.archive.restored', ['archive' => $archiveName, 'ok' => !empty($result['ok'])]);
        station_flash_set(!empty($result['ok']) ? 'ok' : 'error', (string) ($result['message'] ?? 'Restore failed.'));
        header('Location: station.php');
        exit;
    }

    if ($action === 'restore_backup') {
        $result = station_restore_backup_zip($archiveName);
        station_log_event('project.backup.restored', ['archive' => $archiveName, 'ok' => !empty($result['ok'])]);
        station_flash_set(!empty($result['ok']) ? 'ok' : 'error', (string) ($result['message'] ?? 'Backup restore failed.'));
        header('Location: station.php');
        exit;
    }

    if ($action === 'delete_project' && $isAdmin) {
        $result = station_delete_project_permanently($project);
        station_log_event('project.deleted', ['project' => $project, 'ok' => !empty($result['ok'])]);
        station_flash_set(!empty($result['ok']) ? 'ok' : 'error', (string) ($result['message'] ?? 'Delete failed.'));
        header('Location: station.php');
        exit;
    }
}

header('Location: station.php');
exit;
