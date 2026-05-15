<?php

declare(strict_types=1);

/**
 * True when the client connection is HTTPS (direct or via reverse proxy / Cloudflare).
 * Used for session cookie Secure flag so PHP-FPM behind TLS termination still gets Secure cookies.
 */
function station_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && (string) $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    $xfProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($xfProto !== '') {
        $first = trim(explode(',', $xfProto)[0]);
        if ($first === 'https') {
            return true;
        }
    }
    $fe = strtolower((string) ($_SERVER['HTTP_FRONT_END_HTTPS'] ?? ''));

    return $fe === 'on';
}

/**
 * Public origin for the current request (scheme + host), or empty if unknown.
 * Used for OAuth redirect URIs and absolute links.
 */
function station_request_origin_url(): string
{
    $https = station_request_is_https();
    $scheme = $https ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return '';
    }

    return $scheme . '://' . $host;
}

/**
 * Directory URL of the current PHP script (no trailing slash), e.g. https://host/secure/station
 */
function station_request_script_dir_url(): string
{
    $origin = station_request_origin_url();
    if ($origin === '') {
        return '';
    }
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

    return $origin . $dir;
}

/**
 * Session cookie options. Env overrides:
 * - STATION_SESSION_SAMESITE=Lax|Strict|None (None forces Secure=true)
 * - STATION_SESSION_COOKIE_DOMAIN= e.g. .example.com when needed for subdomains
 */
function station_session_cookie_params_from_env(): array
{
    $isHttps = station_request_is_https();
    $raw = getenv('STATION_SESSION_SAMESITE');
    $v = is_string($raw) ? strtolower(trim($raw)) : '';
    $sameSite = 'Lax';
    if ($v === 'none') {
        $sameSite = 'None';
    } elseif ($v === 'strict') {
        $sameSite = 'Strict';
    }
    $secure = $isHttps || $sameSite === 'None';

    $params = [
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => $sameSite,
    ];
    $dom = getenv('STATION_SESSION_COOKIE_DOMAIN');
    if (is_string($dom)) {
        $dom = trim($dom);
        if ($dom !== '') {
            $params['domain'] = $dom;
        }
    }

    return $params;
}

/**
 * Session bootstrap: scripts may define STATION_AUTH_REQUEST_SESSION_READ_AND_CLOSE before
 * loading bootstrap to start the session with read_and_close (legacy / custom use).
 */
$stationSessionReadAndClose = defined('STATION_AUTH_REQUEST_SESSION_READ_AND_CLOSE') && STATION_AUTH_REQUEST_SESSION_READ_AND_CLOSE;

if (session_status() === PHP_SESSION_ACTIVE) {
    if ($stationSessionReadAndClose) {
        // Session was already started (e.g. auto_prepend). Do NOT call session_start() again:
        // that can break the session module and yield 500. write_close() releases the lock;
        // $_SESSION stays readable for the rest of this request (PHP manual).
        session_write_close();
    }
} elseif (session_status() === PHP_SESSION_NONE) {
    if (headers_sent() === false) {
        session_set_cookie_params(station_session_cookie_params_from_env());
    }
    if ($stationSessionReadAndClose) {
        session_start(['read_and_close' => true]);
    } else {
        session_start();
    }
}

function station_base_dir(): string
{
    return dirname(__DIR__);
}

/**
 * Whether $path can hold Station state: existing writable dir, or creatable then writable.
 */
function station_data_dir_path_is_usable(string $path): bool
{
    $path = trim($path);
    if ($path === '') {
        return false;
    }
    if (is_dir($path)) {
        return is_writable($path);
    }

    return @mkdir($path, 0775, true) && is_writable($path);
}

/**
 * Persistent state directory (config, admin-settings, archives, nginx includes, …).
 *
 * - If env `STATION_DATA_DIR` is set and that path is usable (writable, or can be
 *   created by this process), it is used. If it is set but missing and not creatable,
 *   or not writable, we log and fall through (same as an unset env) so setup is not
 *   stuck when the guide path was never chown'd.
 * - Otherwise on Linux, `/var/lib/deployment-station-data` then
 *   `/var/lib/deployment-station` are tried when already writable or creatable here.
 * - Else: `<parent-of-station-php>/.secure-station-data` (same parent as the folder
 *   that contains `station/` — works when you only deploy `/secure/` and the web user
 *   owns the parent path).
 */
function station_data_dir(): string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $env = getenv('STATION_DATA_DIR');
    if (is_string($env) && trim($env) !== '') {
        $cand = trim($env);
        if (station_data_dir_path_is_usable($cand)) {
            $resolved = $cand;

            return $resolved;
        }
        error_log(
            'Deployment Station: STATION_DATA_DIR is set to ' . $cand
            . ' but that path is not writable (and could not be created). Using automatic fallback.'
        );
    }

    if (PHP_OS_FAMILY === 'Linux') {
        foreach (['/var/lib/deployment-station-data', '/var/lib/deployment-station'] as $lib) {
            if (station_data_dir_path_is_usable($lib)) {
                $resolved = $lib;

                return $resolved;
            }
        }
    }

    $resolved = dirname(station_base_dir()) . '/.secure-station-data';

    return $resolved;
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

function station_web_base_path(): string
{
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/secure/station/index.php');
    $base = str_replace('\\', '/', dirname($scriptName));
    if ($base === '.' || $base === '\\' || $base === '/') {
        return '';
    }
    return rtrim($base, '/');
}

function station_station_url(string $relativePath = ''): string
{
    $base = station_web_base_path();
    $relative = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($relative === '') {
        return ($base !== '' ? $base : '') . '/';
    }
    return ($base !== '' ? $base : '') . '/' . $relative;
}

function station_secure_base_path(): string
{
    $stationBase = station_web_base_path();
    $secureBase = str_replace('\\', '/', dirname($stationBase !== '' ? $stationBase : '/station'));
    if ($secureBase === '.' || $secureBase === '/' || $secureBase === '\\') {
        return '';
    }
    return rtrim($secureBase, '/');
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

function station_icons_dir(): string
{
    return station_base_dir() . '/icos';
}

function station_icons_web_path(): string
{
    $base = station_web_base_path();
    return ($base !== '' ? $base : '') . '/icos';
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
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            error_log('Deployment Station: could not create data directory ' . $dir . ' — set STATION_DATA_DIR to a writable path or fix parent permissions.');
        }
    } elseif (!is_writable($dir)) {
        error_log('Deployment Station: data directory is not writable: ' . $dir);
    }

    if (is_dir($dir) && is_writable($dir)) {
        @chmod($dir, 0775);
    }

    $htaccessPath = $dir . '/.htaccess';
    if (!file_exists($htaccessPath)) {
        @file_put_contents($htaccessPath, "Order allow,deny\nDeny from all\n");
    }

    $indexPath = $dir . '/index.html';
    if (!file_exists($indexPath)) {
        @file_put_contents($indexPath, '<!doctype html><title>Forbidden</title>');
    }

    $iconsDir = station_icons_dir();
    if (!is_dir($iconsDir)) {
        @mkdir($iconsDir, 0755, true);
    }

    $iconsIndexPath = $iconsDir . '/index.html';
    if (!file_exists($iconsIndexPath)) {
        @file_put_contents($iconsIndexPath, '<!doctype html><title>Icons</title>');
    }

    foreach ([station_user_profiles_dir(), station_archives_dir(), station_archived_projects_dir(), station_project_settings_dir(), station_data_dir() . '/project-credentials'] as $childDir) {
        if (!is_dir($childDir)) {
            $norm = str_replace('\\', '/', $childDir);
            $mode = str_ends_with($norm, '/project-credentials') ? 0700 : 0750;
            @mkdir($childDir, $mode, true);
        }
    }

    // Ensure the nginx include directory + stub file exist on first run.
    // This way admins can wire `include <data-dir>/nginx/projects.conf;`
    // into their server block before any docker project exists without
    // making `nginx -t` blow up.
    $nginxDir = $dir . '/nginx';
    if (!is_dir($nginxDir)) {
        @mkdir($nginxDir, 0755, true);
    } elseif ((@fileperms($nginxDir) & 0777) !== 0755) {
        @chmod($nginxDir, 0755);
    }

    $nginxIncludePath = $nginxDir . '/projects.conf';
    if (!file_exists($nginxIncludePath)) {
        // If docker.php is loaded (the usual case), let the canonical
        // renderer write the stub so wording stays in sync. Otherwise
        // fall back to a literal stub so `nginx -t` never fails on a
        // fresh install where docker.php hasn't been required yet.
        if (function_exists('station_render_nginx_projects_conf')) {
            $stub = station_render_nginx_projects_conf([]);
        } else {
            $stub = "# Auto-generated by Deployment Station — managed file.\n"
                . "# Regenerated whenever a docker project is configured, started,\n"
                . "# stopped, rebuilt, or torn down. Manual edits will be lost.\n"
                . "# Generated at " . gmdate('c') . "\n\n"
                . "# No dockerized projects yet — this file is intentionally empty.\n"
                . "# Once a project is configured for Docker the route blocks will appear below.\n";
        }
        if (@file_put_contents($nginxIncludePath, $stub, LOCK_EX) !== false) {
            @chmod($nginxIncludePath, 0644);
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
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($data, $flags);
    if (!is_string($json)) {
        return false;
    }
    $payload = $json . "\n";
    $dir = dirname($path);
    if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
        return @file_put_contents($path, $payload, LOCK_EX) !== false;
    }
    if (@rename($tmp, $path)) {
        @chmod($path, 0644);
        return true;
    }
    @unlink($tmp);

    return @file_put_contents($path, $payload, LOCK_EX) !== false;
}

function station_is_safe_relative_path(string $path): bool
{
    $clean = trim(str_replace('\\', '/', $path));
    if ($clean === '' || str_starts_with($clean, '/') || str_contains($clean, '..')) {
        return false;
    }
    return !preg_match('/[[:cntrl:]]/', $clean);
}

function station_require_setup(): void
{
    station_ensure_data_dir();
    if (!station_is_setup_complete()) {
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if ($requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json')) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'message' => 'Setup is not complete yet.',
                'redirectUrl' => 'setup.php'
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }
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

function station_terminal_embed_url(): string
{
    $url = trim((string) (station_admin_settings()['terminalEmbedUrl'] ?? ''));
    if ($url === '') {
        return 'https://syifer.dev/terminal/';
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }

    return str_ends_with($url, '/') ? $url : $url . '/';
}

function station_admin_settings(): array
{
    $defaults = array_merge([
        'serverInfrastructure' => 'apache',
        'defaultProjectVisibility' => 'private',
        'defaultProjectAccessMode' => 'admin',
        'auditLogLimit' => 50,
        'onboardingRequired' => true,
        'allowPublicProjects' => true,
        'githubEnabled' => true,
        'stationHeading' => 'Deployment Station',
        'stationSubheading' => '',
        'faviconUrl' => '',
        'appIconUrl' => '',
        'appIcon192Url' => '',
        'appIcon512Url' => '',
        'appMaskableIconUrl' => '',
        'themeColor' => '#2f7de2',
        'customTemplates' => [],
        'dockerSettings' => [],
        'nginxDockerUpstreamHostMode' => 'preserve',
        'githubOAuthClientId' => '',
        'githubOAuthClientSecret' => '',
        'openaiApiKey' => '',
        'openaiModel' => 'gpt-4o-mini',
        'terminalEmbedUrl' => 'https://syifer.dev/terminal/',
    ], station_admin_default_shell_commands());
    $stored = station_read_json(station_admin_settings_path(), []);

    return array_merge($defaults, is_array($stored) ? $stored : []);
}

/**
 * Default one-liners for nginx auto-reload and Host health quick actions (Debian/Ubuntu paths).
 * Adjust `hostRestartPhpFpmCommand` for your PHP-FPM unit name (e.g. php8.4-fpm).
 *
 * @return array<string, string>
 */
function station_admin_default_shell_commands(): array
{
    return [
        'nginxReloadCommand' => 'sudo -n /usr/sbin/nginx -t && sudo -n /usr/sbin/nginx -s reload',
        'hostRestartNginxCommand' => 'sudo -n /usr/sbin/nginx -t && sudo -n /usr/sbin/nginx -s reload 2>&1',
        'hostRestartPhpFpmCommand' => 'sudo -n /usr/bin/systemctl reload php8.3-fpm 2>&1',
        'hostRestartDockerCommand' => 'sudo -n /usr/bin/systemctl restart docker 2>&1',
        'hostDiagExtraCommand' => 'sudo -n /usr/bin/tail -n 80 /var/log/nginx/error.log 2>&1',
        'hostNginxTestCommand' => 'sudo -n /usr/sbin/nginx -t 2>&1',
    ];
}

/**
 * Effective command for UI and saves: non-empty stored value, else default for this key.
 */
function station_admin_resolved_shell_command(array $settings, string $key): string
{
    $v = trim((string) ($settings[$key] ?? ''));
    if ($v !== '') {
        return $v;
    }
    $defaults = station_admin_default_shell_commands();

    return (string) ($defaults[$key] ?? '');
}

/**
 * Merge the admin-settings mega-form POST/FILES into $settings (all tabs
 * share one form, so every save must apply every posted field).
 */
function station_admin_merge_mega_form_post_into_settings(
    array $settings,
    array $post,
    array $accessModes,
    array $dockerServices
): array {
    if (isset($post['stationHeading'])) {
        $settings['stationHeading'] = trim((string) $post['stationHeading']) ?: 'Deployment Station';
    }
    if (isset($post['stationSubheading'])) {
        $settings['stationSubheading'] = trim((string) $post['stationSubheading']);
    }
    if (isset($post['themeColor'])) {
        $settings['themeColor'] = station_normalize_theme_color((string) ($post['themeColor'] ?? '#2f7de2'));
    }

    if (isset($post['serverInfrastructure'])) {
        $settings['serverInfrastructure'] = station_normalize_server_infrastructure((string) $post['serverInfrastructure']);
    }
    if (isset($post['defaultProjectVisibility'])) {
        $settings['defaultProjectVisibility'] = ((string) $post['defaultProjectVisibility'] === 'public') ? 'public' : 'private';
    }
    if (isset($post['defaultProjectAccessMode'])) {
        $defaultMode = (string) $post['defaultProjectAccessMode'];
        $settings['defaultProjectAccessMode'] = isset($accessModes[$defaultMode]) ? $defaultMode : 'admin';
    }
    if (isset($post['auditLogLimit'])) {
        $settings['auditLogLimit'] = station_normalize_audit_log_limit($post['auditLogLimit']);
    }
    $settings['allowPublicProjects'] = isset($post['allowPublicProjects']);

    $infra = station_normalize_server_infrastructure((string) ($settings['serverInfrastructure'] ?? 'apache'));
    if ($infra === 'nginx') {
        $settings['nginxReloadCommand'] = trim((string) ($post['nginxReloadCommand'] ?? ''));
        if (isset($post['nginxDockerUpstreamHostMode'])) {
            $hostMode = strtolower(trim((string) $post['nginxDockerUpstreamHostMode']));
            $settings['nginxDockerUpstreamHostMode'] = $hostMode === 'loopback' ? 'loopback' : 'preserve';
        }
    }

    $settings['githubEnabled'] = isset($post['githubEnabled']);

    if (isset($post['githubOAuthClientId'])) {
        $settings['githubOAuthClientId'] = trim((string) $post['githubOAuthClientId']);
    }
    if (isset($post['githubOAuthClientSecret'])) {
        $sec = trim((string) $post['githubOAuthClientSecret']);
        if ($sec !== '') {
            $settings['githubOAuthClientSecret'] = $sec;
        }
    }

    if (isset($post['openaiApiKey'])) {
        $okey = trim((string) $post['openaiApiKey']);
        if ($okey !== '') {
            $settings['openaiApiKey'] = $okey;
        }
    }
    if (isset($post['openaiModel'])) {
        $settings['openaiModel'] = trim((string) $post['openaiModel']) ?: 'gpt-4o-mini';
    }
    if (isset($post['terminalEmbedUrl'])) {
        $settings['terminalEmbedUrl'] = trim((string) $post['terminalEmbedUrl']);
    }

    $settings['onboardingRequired'] = isset($post['onboardingRequired']);

    $dockerStored = isset($settings['dockerSettings']) && is_array($settings['dockerSettings'])
        ? $settings['dockerSettings']
        : [];
    $dockerSettings = $dockerStored;
    $dockerSettings['enabled'] = isset($post['dockerEnabled']);
    $dockerSettings['composeVersion'] = (string) ($post['composeVersion'] ?? ($dockerSettings['composeVersion'] ?? '3.9'));
    if (!in_array($dockerSettings['composeVersion'], ['3.8', '3.9'], true)) {
        $dockerSettings['composeVersion'] = '3.9';
    }
    $dockerSettings['defaultDatabase'] = (string) ($post['defaultDatabase'] ?? ($dockerSettings['defaultDatabase'] ?? 'mysql'));
    $allowedDb = ['mysql', 'postgres', 'mongodb', 'none'];
    if (!in_array($dockerSettings['defaultDatabase'], $allowedDb, true)) {
        $dockerSettings['defaultDatabase'] = 'mysql';
    }
    $dockerSettings['dockerBinaryPath'] = trim((string) ($post['dockerBinaryPath'] ?? ($dockerSettings['dockerBinaryPath'] ?? '')));
    $dockerSettings['composeBinaryPath'] = trim((string) ($post['composeBinaryPath'] ?? ($dockerSettings['composeBinaryPath'] ?? '')));

    if (!isset($dockerSettings['services']) || !is_array($dockerSettings['services'])) {
        $dockerSettings['services'] = [];
    }
    foreach ($dockerServices as $serviceKey => $serviceConfig) {
        $dockerSettings['services'][$serviceKey] = isset($post['service_' . $serviceKey]);
    }

    $settings['dockerSettings'] = $dockerSettings;

    unset($settings['vscodeEnabled'], $settings['chatgptEnabled'], $settings['codexEnabled']);

    return $settings;
}

function station_normalize_server_infrastructure(string $value): string
{
    $normalized = strtolower(trim($value));
    return $normalized === 'nginx' ? 'nginx' : 'apache';
}

function station_nginx_project_route_snippet(): string
{
    $stationPrefix = station_web_base_path();
    if ($stationPrefix === '') {
        $stationPrefix = '/station';
    }

    $securePrefix = station_secure_base_path();
    $securePattern = preg_quote($securePrefix !== '' ? $securePrefix : '', '/');

    $dockerIncludePath = function_exists('station_nginx_include_path')
        ? station_nginx_include_path()
        : station_data_dir() . '/nginx/projects.conf';

    $lines = [
        '# Add these locations inside your server {} block.',
        '# IMPORTANT: in your full server{}, this whole paste must appear BEFORE a',
        '# catch-all `location / { ... }` (or other prefix that would steal `/p/…`).',
        '# The `include …/projects.conf` line is last *in this snippet* only because',
        '# the `/p/<slug>/` blocks live in that external file — not because order',
        '# inside server{} is “include last everywhere”.',
        '# Do not use ^~ on the station PHP locations here, or Nginx will serve',
        '# station PHP files as downloads instead of passing them to PHP-FPM.',
        '',
        'location ' . $stationPrefix . ' {',
        '    try_files $uri $uri/ ' . $stationPrefix . '/index.php?$query_string;',
        '}',
        '',
        'location ~ ^' . $securePattern . '/(?<station_project>(?!station(?:/|$)|index\.php$)[^/]+)(?:/(?<station_path>.*))?$ {',
        '    rewrite ^ ' . $stationPrefix . '/project-serve.php?project=$station_project&path=$station_path last;',
        '}',
        '',
        '# Keep your normal PHP handler enabled so project-serve.php and station PHP files execute.',
        '',
        '# Auto-managed: per-project Docker reverse-proxy routes.',
        '# If https://YOURDOMAIN/p/<slug>/ shows the main site instead of the',
        '# container, this include is missing from the active server{} block,',
        '# points at the wrong path, or nginx was not reloaded. A successful',
        '# proxy adds response header X-Station-Docker-Project: <slug>.',
        '# projects.conf always clears Cookie and Authorization before proxy_pass.',
        '# Optional in this server{} (not inside projects.conf): if clients still',
        '# hit 400/494 on /p/… with huge headers, set e.g.',
        '#   client_header_buffer_size 16k;',
        '#   large_client_header_buffers 8 64k;',
        '# Regenerated by Deployment Station whenever a docker project is',
        '# configured, started, stopped, rebuilt, or torn down. When Admin →',
        '# Projects has automatic nginx reload enabled, `nginx -t` and reload',
        '# run after each regeneration so new routes apply immediately.',
        'include ' . $dockerIncludePath . ';'
    ];

    return implode("\n", $lines);
}

function station_normalize_audit_log_limit(mixed $value): int
{
    $limit = (int) $value;
    if ($limit < 50) {
        $limit = 50;
    }
    if ($limit > 200000) {
        $limit = 200000;
    }
    return $limit;
}

function station_audit_log_limit(?array $settings = null): int
{
    $source = is_array($settings) ? $settings : station_admin_settings();
    return station_normalize_audit_log_limit($source['auditLogLimit'] ?? 50);
}

function station_ui_config(): array
{
    $settings = station_admin_settings();
    $config = station_config();
    return [
        'appName' => (string) ($config['appName'] ?? 'Deployment Station'),
        'heading'    => (string) ($settings['stationHeading'] ?? 'Deployment Station'),
        'subheading' => (string) ($settings['stationSubheading'] ?? ''),
        'faviconUrl' => (string) ($settings['faviconUrl'] ?? ''),
        'appIconUrl' => (string) ($settings['appIconUrl'] ?? ''),
        'appIcon192Url' => (string) ($settings['appIcon192Url'] ?? ''),
        'appIcon512Url' => (string) ($settings['appIcon512Url'] ?? ''),
        'appMaskableIconUrl' => (string) ($settings['appMaskableIconUrl'] ?? ''),
        'themeColor' => station_effective_theme_color()
    ];
}

function station_effective_theme_color(?string $username = null): string
{
    $settings = station_admin_settings();
    $default = station_normalize_theme_color((string) ($settings['themeColor'] ?? '#2f7de2'));
    $userName = $username;
    if ($userName === null) {
        $userName = station_current_username();
    }
    if ($userName === '') {
        return $default;
    }

    $profile = station_user_profile($userName);
    $userColor = trim((string) ($profile['themeColor'] ?? ''));
    if ($userColor === '') {
        return $default;
    }

    return station_normalize_theme_color($userColor);
}

function station_normalize_theme_color(string $value): string
{
    $color = trim($value);
    if (preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $color) === 1) {
        return strlen($color) === 4
            ? '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3]
            : strtolower($color);
    }

    return '#2f7de2';
}

function station_pwa_icons(): array
{
    $uiConfig = station_ui_config();
    $fallback = station_icon_url_with_cache_buster((string) ($uiConfig['appIconUrl'] ?? ''));
    $favicon = station_icon_url_with_cache_buster((string) ($uiConfig['faviconUrl'] ?? ''));
    $icon192 = station_icon_url_with_cache_buster((string) ($uiConfig['appIcon192Url'] ?? ''));
    $icon512 = station_icon_url_with_cache_buster((string) ($uiConfig['appIcon512Url'] ?? ''));
    $maskable = station_icon_url_with_cache_buster((string) ($uiConfig['appMaskableIconUrl'] ?? ''));

    return [
        'favicon' => $favicon !== '' ? $favicon : ($fallback !== '' ? $fallback : $icon192),
        'apple' => $icon192 !== '' ? $icon192 : ($fallback !== '' ? $fallback : $favicon),
        '192' => $icon192 !== '' ? $icon192 : ($fallback !== '' ? $fallback : $favicon),
        '512' => $icon512 !== '' ? $icon512 : ($fallback !== '' ? $fallback : ($icon192 !== '' ? $icon192 : $favicon)),
        'maskable' => $maskable !== '' ? $maskable : ($icon512 !== '' ? $icon512 : ($fallback !== '' ? $fallback : $favicon))
    ];
}

function station_favicon_html(): string
{
    $icons = station_pwa_icons();
    $favicon = trim((string) ($icons['favicon'] ?? ''));
    $apple = trim((string) ($icons['apple'] ?? ''));

    if ($favicon === '' && $apple === '') {
        return '';
    }

    $html = [];
    if ($favicon !== '') {
        $safeFavicon = htmlspecialchars($favicon, ENT_QUOTES, 'UTF-8');
        $html[] = '<link rel="icon" href="' . $safeFavicon . '">';
        $html[] = '<link rel="shortcut icon" href="' . $safeFavicon . '">';
    }
    if ($apple !== '') {
        $html[] = '<link rel="apple-touch-icon" href="' . htmlspecialchars($apple, ENT_QUOTES, 'UTF-8') . '">';
    }

    return implode("\n  ", $html);
}

function station_hex_to_rgb(string $color): array
{
    $hex = ltrim(station_normalize_theme_color($color), '#');
    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2))
    ];
}

function station_rgb_to_hex(int $red, int $green, int $blue): string
{
    $r = max(0, min(255, $red));
    $g = max(0, min(255, $green));
    $b = max(0, min(255, $blue));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

function station_mix_hex(string $base, string $mixWith, float $weight): string
{
    [$r1, $g1, $b1] = station_hex_to_rgb($base);
    [$r2, $g2, $b2] = station_hex_to_rgb($mixWith);
    $ratio = max(0.0, min(1.0, $weight));

    return station_rgb_to_hex(
        (int) round(($r1 * (1 - $ratio)) + ($r2 * $ratio)),
        (int) round(($g1 * (1 - $ratio)) + ($g2 * $ratio)),
        (int) round(($b1 * (1 - $ratio)) + ($b2 * $ratio))
    );
}

function station_theme_palette(string $color): array
{
    $brand = station_normalize_theme_color($color);
    $brandDark = station_mix_hex($brand, '#10243f', 0.24);
    $brandBright = station_mix_hex($brand, '#ffffff', 0.26);
    $brandSoft = station_mix_hex($brand, '#ffffff', 0.88);
    $navBg = station_mix_hex($brand, '#12325d', 0.28);
    $navDark = station_mix_hex($brand, '#081426', 0.42);
    [$red, $green, $blue] = station_hex_to_rgb($brand);

    return [
        'brand' => $brand,
        'brandDark' => $brandDark,
        'brandBright' => $brandBright,
        'brandSoft' => $brandSoft,
        'navBg' => $navBg,
        'navDark' => $navDark,
        'brandRgb' => $red . ', ' . $green . ', ' . $blue
    ];
}

function station_theme_style_html(): string
{
    $palette = station_theme_palette(station_ui_config()['themeColor'] ?? '#2f7de2');

    return '<style>:root{' .
        '--brand:' . station_h($palette['brand']) . ';' .
        '--brand-dark:' . station_h($palette['brandDark']) . ';' .
        '--brand-bright:' . station_h($palette['brandBright']) . ';' .
        '--brand-soft:' . station_h($palette['brandSoft']) . ';' .
        '--accent:' . station_h($palette['brand']) . ';' .
        '--nav-bg:' . station_h($palette['navBg']) . ';' .
        '--nav-dark:' . station_h($palette['navDark']) . ';' .
        '--brand-rgb:' . station_h($palette['brandRgb']) . ';' .
    '}</style>';
}

function station_js_config_html(): string
{
    $base = station_station_url('');
    $baseJs = json_encode(rtrim($base, '/') ?: '', JSON_THROW_ON_ERROR);

    return '<script>window.STATION_WEB_BASE=' . $baseJs . ';</script>';
}

/**
 * Emoji icons for the left navigation rail (readable at small sizes).
 */
function station_nav_icon(string $key): string
{
    $icons = [
        'dashboard' => '🏠',
        'users' => '👥',
        'templates' => '🧩',
        'settings' => '⚙️',
        'host_health' => '📡',
        'github_sync' => '🐙',
        'user-settings' => '👤',
        'logout' => '🚪',
        'assist' => '✨',
        'access_requests' => '📩',
    ];

    $emoji = $icons[$key] ?? '•';

    return '<span class="menu-emoji" aria-hidden="true">' . $emoji . '</span>';
}

/** @deprecated Use station_nav_icon() — kept for callers that still use the old name. */
function station_nav_icon_svg(string $key): string
{
    return station_nav_icon($key);
}

function station_pwa_head_html(string $title, string $description = '', string $stylesheetHref = 'assets/style.css?v=20260522a'): string
{
    $uiConfig = station_ui_config();
    $appName = trim((string) ($uiConfig['appName'] ?? 'Deployment Station'));
    $metaDescription = trim($description) !== ''
        ? trim($description)
        : trim((string) ($uiConfig['subheading'] ?? ''));

    if ($metaDescription === '') {
        $metaDescription = $appName;
    }

    $head = [
        '<meta charset="utf-8">',
        '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">',
        '<title>' . station_h($title) . '</title>',
        '<meta name="application-name" content="' . station_h($appName) . '">',
        '<meta name="apple-mobile-web-app-capable" content="yes">',
        '<meta name="apple-mobile-web-app-status-bar-style" content="default">',
        '<meta name="apple-mobile-web-app-title" content="' . station_h($appName) . '">',
        '<meta name="mobile-web-app-capable" content="yes">',
        '<meta name="theme-color" content="' . station_h((string) $uiConfig['themeColor']) . '">',
        '<meta name="description" content="' . station_h($metaDescription) . '">',
        station_favicon_html(),
        '<link rel="manifest" href="manifest.php">',
        '<link rel="stylesheet" href="' . station_h($stylesheetHref) . '">',
        station_theme_style_html(),
        station_js_config_html(),
    ];

    return implode("\n  ", array_values(array_filter($head, static fn ($line): bool => $line !== '')));
}

function station_pwa_register_html(): string
{
    return <<<'HTML'
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('service-worker.php', { scope: './' }).catch(function () {});
  });
}
</script>
HTML;
}

/**
 * Scale sidebar brand text so long app names stay on one line inside the rail.
 */
function station_brand_copy_font_sizes(string $appName, string $heading): array
{
    $nameLen = max(1, mb_strlen($appName));
    $headLen = max(1, mb_strlen($heading));
    $namePx = max(8, min(12, (int) floor(78 / $nameLen)));
    $headPx = max(7, min(10, (int) floor(64 / $headLen)));

    return ['name' => $namePx, 'kicker' => $headPx];
}

function station_dashboard_nav_divider(): string
{
    return '<div class="dashboard-menu-divider" role="separator" aria-hidden="true"></div>';
}

function station_dashboard_nav_link(
    string $active,
    string $key,
    string $href,
    string $iconKey,
    string $label,
    string $extraClass = '',
    ?int $badgeCount = null
): string {
    $classes = trim('dashboard-menu-link ' . ($active === $key ? 'active ' : '') . $extraClass);
    $icon = station_nav_icon($iconKey);
    $badgeHtml = '';
    if ($badgeCount !== null && $badgeCount > 0) {
        $badgeHtml = '<span class="menu-badge">' . station_h((string) min(99, $badgeCount)) . '</span>';
    }

    return '<a href="' . station_h($href) . '" class="' . station_h($classes) . '"' .
        ' title="' . station_h($label) . '" aria-label="' . station_h($label) . '">' .
        '<span class="menu-icon">' . $icon . $badgeHtml . '</span>' .
        '<span class="menu-label">' . station_h($label) . '</span>' .
    '</a>';
}

function station_dashboard_nav_html(string $active = 'dashboard'): string
{
    $uiConfig = station_ui_config();
    $appName = trim((string) ($uiConfig['appName'] ?? 'Deployment Station'));
    $brandIconUrl = trim((string) ($uiConfig['faviconUrl'] ?? '')) !== ''
        ? trim((string) ($uiConfig['faviconUrl'] ?? ''))
        : trim((string) ($uiConfig['appIconUrl'] ?? ''));
    $brandInitial = 'DS';
    $username = station_current_username();
    $user = function_exists('station_current_user') ? station_current_user() : null;
    $isAdmin = function_exists('station_is_admin') && station_is_admin($user);
    $isOwner = function_exists('station_is_owner') && station_is_owner($user);
    $role = $user ? (string) ($user['role'] ?? 'user') : 'user';
    $brandMark = $brandIconUrl !== ''
        ? '<img src="' . station_h($brandIconUrl) . '" alt="' . station_h($appName) . ' icon">'
        : station_h($brandInitial);
    $stationHeading = trim((string) ($uiConfig['heading'] ?? 'Deployment Station'));
    $brandFonts = station_brand_copy_font_sizes($appName, $stationHeading);
    $brandKickerStyle = ' style="font-size:' . (int) $brandFonts['kicker'] . 'px"';
    $brandNameStyle = ' style="font-size:' . (int) $brandFonts['name'] . 'px"';

    $links = [
        station_dashboard_nav_link($active, 'dashboard', 'station.php', 'dashboard', 'Dashboard'),
    ];

    $adminLinks = [];
    if ($isAdmin) {
        $adminLinks[] = station_dashboard_nav_link($active, 'users', 'users.php', 'users', 'Users');
        $adminLinks[] = station_dashboard_nav_link($active, 'templates', 'template-manager.php', 'templates', 'Templates');
    }

    if ($isOwner) {
        $adminLinks[] = station_dashboard_nav_link($active, 'settings', 'admin-settings.php', 'settings', 'Settings');
        $adminLinks[] = station_dashboard_nav_link($active, 'host_health', 'admin-host-health.php', 'host_health', 'Health');
    }

    if ($adminLinks !== []) {
        $links[] = station_dashboard_nav_divider();
        $links = array_merge($links, $adminLinks);
    }

    if (function_exists('station_can_build') && station_can_build($user)) {
        $adminGh = station_admin_settings();
        if (!empty($adminGh['githubEnabled'])) {
            $links[] = station_dashboard_nav_divider();
            $links[] = station_dashboard_nav_link($active, 'github_sync', 'github-sync.php', 'github_sync', 'GitHub');
        }
    }

    $links[] = station_dashboard_nav_divider();
    $links[] = station_dashboard_nav_link($active, 'user-settings', 'user-settings.php', 'user-settings', 'Account');
    $links[] = station_dashboard_nav_link($active, 'logout', 'logout.php', 'logout', 'Sign out', 'logout-link');

    return '<aside class="dashboard-nav">' .
        '<div class="dashboard-mobile-bar">' .
            '<a class="dashboard-brand" href="station.php">' .
                '<span class="dashboard-brand-mark">' . $brandMark . '</span>' .
                '<span class="dashboard-brand-copy">' .
                    '<span class="dashboard-brand-kicker"' . $brandKickerStyle . '>' . station_h($stationHeading) . '</span>' .
                    '<span class="dashboard-brand-name"' . $brandNameStyle . '>' . station_h($appName) . '</span>' .
                '</span>' .
            '</a>' .
            '<button type="button" class="dashboard-mobile-toggle" id="dashboardMobileToggle" aria-label="Open navigation" aria-controls="dashboardMenu" aria-expanded="false">' .
                '<span class="dashboard-mobile-toggle-lines" aria-hidden="true"><span></span><span></span><span></span></span>' .
            '</button>' .
        '</div>' .
        '<div class="dashboard-nav-inner">' .
            '<a class="dashboard-brand" href="station.php">' .
                '<span class="dashboard-brand-mark">' . $brandMark . '</span>' .
                '<span class="dashboard-brand-copy">' .
                    '<span class="dashboard-brand-kicker"' . $brandKickerStyle . '>' . station_h($stationHeading) . '</span>' .
                    '<span class="dashboard-brand-name"' . $brandNameStyle . '>' . station_h($appName) . '</span>' .
                '</span>' .
            '</a>' .
            '<nav class="dashboard-menu" id="dashboardMenu" aria-label="Primary navigation">' .
                '<div class="dashboard-mobile-user"><strong>' . station_h($username) . '</strong><span>' . station_h($role) . '</span></div>' .
                implode('', $links) .
            '</nav>' .
        '</div>' .
    '</aside>';
}

/**
 * One-time modal for owners after sign-in when unseen access requests exist.
 */
function station_access_requests_owner_alert_html(): string
{
    if (empty($_SESSION['station_access_requests_alert'])) {
        return '';
    }

    require_once __DIR__ . '/access-requests.php';
    $unseen = station_access_requests_unseen_count();
    if ($unseen < 1) {
        station_access_requests_clear_owner_alert_session();

        return '';
    }

    $nLabel = $unseen === 1 ? '1 new access request' : $unseen . ' new access requests';

    return '<dialog id="stationAccessRequestsAlert" class="modal-dialog access-requests-alert-dialog" aria-labelledby="stationAccessRequestsAlertTitle">'
        . '<div class="modal-header">'
        . '<div><p class="dashboard-kicker">Owner</p><h2 id="stationAccessRequestsAlertTitle">' . station_h($nLabel) . '</h2></div>'
        . '<button type="button" class="modal-close-btn" id="stationAccessRequestsAlertClose" aria-label="Close">&times;</button>'
        . '</div>'
        . '<p class="setting-description">Someone requested access via GitHub. Review their details under <strong>Users → Access requests</strong> and create an account when ready.</p>'
        . '<div class="modal-actions" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;">'
        . '<a class="btn-primary" href="users.php?tab=requests" id="stationAccessRequestsAlertReview">Review requests</a>'
        . '<button type="button" class="secondary-btn" id="stationAccessRequestsAlertDismiss">Not now</button>'
        . '</div>'
        . '</dialog>'
        . <<<'HTML'
<script>
(function () {
  var dlg = document.getElementById('stationAccessRequestsAlert');
  if (!dlg || typeof dlg.showModal !== 'function') { return; }
  var closeBtn = document.getElementById('stationAccessRequestsAlertClose');
  var dismissBtn = document.getElementById('stationAccessRequestsAlertDismiss');
  function closeDlg() {
    if (dlg.open) { dlg.close(); }
  }
  dlg.addEventListener('close', function () {
    fetch('access-requests-alert-dismiss.php', { method: 'POST', credentials: 'same-origin' }).catch(function () {});
  });
  if (closeBtn) { closeBtn.addEventListener('click', closeDlg); }
  if (dismissBtn) { dismissBtn.addEventListener('click', closeDlg); }
  window.setTimeout(function () { dlg.showModal(); }, 400);
})();
</script>
HTML;
}

/**
 * Standard authenticated dashboard footer: mobile nav, AI Assist + clipboard, PWA.
 */
function station_dashboard_page_footer_html(): string
{
    return station_dashboard_nav_script_html()
        . '<script src="assets/station-shell-nav.js?v=20260522a"></script>'
        . station_clipboard_fab_html()
        . station_pwa_register_html();
}

function station_dashboard_nav_script_html(): string
{
    return <<<'HTML'
<script>
(function () {
  if (window.__stationNavToggleInit) {
    return;
  }
  window.__stationNavToggleInit = true;

  var mobileNav = document.querySelector('.dashboard-nav');
  var mobileToggle = document.getElementById('dashboardMobileToggle');

  if (!mobileNav || !mobileToggle) {
    return;
  }

  mobileToggle.addEventListener('click', function (event) {
    event.preventDefault();
    event.stopPropagation();
    var isOpen = mobileNav.classList.toggle('is-open');
    mobileToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    mobileToggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
  });
})();
</script>
HTML;
}

/**
 * Global floating clipboard widget (FAB + drawer). Available on any page that
 * embeds it, so users can paste from mobile and pick up the text on desktop
 * (or vice versa) without going back to the dashboard.
 */
function station_clipboard_fab_html(): string
{
    $username = station_current_username();
    if ($username === '') {
        return '';
    }

    if (!function_exists('station_openai_configured')) {
        require_once __DIR__ . '/openai.php';
    }

    $assistIcon = station_nav_icon('assist');
    $openaiOn = station_openai_configured();
    $assistDisabled = $openaiOn ? '' : ' disabled title="Add OpenAI under Admin → Integrations or User Settings"';

    $assistBtn = '<button class="assist-fab-button" type="button" id="stationAssistFabToggle" aria-label="Open AI Assist" aria-controls="stationAssistFabPanel" aria-expanded="false"' . $assistDisabled . '>'
        . '<span class="assist-fab-icon" aria-hidden="true">' . $assistIcon . '</span>'
        . '<span class="assist-fab-label">AI Assist</span></button>';

    return '<aside class="page-tools-dock" id="stationPageToolsDock" aria-live="polite">'
        . '<div class="page-tools-slot page-tools-slot--assist">' . $assistBtn
        . <<<'HTML'
  <section class="assist-fab-panel" id="stationAssistFabPanel" role="dialog" aria-label="DeployStation AI Assist" aria-hidden="true">
    <header class="assist-fab-head">
      <div class="assist-fab-head-copy">
        <p class="assist-fab-kicker">AI Assist</p>
        <h3 class="assist-fab-title">DeployStation copilot</h3>
        <p class="assist-fab-hint" id="stationAssistContextLabel">This page</p>
      </div>
      <button type="button" class="assist-fab-close" id="stationAssistFabClose" aria-label="Close AI Assist">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
      </button>
    </header>
    <div class="assist-chat-log" id="stationAssistChatLog" aria-live="polite">
      <div class="assist-chat-msg assist-chat-msg-system">Ask about this page, Docker, GitHub, or how DeployStation works.</div>
    </div>
    <div class="assist-context-bar" role="group" aria-label="Context to include">
      <label class="assist-ctx-chip" title="URL, title, and your role">
        <input type="checkbox" id="stationAssistIncludePage" checked>
        <span class="assist-ctx-chip-label">Page</span>
        <span class="assist-ctx-chip-dot" aria-hidden="true"></span>
      </label>
      <label class="assist-ctx-chip" title="Files, Docker, and GitHub when a project is open">
        <input type="checkbox" id="stationAssistIncludeProject" checked>
        <span class="assist-ctx-chip-label">Project</span>
        <span class="assist-ctx-chip-dot" aria-hidden="true"></span>
      </label>
    </div>
    <details class="assist-extra-drawer">
      <summary class="assist-extra-summary">Extra context</summary>
      <textarea id="stationAssistExtra" class="assist-extra" rows="4" placeholder="Paste logs, errors, or notes…"></textarea>
    </details>
    <div class="assist-chat-compose">
      <textarea id="stationAssistInput" class="assist-input" rows="3" placeholder="Ask anything about DeployStation…"></textarea>
      <button type="button" class="btn-primary assist-send" id="stationAssistSend">Send</button>
    </div>
    <p class="assist-fab-status" id="stationAssistStatus" aria-live="polite"></p>
  </section>
</div>
<div class="page-tools-slot page-tools-slot--clip">
<aside class="clip-fab" id="stationClipboardFab">
  <button class="clip-fab-button" type="button" id="stationClipboardFabToggle" aria-label="Open clipboard" aria-controls="stationClipboardFabPanel" aria-expanded="false">
    <span class="clip-fab-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
        <rect x="8" y="2" width="8" height="4" rx="1"/>
      </svg>
    </span>
    <span class="clip-fab-label">Clipboard</span>
    <span class="clip-fab-badge" id="stationClipboardFabBadge" hidden>•</span>
  </button>
  <section class="clip-fab-panel" id="stationClipboardFabPanel" role="dialog" aria-label="Shared clipboard" aria-hidden="true">
    <header class="clip-fab-head">
      <div>
        <p class="clip-fab-kicker">Shared clipboard</p>
        <h3 class="clip-fab-title">Paste &amp; pick up anywhere</h3>
        <p class="clip-fab-hint">Synced to your account. Open on another device to read it back.</p>
      </div>
      <button type="button" class="clip-fab-close" id="stationClipboardFabClose" aria-label="Close clipboard">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
      </button>
    </header>
    <div class="clip-fab-toolbar">
      <button type="button" class="clip-fab-action" data-clip-action="paste" title="Read from your device clipboard">⇩ Paste</button>
      <button type="button" class="clip-fab-action" data-clip-action="copy" title="Copy to your device clipboard">⇧ Copy</button>
      <label class="clip-fab-action clip-fab-upload" title="Attach a file">
        <input type="file" id="stationClipboardFabFile" hidden>
        ＋ File
      </label>
      <button type="button" class="clip-fab-action danger" data-clip-action="clear" title="Clear shared clipboard">🗑 Clear</button>
    </div>
    <textarea id="stationClipboardFabText" placeholder="Type or paste text here — it syncs to all your devices."></textarea>
    <div class="clip-fab-files" id="stationClipboardFabFiles"></div>
    <p class="clip-fab-status" id="stationClipboardFabStatus" aria-live="polite"></p>
  </section>
</aside>
  </div>
</aside>
<script src="assets/station-assist.js?v=20260522a"></script>
<script>
(function () {
  if (window.__stationClipboardFabPoll) {
    window.clearInterval(window.__stationClipboardFabPoll);
    window.__stationClipboardFabPoll = null;
  }

  var dock = document.getElementById('stationPageToolsDock');
  var fab = document.getElementById('stationClipboardFab');
  var toggle = document.getElementById('stationClipboardFabToggle');
  var panel = document.getElementById('stationClipboardFabPanel');
  var assistPanel = document.getElementById('stationAssistFabPanel');
  var closeBtn = document.getElementById('stationClipboardFabClose');
  var text = document.getElementById('stationClipboardFabText');
  var status = document.getElementById('stationClipboardFabStatus');
  var filesBox = document.getElementById('stationClipboardFabFiles');
  var badge = document.getElementById('stationClipboardFabBadge');
  var fileInput = document.getElementById('stationClipboardFabFile');
  if (!fab || !toggle || !panel || !text) { return; }

  var saveTimer = null;
  var dirty = false;
  var pollTimer = null;
  var revision = '0';
  var lastApplied = '';

  function setStatus(message, sticky) {
    if (!status) { return; }
    status.textContent = message || '';
    if (!message || sticky) { return; }
    window.clearTimeout(setStatus._t);
    setStatus._t = window.setTimeout(function () { status.textContent = ''; }, 1800);
  }

  function setOpen(open) {
    var o = !!open;

    // Before hiding the panel, move focus out — otherwise marking it
    // aria-hidden / inert while a descendant is focused trips the
    // browser's a11y warning ("Blocked aria-hidden on an element because
    // its descendant retained focus").
    if (!o && panel.contains(document.activeElement)) {
      try { document.activeElement.blur(); } catch (_) {}
      try { toggle.focus({ preventScroll: true }); } catch (_) { try { toggle.focus(); } catch (__) {} }
    }

    fab.classList.toggle('is-open', o);
    panel.classList.toggle('clip-fab-panel--open', o);
    if (dock) {
      dock.classList.toggle('clip-open', o);
    }
    panel.setAttribute('aria-hidden', o ? 'false' : 'true');
    if (typeof panel.toggleAttribute === 'function') {
      panel.toggleAttribute('inert', !o);
    }
    toggle.setAttribute('aria-expanded', o ? 'true' : 'false');
    // Force layout regardless of which CSS version the browser cached.
    // Some clients still have the old service-worker cache that doesn't
    // know about .clip-fab-panel--open, so we drive `display` inline.
    panel.style.display = o ? 'flex' : 'none';
    var assistOn = assistPanel && assistPanel.classList.contains('assist-fab-panel--open');
    document.body.classList.toggle('station-overlay-open', o || assistOn);
    if (o) {
      window.setTimeout(function () { text && text.focus(); }, 60);
      refresh();
    }
  }

  // Ensure the panel starts hidden no matter what CSS the browser has.
  panel.style.display = 'none';
  if (typeof panel.toggleAttribute === 'function') {
    panel.toggleAttribute('inert', true);
  }

  function renderFiles(items) {
    if (!filesBox) { return; }
    filesBox.innerHTML = '';
    if (!Array.isArray(items) || items.length === 0) {
      if (badge) { badge.hidden = true; }
      return;
    }
    if (badge) { badge.hidden = false; }
    items.forEach(function (item) {
      var row = document.createElement('div');
      row.className = 'clip-fab-file';

      var link = document.createElement('a');
      link.href = item.url || '#';
      link.target = '_blank';
      link.rel = 'noreferrer';
      link.className = 'clip-fab-file-link';
      var badgeText = document.createElement('span');
      badgeText.className = 'clip-fab-file-type';
      badgeText.textContent = item.isImage ? 'Image' : 'File';
      var name = document.createElement('span');
      name.className = 'clip-fab-file-name';
      name.textContent = item.originalName || 'attachment';
      link.appendChild(badgeText);
      link.appendChild(name);

      var remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'clip-fab-file-remove';
      remove.textContent = '×';
      remove.title = 'Remove attachment';
      remove.addEventListener('click', function () {
        if (!item.name) { return; }
        fetch('clipboard.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          credentials: 'same-origin',
          body: 'action=remove_file&name=' + encodeURIComponent(item.name)
        }).then(function (r) { return r.json(); })
          .then(function (d) {
            if (d && d.ok) {
              renderFiles(d.files || []);
              revision = String((d && d.revision) || revision);
              setStatus('Attachment removed');
            }
          }).catch(function () { setStatus('Delete failed'); });
      });

      row.appendChild(link);
      row.appendChild(remove);
      filesBox.appendChild(row);
    });
  }

  function applyPayload(payload) {
    if (!payload || !payload.ok) { return; }
    revision = String(payload.revision || revision);
    if (typeof payload.content === 'string' && (!dirty || payload.content === text.value)) {
      var active = document.activeElement === text;
      var start = text.selectionStart;
      var end = text.selectionEnd;
      if (payload.content !== text.value) {
        text.value = payload.content;
      }
      lastApplied = payload.content;
      if (active) { try { text.setSelectionRange(start, end); } catch (_) {} }
    }
    renderFiles(payload.files || []);
  }

  function refresh() {
    fetch('clipboard.php?action=get', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(applyPayload)
      .catch(function () {});
  }

  function save() {
    fetch('clipboard.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      credentials: 'same-origin',
      body: 'action=save&content=' + encodeURIComponent(text.value)
    }).then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { setStatus('Save failed'); return; }
        dirty = false;
        revision = String(d.revision || revision);
        lastApplied = typeof d.content === 'string' ? d.content : text.value;
        setStatus('Saved');
      }).catch(function () { setStatus('Save error'); });
  }

  function uploadFile(file) {
    if (!file) { return; }
    var data = new FormData();
    data.append('action', 'upload_file');
    data.append('clip_file', file);
    setStatus('Uploading…', true);
    fetch('clipboard.php', { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { setStatus('Upload failed'); return; }
        renderFiles(d.files || []);
        setStatus('Attached');
      }).catch(function () { setStatus('Upload error'); });
  }

  toggle.addEventListener('click', function (ev) {
    ev.preventDefault();
    ev.stopPropagation();
    var assistToggle = document.getElementById('stationAssistFabToggle');
    if (assistPanel && assistPanel.classList.contains('assist-fab-panel--open')) {
      assistPanel.classList.remove('assist-fab-panel--open');
      assistPanel.style.display = 'none';
      if (dock) { dock.classList.remove('assist-open'); }
      if (assistToggle) { assistToggle.setAttribute('aria-expanded', 'false'); }
    }
    setOpen(!panel.classList.contains('clip-fab-panel--open'));
  });
  if (closeBtn) {
    closeBtn.addEventListener('click', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      setOpen(false);
    });
  }

  text.addEventListener('input', function () {
    dirty = true;
    setStatus('Syncing…', true);
    window.clearTimeout(saveTimer);
    saveTimer = window.setTimeout(save, 400);
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && panel.classList.contains('clip-fab-panel--open')) { setOpen(false); }
  });

  panel.querySelectorAll('[data-clip-action]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var action = btn.getAttribute('data-clip-action');
      if (action === 'copy') {
        try {
          navigator.clipboard.writeText(text.value)
            .then(function () { setStatus('Copied to device'); })
            .catch(function () { setStatus('Copy blocked'); });
        } catch (_) { setStatus('Copy blocked'); }
      } else if (action === 'paste') {
        try {
          navigator.clipboard.readText()
            .then(function (value) {
              if (typeof value !== 'string') { return; }
              text.value = (text.value ? text.value + '\n' : '') + value;
              dirty = true;
              save();
              setStatus('Pasted');
            }).catch(function () { setStatus('Paste blocked — focus the field and paste manually.'); });
        } catch (_) { setStatus('Paste blocked'); }
      } else if (action === 'clear') {
        text.value = '';
        fetch('clipboard.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          credentials: 'same-origin',
          body: 'action=clear'
        }).then(function (r) { return r.json(); })
          .then(function (d) {
            renderFiles([]);
            revision = String((d && d.revision) || revision);
            setStatus('Cleared');
          });
      }
    });
  });

  if (fileInput) {
    fileInput.addEventListener('change', function () {
      if (this.files && this.files[0]) {
        uploadFile(this.files[0]);
        this.value = '';
      }
    });
  }

  refresh();
  pollTimer = window.setInterval(refresh, 4000);
  window.__stationClipboardFabPoll = pollTimer;

  document.addEventListener('click', function (ev) {
    if (!panel.classList.contains('clip-fab-panel--open') || !fab.classList.contains('is-open')) { return; }
    if (fab.contains(ev.target)) { return; }
    setOpen(false);
  });
})();
</script>
HTML
        . station_access_requests_owner_alert_html();
}

function station_clipboard_path(string $username): string
{
    return station_user_profiles_dir() . '/' . station_safe_name($username) . '.clipboard.txt';
}

function station_clipboard_files_meta_path(string $username): string
{
    return station_user_profiles_dir() . '/' . station_safe_name($username) . '.clipboard-files.json';
}

function station_clipboard_revision(string $username): string
{
    $parts = [];
    foreach ([station_clipboard_path($username), station_clipboard_files_meta_path($username)] as $path) {
        if (!is_file($path)) {
            continue;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            continue;
        }

        $parts[] = hash('sha1', $content);
    }

    if ($parts === []) {
        return '0';
    }

    return hash('sha1', implode('|', $parts));
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

function station_brand_icon_fields(): array
{
    return [
        'favicon' => ['urlField' => 'faviconUrl', 'fileField' => 'faviconFile', 'fileStem' => 'favicon'],
        'appIcon' => ['urlField' => 'appIconUrl', 'fileField' => 'appIconFile', 'fileStem' => 'app-icon'],
        'appIcon192' => ['urlField' => 'appIcon192Url', 'fileField' => 'appIcon192File', 'fileStem' => 'app-icon-192'],
        'appIcon512' => ['urlField' => 'appIcon512Url', 'fileField' => 'appIcon512File', 'fileStem' => 'app-icon-512'],
        'appMaskableIcon' => ['urlField' => 'appMaskableIconUrl', 'fileField' => 'appMaskableIconFile', 'fileStem' => 'app-icon-maskable'],
    ];
}

/**
 * Delete any previously-uploaded icon files for $fileStem regardless of extension.
 * Used before saving a new icon (so .png ⇄ .ico replacements don't leave orphans)
 * and when the user explicitly removes an icon.
 */
function station_delete_local_brand_icon(string $fileStem): void
{
    $iconsDir = station_icons_dir();
    $stem = station_safe_name($fileStem);
    if ($stem === '' || !is_dir($iconsDir)) {
        return;
    }
    foreach (['ico', 'png', 'jpg', 'jpeg', 'webp'] as $ext) {
        $path = $iconsDir . '/' . $stem . '.' . $ext;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function station_store_uploaded_brand_icon(array $file, string $fileStem): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'url' => ''];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Upload failed (PHP error ' . $error . ').'];
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return ['ok' => false, 'message' => 'An uploaded icon file was not available on the server.'];
    }

    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['ico', 'png', 'jpg', 'jpeg', 'webp'];
    if (!in_array($extension, $allowedExtensions, true)) {
        return ['ok' => false, 'message' => 'Allowed icon formats are .ico, .png, .jpg, .jpeg, and .webp.'];
    }

    $iconsDir = station_icons_dir();
    if (!is_dir($iconsDir) && !@mkdir($iconsDir, 0755, true) && !is_dir($iconsDir)) {
        return ['ok' => false, 'message' => 'Could not create the local icons folder.'];
    }

    $targetName = station_safe_name($fileStem);
    if ($targetName === '') {
        $targetName = 'icon';
    }

    // Remove any older copies (different extension) so a .png → .ico swap
    // doesn't leave the previous file orphaned on disk + still served from
    // browser caches under the old URL.
    station_delete_local_brand_icon($targetName);

    $targetPath = $iconsDir . '/' . $targetName . '.' . $extension;
    if (!@move_uploaded_file($tmpPath, $targetPath)) {
        return ['ok' => false, 'message' => 'Could not save the uploaded icon file.'];
    }
    @chmod($targetPath, 0644);

    return [
        'ok' => true,
        'url' => station_icons_web_path() . '/' . rawurlencode($targetName . '.' . $extension),
    ];
}

/**
 * Append `?v=<mtime>` to locally-stored icon URLs so browsers (and the PWA
 * manifest) fetch the fresh bytes after each upload. Pass-through for
 * external URLs and URLs that already carry a query string.
 */
function station_icon_url_with_cache_buster(string $url): string
{
    $u = trim($url);
    if ($u === '' || str_contains($u, '?')) {
        return $u;
    }

    $pathPart = $u;
    if (preg_match('#^https?://#i', $u)) {
        $parsed = parse_url($u);
        $pathPart = is_array($parsed) && isset($parsed['path']) ? (string) $parsed['path'] : '';
    }

    $name = basename(str_replace('\\', '/', $pathPart));
    if ($name === '' || $name === '.' || $name === '..') {
        return $u;
    }

    $candidate = station_icons_dir() . '/' . $name;
    if (!is_file($candidate)) {
        return $u;
    }

    // Only add cache-busters for URLs we believe are served from this Station's /icos/ tree
    // (relative paths, or absolute URLs whose path includes /icos/). Avoids mangling unrelated CDNs.
    $isLikelyLocal = !preg_match('#^https?://#i', $u) || str_contains($u, '/icos/');
    if (!$isLikelyLocal) {
        return $u;
    }

    $mtime = @filemtime($candidate);

    return $mtime ? ($u . '?v=' . (int) $mtime) : $u;
}

/**
 * Process the per-icon URL field, file field, and optional "remove" checkbox
 * coming from the admin Branding form.
 *
 * Behavior:
 *   - Blank URL field + nothing uploaded → leaves existing value alone
 *     (unless `remove_<urlField>` is set, which clears it and deletes the
 *     local file).
 *   - Non-blank URL field → stored as-is.
 *   - Uploaded file → replaces both URL and on-disk file.
 *   - A single failed upload no longer aborts the whole save. All errors
 *     are returned in `messages`; the caller persists the rest.
 *
 * @return array{ok:bool,settings:array<string,mixed>,message:string,messages:string[]}
 */
function station_apply_brand_icon_inputs(array $settings, array $post, array $files): array
{
    $messages = [];

    foreach (station_brand_icon_fields() as $field) {
        $urlField = (string) ($field['urlField'] ?? '');
        $fileField = (string) ($field['fileField'] ?? '');
        $fileStem = (string) ($field['fileStem'] ?? 'icon');
        if ($urlField === '' || $fileField === '') {
            continue;
        }

        $existing = trim((string) ($settings[$urlField] ?? ''));
        $removeRequested = !empty($post['remove_' . $urlField]);
        $postedRaw = $post[$urlField] ?? null;
        $postedUrl = is_string($postedRaw) ? trim($postedRaw) : null;

        if ($removeRequested) {
            station_delete_local_brand_icon($fileStem);
            $settings[$urlField] = '';
        } elseif ($postedUrl !== null && $postedUrl !== $existing) {
            // URL was edited (including being cleared explicitly).
            if ($postedUrl === '') {
                station_delete_local_brand_icon($fileStem);
            }
            $settings[$urlField] = $postedUrl;
        }

        if (!isset($files[$fileField]) || !is_array($files[$fileField])) {
            continue;
        }

        $upload = station_store_uploaded_brand_icon($files[$fileField], $fileStem);
        if (empty($upload['ok'])) {
            $messages[] = $fileStem . ': ' . (string) ($upload['message'] ?? 'upload failed.');
            continue;
        }

        $uploadedUrl = trim((string) ($upload['url'] ?? ''));
        if ($uploadedUrl !== '') {
            $settings[$urlField] = $uploadedUrl;
        }
    }

    return [
        'ok' => $messages === [],
        'settings' => $settings,
        'message' => $messages === [] ? '' : implode(' · ', $messages),
        'messages' => $messages,
    ];
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
        'notes' => '',
        'launch' => [
            'webEntryManual' => false,
            'webEntryDir' => '',
            'webEntryFile' => 'index.html',
        ],
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

/**
 * Consume standard flash tiers (ok / warning / error) in display order.
 */
function station_flash_banners_html(): string
{
    $ok = station_flash_get('ok');
    $warning = station_flash_get('warning');
    $error = station_flash_get('error');
    $html = '';
    if ($ok !== '') {
        $html .= '<div class="alert ok">' . station_h($ok) . '</div>';
    }
    if ($warning !== '') {
        $html .= '<div class="alert warning">' . station_h($warning) . '</div>';
    }
    if ($error !== '') {
        $html .= '<div class="alert error">' . station_h($error) . '</div>';
    }

    return $html;
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
        'themeColor' => '',
        'onboardingCompleted' => false,
        'integrations' => [
            'github' => ['enabled' => false, 'username' => '', 'token' => '', 'repo' => '', 'authMode' => 'pat', 'oauthConnectedAt' => ''],
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

    return false;
}

function station_user_needs_onboarding(string $username): bool
{
    $settings = station_admin_settings();
    if (!($settings['onboardingRequired'] ?? true)) {
        return false;
    }

    $user = station_current_user();
    if (!station_can_build($user)) {
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

    $path = station_activity_log_path();
    if (@file_put_contents($path, $json . "\n", FILE_APPEND | LOCK_EX) === false) {
        return;
    }

    $limit = station_audit_log_limit();
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || count($lines) <= $limit) {
        return;
    }

    $trimmed = array_slice($lines, -1 * $limit);
    @file_put_contents($path, implode("\n", $trimmed) . "\n", LOCK_EX);
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
