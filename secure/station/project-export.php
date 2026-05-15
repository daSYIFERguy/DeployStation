<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';

station_require_builder();

$user = station_current_user();
$project = station_safe_name((string) ($_REQUEST['project'] ?? ''));
$action = (string) ($_REQUEST['action'] ?? 'portable');

if ($project === '' || !station_project_exists($project)) {
    station_flash_set('error', 'Project not found.');
    header('Location: station.php');
    exit;
}

if (!station_user_may_access_project($user, $project)) {
    station_flash_set('error', 'Access denied.');
    header('Location: station.php');
    exit;
}

if (!station_can_build($user)) {
    station_flash_set('error', 'Builder access required to export projects.');
    header('Location: project-settings.php?project=' . urlencode($project));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $action !== 'portable') {
    station_flash_set('error', 'Invalid export request.');
    header('Location: project-settings.php?project=' . urlencode($project) . '#administration');
    exit;
}

$result = station_export_portable_project($project);
if (empty($result['ok'])) {
    station_flash_set('error', (string) ($result['message'] ?? 'Export failed.'));
    header('Location: project-settings.php?project=' . urlencode($project) . '#administration');
    exit;
}

$archive = (string) ($result['archiveFile'] ?? '');
if ($archive === '') {
    station_flash_set('error', 'Export file missing.');
    header('Location: project-settings.php?project=' . urlencode($project) . '#administration');
    exit;
}

$path = station_archives_dir() . '/' . $archive;
if (!is_file($path)) {
    station_flash_set('error', 'Export file not found on disk.');
    header('Location: project-settings.php?project=' . urlencode($project) . '#administration');
    exit;
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . basename($archive) . '"');
header('Content-Length: ' . (string) filesize($path));
readfile($path);
exit;
