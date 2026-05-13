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

function station_admin_settings(): array
{
    return station_read_json(station_admin_settings_path(), [
        'serverInfrastructure' => 'apache',
        'defaultProjectVisibility' => 'private',
        'defaultProjectAccessMode' => 'admin',
        'auditLogLimit' => 50,
        'onboardingRequired' => true,
        'allowPublicProjects' => true,
        'githubEnabled' => true,
        'vscodeEnabled' => true,
        'chatgptEnabled' => true,
        'codexEnabled' => true,
        'stationHeading' => 'Deployment Station',
        'stationSubheading' => '',
        'faviconUrl' => '',
        'appIconUrl' => '',
        'appIcon192Url' => '',
        'appIcon512Url' => '',
        'appMaskableIconUrl' => '',
        'themeColor' => '#2f7de2',
        'customTemplates' => []
    ]);
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

    $lines = [
        '# Add these locations inside your server {} block, above any generic PHP location.',
        '# Do not use ^~ here, or Nginx will serve station PHP files as downloads instead of passing them to PHP-FPM.',
        '',
        'location ' . $stationPrefix . ' {',
        '    try_files $uri $uri/ ' . $stationPrefix . '/index.php?$query_string;',
        '}',
        '',
        'location ~ ^' . $securePattern . '/(?<station_project>(?!station(?:/|$)|index\.php$)[^/]+)(?:/(?<station_path>.*))?$ {',
        '    rewrite ^ ' . $stationPrefix . '/project-serve.php?project=$station_project&path=$station_path last;',
        '}',
        '',
        '# Keep your normal PHP handler enabled so project-serve.php and station PHP files execute.'
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
    $fallback = trim((string) ($uiConfig['appIconUrl'] ?? ''));
    $favicon = trim((string) ($uiConfig['faviconUrl'] ?? ''));
    $icon192 = trim((string) ($uiConfig['appIcon192Url'] ?? ''));
    $icon512 = trim((string) ($uiConfig['appIcon512Url'] ?? ''));
    $maskable = trim((string) ($uiConfig['appMaskableIconUrl'] ?? ''));

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

function station_pwa_head_html(string $title, string $description = '', string $stylesheetHref = 'assets/style.css'): string
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
        station_theme_style_html()
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

function station_dashboard_nav_link(string $active, string $key, string $href, string $icon, string $label, string $extraClass = ''): string
{
    $classes = trim('dashboard-menu-link ' . ($active === $key ? 'active ' : '') . $extraClass);

    return '<a href="' . station_h($href) . '" class="' . station_h($classes) . '">' .
        '<span class="menu-icon">' . station_h($icon) . '</span>' .
        '<span>' . station_h($label) . '</span>' .
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
    $userInitial = strtoupper(substr($username !== '' ? $username : 'U', 0, 1));

    $brandMark = $brandIconUrl !== ''
        ? '<img src="' . station_h($brandIconUrl) . '" alt="' . station_h($appName) . ' icon">'
        : station_h($brandInitial);

    $links = [
        station_dashboard_nav_link($active, 'dashboard', 'station.php', '⌂', 'Dashboard'),
    ];

    if ($isAdmin) {
        $links[] = station_dashboard_nav_link($active, 'users', 'users.php', '◎', 'Users');
        $links[] = station_dashboard_nav_link($active, 'templates', 'template-manager.php', '⋔', 'Templates');
    }

    if ($isOwner) {
        $links[] = station_dashboard_nav_link($active, 'settings', 'admin-settings.php', '◫', 'Settings');
    }

    $links[] = station_dashboard_nav_link($active, 'user-settings', 'user-settings.php', '◌', 'User Settings');
    $links[] = station_dashboard_nav_link($active, 'logout', 'logout.php', '↗', 'Sign out', 'logout-link');

    return '<aside class="dashboard-nav">' .
        '<div class="dashboard-mobile-bar">' .
            '<a class="dashboard-brand" href="station.php">' .
                '<span class="dashboard-brand-mark">' . $brandMark . '</span>' .
                '<span class="dashboard-brand-copy">' .
                    '<span class="dashboard-brand-kicker">' . station_h((string) ($uiConfig['heading'] ?? 'Deployment Station')) . '</span>' .
                    '<span class="dashboard-brand-name">' . station_h($appName) . '</span>' .
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
                    '<span class="dashboard-brand-kicker">' . station_h((string) ($uiConfig['heading'] ?? 'Deployment Station')) . '</span>' .
                    '<span class="dashboard-brand-name">' . station_h($appName) . '</span>' .
                '</span>' .
            '</a>' .
            '<nav class="dashboard-menu" id="dashboardMenu" aria-label="Primary navigation">' .
                '<div class="dashboard-mobile-user"><strong>' . station_h($username) . '</strong><span>' . station_h($role) . '</span></div>' .
                implode('', $links) .
            '</nav>' .
            '<a class="dashboard-account" href="user-settings.php">' .
                '<span class="dashboard-avatar">' . station_h($userInitial) . '</span>' .
                '<span class="dashboard-account-copy"><strong>' . station_h($username) . '</strong><span>' . station_h($role) . '</span></span>' .
            '</a>' .
        '</div>' .
    '</aside>';
}

function station_dashboard_nav_script_html(): string
{
    return <<<'HTML'
<script>
(function () {
  var mobileNav = document.querySelector('.dashboard-nav');
  var mobileToggle = document.getElementById('dashboardMobileToggle');

  if (!mobileNav || !mobileToggle) {
    return;
  }

  mobileToggle.addEventListener('click', function () {
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

    return <<<'HTML'
<aside class="clip-fab" id="stationClipboardFab" aria-live="polite">
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
  <section class="clip-fab-panel" id="stationClipboardFabPanel" role="dialog" aria-label="Shared clipboard" hidden>
    <header class="clip-fab-head">
      <div>
        <p class="clip-fab-kicker">Shared clipboard</p>
        <h3 class="clip-fab-title">Paste &amp; pick up anywhere</h3>
        <p class="clip-fab-hint">Synced to your account. Open on another device to read it back.</p>
      </div>
      <button type="button" class="clip-fab-close" id="stationClipboardFabClose" aria-label="Close clipboard">✕</button>
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
<script>
(function () {
  if (window.__stationClipboardFabInit) { return; }
  window.__stationClipboardFabInit = true;

  var fab = document.getElementById('stationClipboardFab');
  var toggle = document.getElementById('stationClipboardFabToggle');
  var panel = document.getElementById('stationClipboardFabPanel');
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
    fab.classList.toggle('is-open', !!open);
    panel.hidden = !open;
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      window.setTimeout(function () { text && text.focus(); }, 60);
      refresh();
    }
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

  toggle.addEventListener('click', function () { setOpen(panel.hidden); });
  if (closeBtn) { closeBtn.addEventListener('click', function () { setOpen(false); }); }

  text.addEventListener('input', function () {
    dirty = true;
    setStatus('Syncing…', true);
    window.clearTimeout(saveTimer);
    saveTimer = window.setTimeout(save, 400);
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !panel.hidden) { setOpen(false); }
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
})();
</script>
HTML;
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

function station_store_uploaded_brand_icon(array $file, string $fileStem): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'url' => ''];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'One of the icon uploads failed.'];
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
    $targetPath = $iconsDir . '/' . $targetName . '.' . $extension;
    if (!@move_uploaded_file($tmpPath, $targetPath)) {
        return ['ok' => false, 'message' => 'Could not save the uploaded icon file.'];
    }

    return [
        'ok' => true,
        'url' => station_icons_web_path() . '/' . rawurlencode($targetName . '.' . $extension),
    ];
}

function station_apply_brand_icon_inputs(array $settings, array $post, array $files): array
{
    foreach (station_brand_icon_fields() as $field) {
        $urlField = (string) ($field['urlField'] ?? '');
        $fileField = (string) ($field['fileField'] ?? '');
        $fileStem = (string) ($field['fileStem'] ?? 'icon');
        if ($urlField === '' || $fileField === '') {
            continue;
        }

        $settings[$urlField] = trim((string) ($post[$urlField] ?? ($settings[$urlField] ?? '')));

        if (!isset($files[$fileField]) || !is_array($files[$fileField])) {
            continue;
        }

        $upload = station_store_uploaded_brand_icon($files[$fileField], $fileStem);
        if (empty($upload['ok'])) {
            return ['ok' => false, 'settings' => $settings, 'message' => (string) ($upload['message'] ?? 'Could not save an uploaded icon.')];
        }

        $uploadedUrl = trim((string) ($upload['url'] ?? ''));
        if ($uploadedUrl !== '') {
            $settings[$urlField] = $uploadedUrl;
        }
    }

    return ['ok' => true, 'settings' => $settings];
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
        'themeColor' => '',
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
