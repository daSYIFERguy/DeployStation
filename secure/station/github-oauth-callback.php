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

    station_link_github_to_user($stationUser, $githubLogin);

    $stationRow = station_lookup_user($stationUser);
    $oauthScope = trim((string) ($ex['scope'] ?? ''));
    if ($stationRow !== null && station_can_build($stationRow)) {
        $loginWarnings = [];
        if ($oauthScope !== '' && !preg_match('/\brepo\b/', $oauthScope)) {
            $loginWarnings[] = 'GitHub did not grant repository access (scopes: ' . $oauthScope . '). Sync and pushes stay disabled until you open User Settings → GitHub, disconnect if needed, then authorize again and approve every permission.';
        } else {
            $verify = station_github_verify_repo_access($accessToken, 'oauth');
            if (!empty($verify['ok'])) {
                station_link_github_to_user($stationUser, $githubLogin, $accessToken);
                station_log_event('github.oauth.login_token_saved', ['username' => $stationUser, 'github_login' => $githubLogin, 'scope' => $oauthScope]);
            } else {
                $loginWarnings[] = (string) ($verify['message'] ?? 'Could not verify GitHub repository access.') . ' Open User Settings → GitHub to reconnect.';
            }
        }
        if ($oauthScope !== '' && preg_match('/\brepo\b/', $oauthScope) && !preg_match('/\bworkflow\b/', $oauthScope)) {
            $loginWarnings[] = 'GitHub did not grant the workflow scope (' . $oauthScope . '). Pushes that change files under .github/workflows may fail until you revoke this OAuth app under GitHub → Settings → Applications and sign in again, accepting all requested permissions.';
        }
        if ($loginWarnings !== []) {
            station_flash_set('warning', implode(' ', $loginWarnings));
        }
    }

    if (!station_login_as_user($stationUser)) {
        station_flash_set('error', 'Could not sign you in.');
        header('Location: index.php');
        exit;
    }

    if (station_user_needs_onboarding($stationUser)) {
        header('Location: onboarding.php');
        exit;
    }

    station_flash_set('ok', 'Signed in with GitHub. Builders get the same repository and Actions permissions as when connecting under User Settings—approve every checkbox on GitHub’s screen.');
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
$oauthScope = trim((string) ($ex['scope'] ?? ''));
if ($oauthScope !== '' && !preg_match('/\brepo\b/', $oauthScope)) {
    station_flash_set(
        'error',
        'GitHub connected but did not grant repository access (scopes: ' . $oauthScope . '). '
        . 'Revoke this app under GitHub → Settings → Applications → Authorized OAuth Apps, then use “Sign in with GitHub” on User Settings again and approve repo access.'
    );
    header('Location: user-settings.php');
    exit;
}

station_link_github_to_user($username, $githubLogin, $accessToken);
$verify = station_github_verify_repo_access($accessToken, 'oauth');
if (empty($verify['ok'])) {
    station_flash_set('error', (string) ($verify['message'] ?? 'GitHub connected but API check failed.'));
    header('Location: user-settings.php');
    exit;
}

station_log_event('github.oauth.connected', ['username' => $username, 'github_login' => $githubLogin, 'scope' => $oauthScope]);

station_flash_set(
    'ok',
    'GitHub connected with repository, Actions workflow, and org read access. Approve every permission on GitHub’s screen so pushes and sync match a full repo token.'
);

if ($oauthScope !== '' && !preg_match('/\bworkflow\b/', $oauthScope)) {
    station_flash_set(
        'warning',
        'GitHub did not grant the workflow scope (' . $oauthScope . '). Pushes that add or change files under .github/workflows may fail. Under GitHub → Settings → Applications → Authorized OAuth Apps, revoke this app, then use Sign in with GitHub on User Settings again and accept all requested permissions.'
    );
}

$after = basename($redirectAfter);
$allowedAfter = ['user-settings.php', 'station.php', 'github-sync.php', 'index.php'];
if (!in_array($after, $allowedAfter, true)) {
    $after = 'user-settings.php';
}
header('Location: ' . $after);
exit;
