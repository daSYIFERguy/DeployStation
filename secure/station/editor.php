<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';

station_require_login();

$project = station_safe_name((string) ($_GET['project'] ?? $_POST['project'] ?? ''));
$file = trim(str_replace('\\', '/', (string) ($_GET['file'] ?? $_POST['file'] ?? '')));

if ($project === '' || !station_project_exists($project)) {
    station_flash_set('error', 'Project not found.');
    header('Location: station.php');
    exit;
}

$q = 'viewer.php?project=' . rawurlencode($project);
if ($file !== '' && station_is_safe_relative_path($file)) {
    $q .= '&file=' . rawurlencode($file);
}
header('Location: ' . $q);
exit;
