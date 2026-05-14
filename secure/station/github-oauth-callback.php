<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/github-oauth.php';
require_once __DIR__ . '/lib/github-sync.php';

station_require_login();
if (!station_can_build(station_current_user())) {
    station_flash_set('error', 'Builder access required.');
    header('Location: station.php');
    exit;
}

$admin = station_admin_settings();
$clientId = trim((string) ($admin['githubOAuthClientId'] ?? ''));
$clientSecret = trim((string) ($admin['githubOAuthClientSecret'] ?? ''));
if ($clientId === '' || $clientSecret === '') {
    station_flash_set('error', 'GitHub OAuth is not fully configured on this server (missing client ID or secret).');
    header('Location: user-settings.php');
    exit;
}

$state = (string) ($_GET['state'] ?? '');
$expected = (string) ($_SESSION['github_oauth_state'] ?? '');
unset($_SESSION['github_oauth_state']);
if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
    station_flash_set('error', 'GitHub OAuth state did not match. Try signing in again.');
    header('Location: user-settings.php');
    exit;
}

$code = trim((string) ($_GET['code'] ?? ''));
if ($code === '') {
    $err = trim((string) ($_GET['error_description'] ?? $_GET['error'] ?? ''));
    station_flash_set('error', $err !== '' ? $err : 'GitHub did not return an authorization code.');
    header('Location: user-settings.php');
    exit;
}

$redirectUri = station_github_oauth_redirect_uri();
$ex = station_github_oauth_exchange_code($clientId, $clientSecret, $code, $redirectUri);
if (empty($ex['ok']) || trim((string) ($ex['access_token'] ?? '')) === '') {
    station_flash_set('error', (string) ($ex['message'] ?? 'Token exchange failed.'));
    header('Location: user-settings.php');
    exit;
}

$accessToken = trim((string) $ex['access_token']);
$userApi = station_github_api('GET', '/user', $accessToken);
$login = '';
if (!empty($userApi['ok'])) {
    $u = json_decode((string) ($userApi['body'] ?? ''), true);
    if (is_array($u)) {
        $login = trim((string) ($u['login'] ?? ''));
    }
}

$username = station_current_username();
$profile = station_user_profile($username);
if (!isset($profile['integrations']) || !is_array($profile['integrations'])) {
    $profile['integrations'] = [];
}
$prev = isset($profile['integrations']['github']) && is_array($profile['integrations']['github'])
    ? $profile['integrations']['github']
    : [];

$profile['integrations']['github'] = array_merge($prev, [
    'enabled' => true,
    'username' => $login !== '' ? $login : (string) ($prev['username'] ?? ''),
    'token' => $accessToken,
    'authMode' => 'oauth',
    'oauthConnectedAt' => gmdate('c'),
]);

if (station_save_user_profile($username, $profile)) {
    station_log_event('github.oauth.connected', ['username' => $username, 'github_login' => $login]);
    station_flash_set('ok', 'GitHub account connected. You can import repos and use sync with this OAuth token.');
} else {
    station_flash_set('error', 'Could not save your profile after GitHub login.');
}

$after = basename((string) ($_SESSION['github_oauth_redirect_after'] ?? 'user-settings.php'));
unset($_SESSION['github_oauth_redirect_after']);
$allowedAfter = ['user-settings.php', 'station.php', 'github-sync.php'];
if (!in_array($after, $allowedAfter, true)) {
    $after = 'user-settings.php';
}
header('Location: ' . $after);
exit;
