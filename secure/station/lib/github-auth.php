<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/github-oauth.php';
require_once __DIR__ . '/github-sync.php';

/**
 * OAuth purposes: connect (logged-in user token), login, request_access (signup queue).
 */
function station_github_oauth_scopes_for_purpose(string $purpose): string
{
    return match ($purpose) {
        'connect' => 'repo read:user',
        'login', 'request_access' => 'read:user',
        default => 'read:user',
    };
}

function station_github_oauth_purpose_label(string $purpose): string
{
    return match ($purpose) {
        'connect' => 'Connect GitHub to your account',
        'login' => 'Sign in with GitHub',
        'request_access' => 'Verify with GitHub to request access',
        default => 'GitHub authorization',
    };
}

/**
 * @return array{ok: bool, message: string, redirect?: string}
 */
function station_github_oauth_begin(string $purpose, string $redirectAfter = 'index.php'): array
{
    $purpose = match ($purpose) {
        'connect', 'login', 'request_access' => $purpose,
        default => '',
    };
    if ($purpose === '') {
        return ['ok' => false, 'message' => 'Invalid OAuth flow.'];
    }

    if ($purpose === 'connect') {
        if (!station_current_user() || !station_can_build(station_current_user())) {
            return ['ok' => false, 'message' => 'Sign in with builder access to connect GitHub.'];
        }
    }

    $admin = station_admin_settings();
    if (empty($admin['githubEnabled'])) {
        return ['ok' => false, 'message' => 'GitHub is not enabled on this station.'];
    }

    $clientId = trim((string) ($admin['githubOAuthClientId'] ?? ''));
    $clientSecret = trim((string) ($admin['githubOAuthClientSecret'] ?? ''));
    if ($clientId === '') {
        return ['ok' => false, 'message' => 'GitHub OAuth is not configured yet (missing Client ID).'];
    }
    if ($purpose !== 'login' && $purpose !== 'request_access' && $clientSecret === '') {
        return ['ok' => false, 'message' => 'GitHub OAuth client secret is required.'];
    }

    $redirectUri = station_github_oauth_redirect_uri();
    if ($redirectUri === '') {
        return ['ok' => false, 'message' => 'Could not build OAuth callback URL.'];
    }

    $state = bin2hex(random_bytes(16));
    $_SESSION['github_oauth_state'] = $state;
    $_SESSION['github_oauth_purpose'] = $purpose;
    $_SESSION['github_oauth_redirect_after'] = $redirectAfter;

    $scopes = station_github_oauth_scopes_for_purpose($purpose);
    $url = station_github_oauth_authorize_url($clientId, $state, $scopes);
    if ($url === '') {
        return ['ok' => false, 'message' => 'Could not start GitHub authorization.'];
    }

    return ['ok' => true, 'message' => 'OK', 'redirect' => $url];
}

/**
 * @return array{ok: bool, login?: string, id?: int|string, name?: string, email?: string, avatar?: string, message: string}
 */
function station_github_fetch_user_profile(string $accessToken): array
{
    $userApi = station_github_api('GET', '/user', $accessToken);
    if (empty($userApi['ok'])) {
        return ['ok' => false, 'message' => 'Could not read your GitHub profile.'];
    }

    $u = json_decode((string) ($userApi['body'] ?? ''), true);
    if (!is_array($u)) {
        return ['ok' => false, 'message' => 'Invalid GitHub profile response.'];
    }

    $login = trim((string) ($u['login'] ?? ''));
    if ($login === '') {
        return ['ok' => false, 'message' => 'GitHub profile missing login.'];
    }

    $email = '';
    if (!empty($u['email']) && is_string($u['email'])) {
        $email = trim($u['email']);
    }

    return [
        'ok' => true,
        'message' => 'OK',
        'login' => $login,
        'id' => $u['id'] ?? '',
        'name' => trim((string) ($u['name'] ?? '')),
        'email' => $email,
        'avatar' => trim((string) ($u['avatar_url'] ?? '')),
    ];
}

/**
 * Find a station username linked to this GitHub login (profile integration or config field).
 */
function station_find_username_by_github_login(string $githubLogin): ?string
{
    $githubLogin = strtolower(trim($githubLogin));
    if ($githubLogin === '') {
        return null;
    }

    $cfg = station_config();
    $users = isset($cfg['users']) && is_array($cfg['users']) ? $cfg['users'] : [];
    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }
        $uname = (string) ($user['username'] ?? '');
        if ($uname === '' || (isset($user['active']) && !(bool) $user['active'])) {
            continue;
        }
        $linked = strtolower(trim((string) ($user['githubLogin'] ?? '')));
        if ($linked !== '' && $linked === $githubLogin) {
            return $uname;
        }
    }

    $dir = station_user_profiles_dir();
    if (!is_dir($dir)) {
        return null;
    }

    foreach (glob($dir . '/*.json') ?: [] as $path) {
        $base = basename($path, '.json');
        if ($base === '') {
            continue;
        }
        $profile = station_user_profile($base);
        $gh = $profile['integrations']['github'] ?? [];
        if (!is_array($gh)) {
            continue;
        }
        $ghUser = strtolower(trim((string) ($gh['username'] ?? '')));
        if ($ghUser !== '' && $ghUser === $githubLogin) {
            return $base;
        }
    }

    return null;
}

function station_link_github_to_user(string $username, string $githubLogin, string $accessToken = ''): void
{
    $githubLogin = trim($githubLogin);
    if ($githubLogin === '') {
        return;
    }

    $cfg = station_config();
    $users = isset($cfg['users']) && is_array($cfg['users']) ? $cfg['users'] : [];
    foreach ($users as $i => $user) {
        if (!is_array($user) || (string) ($user['username'] ?? '') !== $username) {
            continue;
        }
        $users[$i]['githubLogin'] = $githubLogin;
        $cfg['users'] = $users;
        station_save_config($cfg);
        break;
    }

    if ($accessToken !== '') {
        $profile = station_user_profile($username);
        if (!isset($profile['integrations']) || !is_array($profile['integrations'])) {
            $profile['integrations'] = [];
        }
        $prev = isset($profile['integrations']['github']) && is_array($profile['integrations']['github'])
            ? $profile['integrations']['github']
            : [];
        $profile['integrations']['github'] = array_merge($prev, [
            'enabled' => true,
            'username' => $githubLogin,
            'token' => $accessToken,
            'authMode' => 'oauth',
            'oauthConnectedAt' => gmdate('c'),
        ]);
        station_save_user_profile($username, $profile);
    }
}
