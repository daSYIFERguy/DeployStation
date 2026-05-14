<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/docker.php';

station_require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$slugs = [];
$rawSlugs = $_GET['projects'] ?? $_POST['projects'] ?? '';
if (is_array($rawSlugs)) {
    $slugs = $rawSlugs;
} elseif (is_string($rawSlugs) && $rawSlugs !== '') {
    $slugs = explode(',', $rawSlugs);
}

$singleSlug = (string) ($_GET['project'] ?? $_POST['project'] ?? '');
if ($singleSlug !== '') {
    $slugs[] = $singleSlug;
}

$user = station_current_user();
$result = [];

if (!station_docker_enabled()) {
    echo json_encode(['ok' => true, 'enabled' => false, 'engine' => false, 'projects' => []], JSON_UNESCAPED_SLASHES);
    exit;
}

$engineCheck = station_docker_engine_available();
$engineOk = !empty($engineCheck['ok']);

foreach (array_unique(array_map('station_safe_name', $slugs)) as $slug) {
    if ($slug === '' || !station_project_exists($slug)) {
        continue;
    }
    if (!station_user_may_access_project($user, $slug)) {
        continue;
    }

    if (!$engineOk) {
        $result[$slug] = ['state' => 'unavailable', 'services' => [], 'output' => trim((string) ($engineCheck['output'] ?? ''))];
        continue;
    }
    $result[$slug] = station_project_docker_status($slug);
}

echo json_encode([
    'ok' => true,
    'enabled' => true,
    'engine' => $engineOk,
    'engineVersion' => trim((string) ($engineCheck['version'] ?? '')),
    'projects' => $result,
], JSON_UNESCAPED_SLASHES);
