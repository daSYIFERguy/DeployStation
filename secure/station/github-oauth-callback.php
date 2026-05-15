<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/github-oauth.php';
require_once __DIR__ . '/lib/github-auth.php';
require_once __DIR__ . '/lib/access-requests.php';

station_ensure_data_dir();

$admin = station_admin_settings();
$clientId = trim((string) ($admin['githubOAuthClientId'] ?? ''));
$clientSecret = trim((string) ($admin['githubOAuthClientSecret'] ?? ''));

$purpose = (string) ($_SESSION['github_oauth_purpose'] ?? 'connect');
$redirectAfter = (string) ($_SESSION['github_oauth_redirect_after'] ?? 'index.php');
unset($_SESSION['github_oauth_purpose'], $_SESSION['github_oauth_redirect_after']);

$failRedirect = match ($purpose) {
    'login', 'request_access' => 'index.php',
    default => 'user-settings.php',
};

$state = (string) ($_GET['state'] ?? '');
$expected = (string) ($_SESSION['github_oauth_state'] ?? '');
unset($_SESSION['github_oauth_state']);
if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
    station_flash_set('error', 'GitHub authorization expired or state did not match. Please try again.');
    header('Location: ' . $failRedirect);
    exit;
}

$code = trim((string) ($_GET['code'] ?? ''));
if ($code === '') {
    $err = trim((string) ($_GET['error_description'] ?? $_GET['error'] ?? ''));
    station_flash_set('error', $err !== '' ? $err : 'GitHub did not return an authorization code.');
    header('Location: ' . $failRedirect);
    exit;
}

if ($clientId === '' || $clientSecret === '') {
    station_flash_set('error', 'GitHub OAuth is not fully configured on this server.');
    header('Location: ' . $failRedirect);
    exit;
}

$redirectUri = station_github_oauth_redirect_uri();
$ex = station_github_oauth_exchange_code($clientId, $clientSecret, $code, $redirectUri);
if (empty($ex['ok']) || trim((string) ($ex['access_token'] ?? '')) === '') {
    station_flash_set('error', (string) ($ex['message'] ?? 'Token exchange failed.'));
    header('Location: ' . $failRedirect);
    exit;
}

$accessToken = trim((string) $ex['access_token']);
$ghProfile = station_github_fetch_user_profile($accessToken);
if (empty($ghProfile['ok'])) {
    station_flash_set('error', (string) ($ghProfile['message'] ?? 'Could not load GitHub profile.'));
    header('Location: ' . $failRedirect);
    exit;
}

$githubLogin = (string) ($ghProfile['login'] ?? '');

if ($purpose === 'request_access') {
    $name = trim((string) ($_SESSION['github_request_access_name'] ?? ''));
    $email = trim((string) ($_SESSION['github_request_access_email'] ?? ''));
    unset($_SESSION['github_request_access_name'], $_SESSION['github_request_access_email']);

    if ($name === '' && (string) ($ghProfile['name'] ?? '') !== '') {
        $name = (string) $ghProfile['name'];
    }
    if ($email === '' && (string) ($ghProfile['email'] ?? '') !== '') {
        $email = (string) $ghProfile['email'];
    }

    $result = station_access_request_create([
        'name' => $name,
        'email' => $email,
        'githubLogin' => $githubLogin,
        'githubId' => $ghProfile['id'] ?? '',
        'githubName' => (string) ($ghProfile['name'] ?? ''),
        'githubAvatar' => (string) ($ghProfile['avatar'] ?? ''),
    ]);

    if (!empty($result['ok'])) {
        station_flash_set('ok', 'Your access request is pending. You will be notified when your account is ready.');
    } else {
        station_flash_set('error', (string) ($result['message'] ?? 'Could not submit request.'));
    }

    header('Location: index.php#request-access');
    exit;
}

if ($purpose === 'login') {
    $stationUser = station_find_username_by_github_login($githubLogin);
    if ($stationUser === null) {
        station_flash_set('error', 'No DeployStation account is linked to GitHub user @' . $githubLogin . '. Request access or sign in with username and password.');
        header('Location: index.php');
        exit;
    }

    station_link_github_to_user($stationUser, $githubLogin, $accessToken);
    if (!station_login_as_user($stationUser)) {
        station_flash_set('error', 'Could not sign you in.');
        header('Location: index.php');
        exit;
    }

    if (station_user_needs_onboarding($stationUser)) {
        header('Location: onboarding.php');
        exit;
    }

    station_flash_set('ok', 'Signed in with GitHub.');
    header('Location: station.php');
    exit;
}

// Default: connect GitHub to logged-in user (builder+).
station_require_login();
if (!station_can_build(station_current_user())) {
    station_flash_set('error', 'Builder access required.');
    header('Location: station.php');
    exit;
}

$username = station_current_username();
station_link_github_to_user($username, $githubLogin, $accessToken);
station_log_event('github.oauth.connected', ['username' => $username, 'github_login' => $githubLogin]);
station_flash_set('ok', 'GitHub account connected.');

$after = basename($redirectAfter);
$allowedAfter = ['user-settings.php', 'station.php', 'github-sync.php', 'index.php'];
if (!in_array($after, $allowedAfter, true)) {
    $after = 'user-settings.php';
}
header('Location: ' . $after);
exit;
