<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    if (headers_sent() === false) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    session_start();
}

function station_base_dir(): string
{
    return dirname(__DIR__);
}

function station_data_dir(): string
{
    $env = getenv('STATION_DATA_DIR');
    return is_string($env) && trim($env) !== '' ? trim($env) : dirname(station_base_dir()) . '/.secure-station-data';
}

function station_projects_dir(): string
{
    return dirname(station_base_dir());
}

function station_path_join(string ...$parts): string
{
    $clean = [];
    foreach ($parts as $part) {
        $trimmed = trim($part);
        if ($trimmed === '') {
            continue;
        }
        $clean[] = trim($trimmed, '/');
    }
    return implode('/', $clean);
}

function station_config_path(): string
{
    return station_data_dir() . '/config.json';
}

function station_projects_meta_path(): string
{
    return station_data_dir() . '/projects.json';
}

function station_activity_log_path(): string
{
    return station_data_dir() . '/activity.log';
}

function station_user_profiles_dir(): string
{
    return station_data_dir() . '/user-profiles';
}

function station_admin_settings_path(): string
{
    return station_data_dir() . '/admin-settings.json';
}

function station_archives_dir(): string
{
    return station_data_dir() . '/archives';
}

function station_archived_projects_dir(): string
{
    return station_data_dir() . '/archived-projects';
}

function station_project_settings_dir(): string
{
    return station_data_dir() . '/project-settings';
}

function station_project_settings_path(string $slug): string
{
    return station_project_settings_dir() . '/' . station_safe_name($slug) . '.json';
}

function station_ensure_data_dir(): void
{
    $dir = station_data_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }

    $htaccessPath = $dir . '/.htaccess';
    if (!file_exists($htaccessPath)) {
        @file_put_contents($htaccessPath, "Order allow,deny\nDeny from all\n");
    }

    $indexPath = $dir . '/index.html';
    if (!file_exists($indexPath)) {
        @file_put_contents($indexPath, '<!doctype html><title>Forbidden</title>');
    }

    foreach ([station_user_profiles_dir(), station_archives_dir(), station_archived_projects_dir(), station_project_settings_dir()] as $childDir) {
        if (!is_dir($childDir)) {
            @mkdir($childDir, 0700, true);
        }
    }
}

function station_is_setup_complete(): bool
{
    $path = station_config_path();
    if (!file_exists($path)) {
        return false;
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        return false;
    }

    $cfg = json_decode($raw, true);
    return is_array($cfg) && !empty($cfg['users']) && !empty($cfg['appName']);
}

function station_read_json(string $path, array $default = []): array
{
    if (!file_exists($path)) {
        return $default;
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        return $default;
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

function station_write_json(string $path, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }
    return @file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

function station_require_setup(): void
{
    station_ensure_data_dir();
    if (!station_is_setup_complete()) {
        header('Location: setup.php');
        exit;
    }
}

function station_config(): array
{
    return station_read_json(station_config_path(), [
        'appName' => 'Micro Deployment Station',
        'createdAt' => gmdate('c'),
        'users' => []
    ]);
}

function station_save_config(array $cfg): bool
{
    return station_write_json(station_config_path(), $cfg);
}

function station_projects_meta(): array
{
    return station_read_json(station_projects_meta_path(), [
        'projects' => []
    ]);
}

function station_save_projects_meta(array $meta): bool
{
    return station_write_json(station_projects_meta_path(), $meta);
}

function station_admin_settings(): array
{
    return station_read_json(station_admin_settings_path(), [
        'defaultProjectVisibility' => 'private',
        'defaultProjectAccessMode' => 'admin',
        'onboardingRequired' => true,
        'allowPublicProjects' => true,
        'githubEnabled' => true,
        'vscodeEnabled' => true,
        'chatgptEnabled' => true,
        'codexEnabled' => true,
        'stationHeading' => 'Deployment Station',
        'stationSubheading' => '',
        'faviconUrl' => ''
    ]);
}

function station_ui_config(): array
{
    $settings = station_admin_settings();
    return [
        'heading'    => (string) ($settings['stationHeading'] ?? 'Deployment Station'),
        'subheading' => (string) ($settings['stationSubheading'] ?? ''),
        'faviconUrl' => (string) ($settings['faviconUrl'] ?? '')
    ];
}

function station_favicon_html(): string
{
    $url = trim(station_ui_config()['faviconUrl']);
    if ($url === '') {
        return '';
    }
    return '<link rel="icon" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
}

function station_clipboard_path(string $username): string
{
    return station_user_profiles_dir() . '/' . station_safe_name($username) . '.clipboard.txt';
}

function station_save_user_clipboard(string $username, string $content): bool
{
    $content = mb_substr($content, 0, 65536);
    return @file_put_contents(station_clipboard_path($username), $content, LOCK_EX) !== false;
}

function station_get_user_clipboard(string $username): string
{
    $path = station_clipboard_path($username);
    if (!file_exists($path)) {
        return '';
    }
    return (string) (@file_get_contents($path) ?: '');
}

function station_save_admin_settings(array $settings): bool
{
    return station_write_json(station_admin_settings_path(), $settings);
}

function station_project_settings(string $slug): array
{
    $safe = station_safe_name($slug);
    return station_read_json(station_project_settings_path($safe), [
        'slug' => $safe,
        'environment' => [],
        'github' => [
            'repoOwner' => '',
            'repoName' => '',
            'visibility' => 'private',
            'defaultBranch' => 'main',
            'releaseWorkflow' => true
        ],
        'scraperBuilder' => [
            'targetDomain' => '',
            'useCase' => '',
            'presets' => []
        ],
        'notes' => ''
    ]);
}

function station_save_project_settings(string $slug, array $settings): bool
{
    $safe = station_safe_name($slug);
    $settings['slug'] = $safe;
    return station_write_json(station_project_settings_path($safe), $settings);
}

function station_flash_set(string $key, string $message): void
{
    if (!isset($_SESSION['station_flash']) || !is_array($_SESSION['station_flash'])) {
        $_SESSION['station_flash'] = [];
    }
    $_SESSION['station_flash'][$key] = $message;
}

function station_flash_get(string $key): string
{
    if (!isset($_SESSION['station_flash'][$key])) {
        return '';
    }
    $message = (string) $_SESSION['station_flash'][$key];
    unset($_SESSION['station_flash'][$key]);
    return $message;
}

function station_safe_name(string $name): string
{
    $value = strtolower(trim($name));
    $value = preg_replace('/[^a-z0-9\-_]+/', '-', $value) ?? '';
    $value = trim($value, '-_');
    return $value !== '' ? $value : 'project';
}

function station_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function station_current_username(): string
{
    return isset($_SESSION['station_user']) ? (string) $_SESSION['station_user'] : '';
}

function station_user_profile_path(string $username): string
{
    return station_user_profiles_dir() . '/' . station_safe_name($username) . '.json';
}

function station_user_profile(string $username): array
{
    $safe = station_safe_name($username);
    return station_read_json(station_user_profile_path($safe), [
        'username' => $safe,
        'displayName' => $safe,
        'onboardingCompleted' => false,
        'integrations' => [
            'github' => ['enabled' => false, 'username' => '', 'token' => '', 'repo' => ''],
            'vscode' => ['enabled' => false, 'syncUrl' => '', 'notes' => ''],
            'chatgpt' => ['enabled' => false, 'apiKey' => '', 'workspace' => ''],
            'codex' => ['enabled' => false, 'apiKey' => '', 'workspace' => '']
        ]
    ]);
}

function station_save_user_profile(string $username, array $profile): bool
{
    $safe = station_safe_name($username);
    $profile['username'] = $safe;
    return station_write_json(station_user_profile_path($safe), $profile);
}

function station_current_user_profile(): array
{
    $username = station_current_username();
    if ($username === '') {
        return [];
    }
    return station_user_profile($username);
}

function station_integration_ready(array $profile, string $integration): bool
{
    $integrations = isset($profile['integrations']) && is_array($profile['integrations']) ? $profile['integrations'] : [];
    $config = isset($integrations[$integration]) && is_array($integrations[$integration]) ? $integrations[$integration] : [];
    if (!($config['enabled'] ?? false)) {
        return false;
    }

    if ($integration === 'github') {
        return trim((string) ($config['token'] ?? '')) !== '';
    }
    if ($integration === 'vscode') {
        return trim((string) ($config['syncUrl'] ?? '')) !== '';
    }
    return trim((string) ($config['apiKey'] ?? '')) !== '';
}

function station_user_needs_onboarding(string $username): bool
{
    $settings = station_admin_settings();
    if (!($settings['onboardingRequired'] ?? true)) {
        return false;
    }

    $profile = station_user_profile($username);
    return !($profile['onboardingCompleted'] ?? false);
}

function station_log_event(string $event, array $context = []): void
{
    $entry = [
        'at' => gmdate('c'),
        'event' => $event,
        'by' => isset($_SESSION['station_user']) ? (string) $_SESSION['station_user'] : 'guest',
        'context' => $context
    ];

    $json = json_encode($entry, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return;
    }

    @file_put_contents(station_activity_log_path(), $json . "\n", FILE_APPEND | LOCK_EX);
}

function station_recent_events(int $limit = 30): array
{
    $path = station_activity_log_path();
    if (!file_exists($path)) {
        return [];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $slice = array_slice($lines, -1 * max(1, $limit));
    $result = [];
    foreach (array_reverse($slice) as $line) {
        $entry = json_decode((string) $line, true);
        if (is_array($entry)) {
            $result[] = $entry;
        }
    }
    return $result;
}

function station_update_app_name(string $name): bool
{
    $cfg = station_config();
    $trimmed = trim($name);
    if ($trimmed === '') {
        return false;
    }
    $cfg['appName'] = $trimmed;
    $cfg['updatedAt'] = gmdate('c');
    return station_save_config($cfg);
}

function station_rename_station_dir(string $newRawName): array
{
    $newName = station_safe_name($newRawName);
    if ($newName === '') {
        return ['ok' => false, 'message' => 'Invalid station directory name.'];
    }

    if ($newName === 'station') {
        return ['ok' => true, 'message' => 'No directory rename needed.', 'dir' => $newName];
    }

    $currentDir = station_base_dir();
    $parent = dirname($currentDir);
    $target = $parent . '/' . $newName;

    if (is_dir($target)) {
        return ['ok' => false, 'message' => 'Target station directory already exists.'];
    }

    if (!@rename($currentDir, $target)) {
        return ['ok' => false, 'message' => 'Station directory rename failed.'];
    }

    return ['ok' => true, 'message' => 'Station directory renamed.', 'dir' => $newName];
}
