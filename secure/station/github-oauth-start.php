<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/github-auth.php';

station_require_login();
if (!station_can_build(station_current_user())) {
    station_flash_set('error', 'Builder access required to connect GitHub.');
    header('Location: station.php');
    exit;
}

$begin = station_github_oauth_begin('connect', 'user-settings.php');
if (empty($begin['ok']) || empty($begin['redirect'])) {
    station_flash_set('error', (string) ($begin['message'] ?? 'Could not start GitHub OAuth.'));
    header('Location: user-settings.php');
    exit;
}

header('Location: ' . (string) $begin['redirect'], true, 302);
exit;
