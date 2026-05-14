<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/github-sync.php';

station_require_login();
header('Content-Type: application/json; charset=utf-8');

if (!station_can_build(station_current_user())) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Builder access required.'], JSON_UNESCAPED_SLASHES);
    exit;
}

$admin = station_admin_settings();
if (empty($admin['githubEnabled'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'GitHub is disabled for this station.'], JSON_UNESCAPED_SLASHES);
    exit;
}

$profile = station_user_profile(station_current_username());
if (!station_integration_ready($profile, 'github')) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Connect GitHub under User Settings first.'], JSON_UNESCAPED_SLASHES);
    exit;
}

$token = trim((string) ($profile['integrations']['github']['token'] ?? ''));
$pages = (int) ($_GET['pages'] ?? 3);
$pages = max(1, min(8, $pages));

$list = station_github_list_user_repositories($token, $pages);
echo json_encode($list, JSON_UNESCAPED_SLASHES);
exit;
