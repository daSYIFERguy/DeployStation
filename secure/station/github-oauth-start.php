<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/github-oauth.php';

station_require_login();
if (!station_can_build(station_current_user())) {
    station_flash_set('error', 'Builder access required to connect GitHub.');
    header('Location: station.php');
    exit;
}

$admin = station_admin_settings();
if (empty($admin['githubEnabled'])) {
    station_flash_set('error', 'GitHub is disabled for this station.');
    header('Location: user-settings.php');
    exit;
}

$clientId = trim((string) ($admin['githubOAuthClientId'] ?? ''));
if ($clientId === '') {
    station_flash_set('error', 'GitHub OAuth is not configured yet. Ask the station owner to add an OAuth App Client ID under Admin → GitHub.');
    header('Location: user-settings.php');
    exit;
}

$redirect = station_github_oauth_redirect_uri();
if ($redirect === '') {
    station_flash_set('error', 'Could not build OAuth redirect URL (unknown HTTP host).');
    header('Location: user-settings.php');
    exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['github_oauth_state'] = $state;
$_SESSION['github_oauth_redirect_after'] = 'user-settings.php';

$url = station_github_oauth_authorize_url($clientId, $state);
if ($url === '') {
    station_flash_set('error', 'Could not start GitHub OAuth.');
    header('Location: user-settings.php');
    exit;
}

header('Location: ' . $url, true, 302);
exit;
