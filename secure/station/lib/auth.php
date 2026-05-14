<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function station_request_expects_json(): bool
{
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
}

function station_request_json_error(int $statusCode, string $message, string $redirectUrl = ''): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'message' => $message,
        'redirectUrl' => $redirectUrl
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

function station_role_rank(?string $role): int
{
    $value = (string) $role;
    if ($value === 'owner') {
        return 4;
    }
    if ($value === 'admin') {
        return 3;
    }
    if ($value === 'builder') {
        return 2;
    }
    if ($value === 'viewer') {
        return 1;
    }
    return 0;
}

function station_user_role(?array $user): string
{
    return $user && isset($user['role']) ? (string) $user['role'] : 'guest';
}

function station_current_user(): ?array
{
    $username = isset($_SESSION['station_user']) ? (string) $_SESSION['station_user'] : '';
    if ($username === '') {
        return null;
    }

    $cfg = station_config();
    $users = isset($cfg['users']) && is_array($cfg['users']) ? $cfg['users'] : [];
    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }
        if ((string) ($user['username'] ?? '') === $username) {
            return $user;
        }
    }

    return null;
}

function station_is_owner(?array $user): bool
{
    if (!$user) {
        return false;
    }
    return (string) ($user['role'] ?? '') === 'owner';
}

function station_is_admin(?array $user): bool
{
    return station_role_rank(station_user_role($user)) >= 3;
}

function station_can_build(?array $user): bool
{
    return station_role_rank(station_user_role($user)) >= 2;
}

function station_can_view(?array $user): bool
{
    return station_role_rank(station_user_role($user)) >= 1;
}

function station_can_access_project(?array $user, string $accessMode): bool
{
    $rank = station_role_rank(station_user_role($user));
    $mode = trim(strtolower($accessMode));

    if ($mode === 'public') {
        return true;
    }
    if ($mode === 'viewersorbuilder') {
        return $rank >= 1;
    }
    if ($mode === 'buildersoradmin') {
        return $rank >= 2;
    }
    if ($mode === 'admin') {
        return $rank >= 3;
    }
    if ($mode === 'adminsonly') {
        return $rank >= 4;
    }
    return $rank >= 3;
}

function station_allowed_project_access_modes(): array
{
    return [
        'public' => 'Public',
        'viewersorbuilder' => 'Viewers or Builder+',
        'buildersoradmin' => 'Builder or Admin+',
        'admin' => 'Admin+',
        'adminsonly' => 'Owner only'
    ];
}

function station_login(string $username, string $password): bool
{
    $cfg = station_config();
    $users = isset($cfg['users']) && is_array($cfg['users']) ? $cfg['users'] : [];

    foreach ($users as $idx => $user) {
        if (!is_array($user)) {
            continue;
        }
        $candidate = (string) ($user['username'] ?? '');
        $hash = (string) ($user['passwordHash'] ?? '');
        $active = !isset($user['active']) || (bool) $user['active'];

        if (!$active || $candidate !== $username) {
            continue;
        }

        if ($hash !== '' && password_verify($password, $hash)) {
            $_SESSION['station_user'] = $candidate;
            $users[$idx]['lastLoginAt'] = gmdate('c');
            $cfg['users'] = $users;
            station_save_config($cfg);
            station_log_event('user.login', ['username' => $candidate]);
            return true;
        }
    }

    return false;
}

function station_logout(): void
{
    unset($_SESSION['station_user']);
}

function station_require_login(): void
{
    station_require_setup();
    if (!station_current_user()) {
        if (station_request_expects_json()) {
            station_request_json_error(401, 'Your session expired. Sign in again to continue.', 'index.php');
        }
        header('Location: index.php');
        exit;
    }
}

function station_require_owner(): void
{
    station_require_login();
    $user = station_current_user();
    if (!station_is_owner($user)) {
        station_flash_set('error', 'Owner access required.');
        header('Location: station.php');
        exit;
    }
}

function station_require_builder(): void
{
    station_require_login();
    $user = station_current_user();
    if (!station_can_build($user)) {
        if (station_request_expects_json()) {
            station_request_json_error(403, 'Builder access required.', 'station.php');
        }
        station_flash_set('error', 'Builder access required.');
        header('Location: station.php');
        exit;
    }
}

require_once __DIR__ . '/projects.php';

/**
 * Whether the current user may open this project (files, launch, docker APIs).
 * Unauthenticated clients are denied whenever visibility is not public, even if accessMode
 * in projects.json was mistakenly left public (visibility and accessMode can drift).
 * The nginx docker proxy at <code>/p/&lt;slug&gt;/</code> always requires a Station session
 * in nginx-docker-auth.php before this function runs.
 */
function station_user_may_access_project(?array $user, string $slug): bool
{
    $slug = station_safe_name($slug);
    if ($slug === '') {
        return false;
    }
    if ($user === null && station_project_visibility($slug) !== 'public') {
        return false;
    }

    return station_can_access_project($user, station_project_access_mode($slug));
}
