<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/docker.php';

station_require_owner();
$settings = station_admin_settings();
$currentAppName = (string) (station_config()['appName'] ?? 'Deployment Station');
$currentStationDirName = basename(station_base_dir());
$uiConfig = station_ui_config();
$error = '';
$ok = station_flash_get('ok');
$accessModes = station_allowed_project_access_modes();
$dockerServices = station_docker_services();
$dockerSettings = station_docker_settings();
$settingsTabs = [
    'general' => 'Branding',
    'project-defaults' => 'Projects',
    'docker' => 'Docker',
    'integrations' => 'Integrations',
    'onboarding' => 'Onboarding',
];

$activeTab = (string) ($_GET['tab'] ?? 'general');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Prefer the tab for the save button that was clicked — the hidden activeTab
    // can be stale (e.g. ?tab=docker then #project-defaults without re-running JS).
    if (isset($_POST['admin_save_section'])) {
        $sec = (string) $_POST['admin_save_section'];
        $activeTab = isset($settingsTabs[$sec]) ? $sec : 'general';
    } else {
        $activeTab = (string) ($_POST['activeTab'] ?? $activeTab);
    }
}
if (!isset($settingsTabs[$activeTab])) {
    $activeTab = 'general';
}
$iconUploadAccept = '.ico,.png,.jpg,.jpeg,.webp,image/x-icon,image/png,image/jpeg,image/webp';
$brandIconLabels = [
    'favicon' => ['label' => 'Favicon', 'description' => 'Browser tab icon. Use .ico, .png, or .webp.'],
    'appIcon' => ['label' => 'App Icon', 'description' => 'Default install icon used when a specific PWA icon is not provided.'],
    'appIcon192' => ['label' => 'PWA Icon 192', 'description' => 'Small install icon for mobile and desktop app surfaces.'],
    'appIcon512' => ['label' => 'PWA Icon 512', 'description' => 'Large install icon for app launchers and stores.'],
    'appMaskableIcon' => ['label' => 'Maskable Icon', 'description' => 'Safe-area icon for Android adaptive app icons.'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'rebuild_nginx_include') {
    $result = station_write_nginx_projects_conf();
    if (!empty($result['ok'])) {
        station_log_event('admin.nginx.include.rebuilt', [
            'count' => (int) ($result['count'] ?? 0),
            'path' => (string) ($result['path'] ?? ''),
        ]);
        $msg = (string) ($result['message'] ?? 'Nginx include regenerated.') . station_nginx_include_reload_hint_for_flash($result);
        station_flash_set('ok', $msg);
    } else {
        station_log_event('nginx.include.failed', [
            'phase' => 'admin-manual-rebuild',
            'message' => (string) ($result['message'] ?? ''),
        ]);
        station_flash_set('error', 'Could not regenerate nginx include: ' . (string) ($result['message'] ?? 'unknown error'));
    }
    header('Location: admin-settings.php?tab=project-defaults');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'admin_docker_restart_all') {
    @set_time_limit(0);
    @ignore_user_abort(true);
    $r = station_admin_bulk_docker_projects('restart');
    station_log_event('admin.docker.bulk', ['action' => 'restart', 'ok' => !empty($r['ok'])]);
    station_flash_set(!empty($r['ok']) ? 'ok' : 'error', (string) ($r['message'] ?? 'Bulk restart finished.'));
    header('Location: admin-settings.php?tab=docker');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'admin_docker_recreate_all') {
    if (empty($_POST['confirm_recreate_all'])) {
        station_flash_set('error', 'Tick the confirmation box before force-recreating all container stacks.');
        header('Location: admin-settings.php?tab=docker');
        exit;
    }
    @set_time_limit(0);
    @ignore_user_abort(true);
    $r = station_admin_bulk_docker_projects('recreate');
    station_log_event('admin.docker.bulk', ['action' => 'recreate', 'ok' => !empty($r['ok'])]);
    station_flash_set(!empty($r['ok']) ? 'ok' : 'error', (string) ($r['message'] ?? 'Bulk recreate finished.'));
    header('Location: admin-settings.php?tab=docker');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'rename_station_dir') {
    $result = station_rename_station_dir((string) ($_POST['station_dir_name'] ?? ''));
    if (!empty($result['ok'])) {
        $dir = (string) ($result['dir'] ?? 'station');
        station_log_event('station.dir.renamed', ['dir' => $dir]);
        station_flash_set('ok', 'Station renamed. Reopen at /secure/' . $dir . '/');
        header('Location: ../' . rawurlencode($dir) . '/admin-settings.php?tab=general');
        exit;
    }
    station_flash_set('error', (string) ($result['message'] ?? 'Rename failed.'));
    header('Location: admin-settings.php?tab=general');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'update_station_name') {
    $name = (string) ($_POST['station_name'] ?? '');
    station_update_app_name($name)
        ? station_flash_set('ok', 'Station name updated.')
        : station_flash_set('error', 'Could not update station name.');
    station_log_event('station.name.updated', ['name' => $name]);
    header('Location: admin-settings.php?tab=general');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_save_section']) && (string) ($_POST['action'] ?? '') === '') {
    $settings = station_admin_merge_mega_form_post_into_settings($settings, $_POST, $accessModes, $dockerServices);
    $iconResult = station_apply_brand_icon_inputs($settings, $_POST, $_FILES);
    $settings = (array) ($iconResult['settings'] ?? $settings);
    if (empty($iconResult['ok'])) {
        $error = (string) ($iconResult['message'] ?? 'Could not save some icon uploads.');
    }

    $saved = station_save_admin_settings($settings);
    if ($saved && $error === '') {
        clearstatcache(true, station_admin_settings_path());
        if (station_normalize_server_infrastructure((string) ($settings['serverInfrastructure'] ?? '')) === 'nginx'
            && function_exists('station_write_nginx_projects_conf')) {
            station_write_nginx_projects_conf();
        }
        station_log_event('admin.settings.updated', ['tab' => $activeTab]);
        station_flash_set('ok', 'Settings saved successfully!');
        header('Location: admin-settings.php?tab=' . urlencode($activeTab));
        exit;
    }
    if ($saved && $error !== '') {
        clearstatcache(true, station_admin_settings_path());
        station_log_event('admin.settings.updated.partial', ['tab' => $activeTab, 'detail' => $error]);
        station_flash_set('ok', 'Other settings were saved. Issue with icon upload: ' . $error);
        header('Location: admin-settings.php?tab=' . urlencode($activeTab));
        exit;
    }
    if ($error === '') {
        $path = station_admin_settings_path();
        $error = 'Could not save settings to ' . $path . ' (check permissions on the data directory).';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('Admin Settings', 'Configure system settings, branding, Docker integration, and defaults.') ?>
</head>
<body class="station-body">
  <div class="dashboard-shell">
    <?= station_dashboard_nav_html('settings') ?>

    <main class="dashboard-main">
      <header class="dashboard-topbar">
        <div>
          <p class="dashboard-kicker">Owner Console</p>
          <h1 class="dashboard-heading">Admin Settings</h1>
          <p class="dashboard-subheading">Configure branding, project defaults, Docker services, integrations, and onboarding from one station-wide control panel.</p>
        </div>
      </header>

      <div class="settings-shell">
        <!-- Left Navigation Sidebar -->
        <nav class="settings-nav" aria-label="Admin settings sections">
      <a href="#general" class="settings-nav-item <?= $activeTab === 'general' ? 'active' : '' ?>" onclick="switchTab(event, 'general')">
        <span class="settings-nav-icon">🎨</span>
        <span>Branding</span>
      </a>
      <a href="#project-defaults" class="settings-nav-item <?= $activeTab === 'project-defaults' ? 'active' : '' ?>" onclick="switchTab(event, 'project-defaults')">
        <span class="settings-nav-icon">⚙️</span>
        <span>Projects</span>
      </a>
      <a href="#docker" class="settings-nav-item <?= $activeTab === 'docker' ? 'active' : '' ?>" onclick="switchTab(event, 'docker')">
        <span class="settings-nav-icon">🐳</span>
        <span>Docker</span>
      </a>
      <a href="#integrations" class="settings-nav-item <?= $activeTab === 'integrations' ? 'active' : '' ?>" onclick="switchTab(event, 'integrations')">
        <span class="settings-nav-icon">🔌</span>
        <span>Integrations</span>
      </a>
      <a href="#onboarding" class="settings-nav-item <?= $activeTab === 'onboarding' ? 'active' : '' ?>" onclick="switchTab(event, 'onboarding')">
        <span class="settings-nav-icon">👋</span>
        <span>Onboarding</span>
      </a>
      <a href="admin-host-health.php" class="settings-nav-item" style="margin-top: 12px; border-top: 1px solid var(--border, #e5e7eb); padding-top: 12px;">
        <span class="settings-nav-icon">📡</span>
        <span>Host health</span>
      </a>
      <a href="admin-factory-reset.php" class="settings-nav-item">
        <span class="settings-nav-icon">⚠️</span>
        <span>Factory reset</span>
      </a>
        </nav>

        <!-- Right Content Area -->
        <div class="settings-content">
      <?php if ($ok !== ''): ?>
        <div class="alert ok"><?= station_h($ok) ?></div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="alert error"><?= station_h($error) ?></div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="admin_mega_form" value="1">
        <input type="hidden" name="activeTab" value="<?= station_h($activeTab) ?>">

        <!-- GENERAL / BRANDING SETTINGS -->
        <div class="settings-section general<?= $activeTab === 'general' ? ' active' : '' ?>" id="general">
          <div class="settings-panel">
            <div class="settings-panel-head">
              <h1 class="settings-panel-heading">Branding & Appearance</h1>
              <p class="settings-panel-subtitle">Customize the look and feel of your deployment station with logos, colors, and text.</p>
            </div>

            <div class="settings-form-group">
              <div class="setting-item">
                <label class="setting-label">Station Heading</label>
                <input type="text" name="stationHeading" value="<?= station_h($settings['stationHeading'] ?? 'Deployment Station') ?>" placeholder="Deployment Station">
                <p class="setting-description">Main title displayed at the top of every page.</p>
              </div>

              <div class="setting-item">
                <label class="setting-label">Sub-heading</label>
                <input type="text" name="stationSubheading" value="<?= station_h($settings['stationSubheading'] ?? '') ?>" placeholder="Your projects, deployed.">
                <p class="setting-description">Optional tagline or description. Leave blank to show signed-in user.</p>
              </div>

              <div class="setting-item">
                <label class="setting-label">Theme Color</label>
                <input type="color" name="themeColor" value="<?= station_h(station_normalize_theme_color((string) ($settings['themeColor'] ?? '#2f7de2'))) ?>">
                <p class="setting-description">Primary brand color for buttons, links, and accents.</p>
              </div>
            </div>

            <h3 style="font-size: 15px; margin: 28px 0 16px;">Icons</h3>
            <div class="settings-form-group grid-2">
              <?php foreach (station_brand_icon_fields() as $iconKey => $iconField): ?>
                <?php
                  $urlField = (string) ($iconField['urlField'] ?? '');
                  $fileField = (string) ($iconField['fileField'] ?? '');
                  $iconMeta = $brandIconLabels[$iconKey] ?? ['label' => $iconKey, 'description' => 'Upload or link an app icon.'];
                  $currentIconUrl = trim((string) ($settings[$urlField] ?? ''));
                ?>
                <div class="setting-item">
                  <label class="setting-label"><?= station_h((string) $iconMeta['label']) ?></label>
                  <div class="icon-preview-row">
                    <?php if ($currentIconUrl !== ''): ?>
                      <div class="icon-preview">
                        <img class="icon-preview-image" src="<?= station_h(station_icon_url_with_cache_buster($currentIconUrl)) ?>" alt="<?= station_h((string) $iconMeta['label']) ?> preview">
                        <span class="icon-preview-label">Current</span>
                      </div>
                    <?php endif; ?>
                    <div style="flex: 1; min-width: 220px;">
                      <input type="text" name="<?= station_h($urlField) ?>" value="<?= station_h($currentIconUrl) ?>" placeholder="https://example.com/icon.png or /secure/station/icos/icon.png">
                      <input type="file" name="<?= station_h($fileField) ?>" accept="<?= station_h($iconUploadAccept) ?>">
                      <?php if ($currentIconUrl !== ''): ?>
                        <label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:12px;color:var(--bad,#c5221f);cursor:pointer;">
                          <input type="checkbox" name="remove_<?= station_h($urlField) ?>" value="1">
                          Remove this icon on save
                        </label>
                      <?php endif; ?>
                    </div>
                  </div>
                  <p class="setting-description"><?= station_h((string) $iconMeta['description']) ?> Leave the URL field empty (or tick the box above) to clear it on save.</p>
                </div>
              <?php endforeach; ?>
            </div>

            <button type="submit" name="admin_save_section" value="general" style="margin-top: 20px;">Save Branding Settings</button>
          </div>

          <!-- Station identity (moved off dashboard) -->
          <div class="settings-panel" style="margin-top: 24px;">
            <div class="settings-panel-head">
              <h1 class="settings-panel-heading">Station Identity</h1>
              <p class="settings-panel-subtitle">Display name shown in the title bar and the directory used in the URL.</p>
            </div>

            <div class="settings-form-group">
              <div class="setting-item">
                <label class="setting-label" for="station_name">Display Name</label>
                <p class="setting-description">Shown in the title bar and the welcome message. This is separate from the Station Heading above (which is the big page title).</p>
                <input id="station_name" type="text" value="<?= station_h($currentAppName) ?>" form="stationNameForm" name="station_name" required>
              </div>

              <div class="setting-item">
                <label class="setting-label" for="station_dir_name">Station Directory</label>
                <p class="setting-description">Current directory: <code>/secure/<?= station_h($currentStationDirName) ?>/</code>. Renaming changes the URL — the page reloads at the new path. Avoid <code>station</code> as a custom name only if you don't want the default.</p>
                <input id="station_dir_name" type="text" form="stationDirForm" name="station_dir_name" value="<?= station_h($currentStationDirName) ?>" placeholder="station" required>
              </div>
            </div>
          </div>
        </div>

        <!-- These tiny side-forms live OUTSIDE the main settings form so they
             don't get sent with branding/icons. They post their own actions
             and redirect back. -->

        <!-- PROJECT DEFAULTS -->
        <div class="settings-section project-defaults<?= $activeTab === 'project-defaults' ? ' active' : '' ?>" id="project-defaults">
          <div class="settings-panel">
            <div class="settings-panel-head">
              <h1 class="settings-panel-heading">Project Defaults</h1>
              <p class="settings-panel-subtitle">Set default behavior for newly created projects.</p>
            </div>

            <div class="settings-form-group">
              <div class="setting-item">
                <label class="setting-label">Production Web Server</label>
                <select name="serverInfrastructure">
                  <option value="apache" <?= station_normalize_server_infrastructure((string) ($settings['serverInfrastructure'] ?? 'apache')) === 'apache' ? 'selected' : '' ?>>Apache / LiteSpeed</option>
                  <option value="nginx" <?= station_normalize_server_infrastructure((string) ($settings['serverInfrastructure'] ?? 'apache')) === 'nginx' ? 'selected' : '' ?>>Nginx</option>
                </select>
                <p class="setting-description">Web server used in production environments.</p>
              </div>

              <?php if (station_normalize_server_infrastructure((string) ($settings['serverInfrastructure'] ?? 'apache')) === 'nginx'): ?>
                <div class="setting-item">
                  <label class="setting-label">Nginx Routing Snippet</label>
                  <textarea rows="18" readonly><?= station_h(station_nginx_project_route_snippet()) ?></textarea>
                  <p class="setting-description">Add this inside your Nginx server block so project URLs route through Station access checks. The included <code>projects.conf</code> file is regenerated automatically — you only need to paste this snippet once.</p>
                </div>

                <?php
                  $nginxIncludePath = station_nginx_include_path();
                  $dockerizedProjects = station_collect_dockerized_projects();
                  $nginxIncludeContents = is_file($nginxIncludePath) ? (string) (@file_get_contents($nginxIncludePath) ?: '') : '';
                ?>
                <div class="setting-item">
                  <label class="setting-label">Docker reverse-proxy routes</label>
                  <p class="setting-description">
                    Auto-managed include file: <code><?= station_h($nginxIncludePath) ?></code><br>
                    Currently routed projects: <strong><?= (int) count($dockerizedProjects) ?></strong>
                  </p>
                  <div style="margin: 8px 0 12px;">
                    <button type="submit" class="secondary-btn" name="action" value="rebuild_nginx_include" formnovalidate>Regenerate now</button>
                  </div>
                  <?php if ($nginxIncludeContents === ''): ?>
                    <p class="setting-description">No dockerized projects yet — the file will appear as soon as you configure one.</p>
                  <?php else: ?>
                    <pre class="code-block" style="max-height: 320px; overflow: auto;"><?= station_h($nginxIncludeContents) ?></pre>
                    <p class="setting-description">Updated whenever a docker project is configured, started, stopped, rebuilt, torn down, or when a project is deleted or archived. Users reach each running project at <code>/p/&lt;slug&gt;/</code>.</p>
                  <?php endif; ?>
                </div>

                <div class="setting-item">
                  <p class="setting-description" style="margin:0 0 10px;">When production mode is <strong>Nginx</strong>, Station runs the command below after every <code>projects.conf</code> rewrite (Docker saves, Regenerate now, uploads, etc.) so new <code>/p/&lt;slug&gt;/</code> routes go live. The default uses <code>sudo -n</code>; grant the PHP user passwordless access to the shown binaries or replace this with a wrapper script.</p>
                  <label class="setting-label" for="nginxReloadCommand">Reload shell command</label>
                  <textarea id="nginxReloadCommand" name="nginxReloadCommand" rows="3" spellcheck="false" class="code-block" style="width:100%;font-family:ui-monospace,monospace;font-size:12px;"><?= station_h(station_admin_resolved_shell_command($settings, 'nginxReloadCommand')) ?></textarea>
                  <p class="setting-description">Default after a fresh install matches the line below (nginx test + <code>nginx -s reload</code>). Clear the field only if you use a custom wrapper script.</p>

                  <details style="margin-top: 12px; border: 1px solid var(--line); border-radius: 12px; padding: 12px 16px; background: var(--panel-soft);">
                    <summary style="cursor:pointer; font-weight:600;">Passwordless sudo for the PHP user (nginx + optional Host health actions)</summary>
                    <div style="margin-top: 10px;">
                      <p>1. Identify the system user PHP runs as. Run on your server:</p>
                      <pre class="code-block">ps -eo user,comm | grep -E 'php-fpm|nginx|apache' | awk '{print $1}' | sort -u</pre>
                      <p>On Debian/Ubuntu it's usually <code>www-data</code>. On RHEL/Alpine/Docker images it's often <code>nginx</code>.</p>

                      <p>2. Run <code>sudo visudo -f /etc/sudoers.d/deployment-station</code> and paste the block below (replace <code>www-data</code> with the user from step 1). Each command path must match what runs on <em>your</em> host (<code>which nginx</code>, <code>systemctl cat php8.3-fpm</code>, etc.). There must be <strong>no stray characters</strong> after a command (e.g. <code>nginx -s reload</code> — not <code>reload?</code>).</p>
                      <pre class="code-block"># Deployment Station — passwordless for the PHP user (tune paths / unit names).
# Required so Station can reload nginx after projects.conf updates:
www-data ALL=(root) NOPASSWD: /usr/sbin/nginx -t
www-data ALL=(root) NOPASSWD: /usr/sbin/nginx -s reload

# Optional — same binaries Host health “Quick actions” uses by default:
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl reload nginx
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart docker
www-data ALL=(root) NOPASSWD: /usr/bin/tail -n 80 /var/log/nginx/error.log</pre>
                      <p>Add or remove <code>systemctl reload php…</code> lines for the PHP-FPM unit you actually use (only one needs to match). If your <code>nginx</code> or <code>systemctl</code> lives elsewhere, run <code>command -v nginx systemctl</code> and adjust the paths in <strong>both</strong> sudoers and Admin → Host health.</p>

                      <p>3. Verify nginx test, as the PHP user:</p>
                      <pre class="code-block">sudo -u www-data sudo -n /usr/sbin/nginx -t</pre>
                      <p>Expected output ends with <code>syntax is ok</code> and <code>test is successful</code>. If you see a password prompt, the sudoers line didn't match — recheck the user and the exact binary path.</p>

                      <p>4. Save <strong>Project defaults</strong> in Station after sudoers works. Each time routes are regenerated, Station will attempt this reload command (success or failure is logged).</p>

                      <p>5. Open <strong>Host health</strong> and save the helper one-liners once so quick actions match your host paths.</p>

                      <p>If PHP cannot run <code>sudo -n</code>, change the reload command to a script your web user may execute; the include file on disk is still updated on every regeneration.</p>
                    </div>
                  </details>
                </div>

                <div class="setting-item" style="margin-top: 18px;">
                  <label class="setting-label" for="nginxAuthRequestBasePath">Nginx <code>auth_request</code> URL prefix (optional)</label>
                  <input type="text" id="nginxAuthRequestBasePath" name="nginxAuthRequestBasePath" spellcheck="false" class="code-block" style="width:100%;max-width:520px;font-family:ui-monospace,monospace;font-size:13px;padding:8px 10px;" value="<?= station_h((string) ($settings['nginxAuthRequestBasePath'] ?? '')) ?>" placeholder="/station or /secure/station">
                  <p class="setting-description">Each <code>/p/&lt;slug&gt;/</code> block uses <code>auth_request <?= station_h(station_nginx_auth_request_base_path()) ?>/nginx-docker-auth.php?project=…</code>. This path must be the same prefix browsers use to reach Station PHP (e.g. <code>/station</code> if that is your URL). If it points at the wrong prefix, nginx may run the wrong handler (anonymous users can see the container; signed-in requests can error). You can also set env <code>STATION_AUTH_REQUEST_BASE</code> in the PHP-FPM pool. Save project defaults, then reload nginx.</p>
                </div>

                <div class="setting-item" style="margin-top: 18px;">
                  <label class="setting-label" for="nginxDockerUpstreamHostMode">Docker proxy: Host header to containers</label>
                  <select id="nginxDockerUpstreamHostMode" name="nginxDockerUpstreamHostMode">
                    <?php $upHost = (string) ($settings['nginxDockerUpstreamHostMode'] ?? 'preserve'); ?>
                    <option value="preserve" <?= $upHost !== 'loopback' ? 'selected' : '' ?>>Public hostname (<code>$host</code>) — default; best for many PHP/Laravel apps behind <code>/p/&lt;slug&gt;/</code></option>
                    <option value="loopback" <?= $upHost === 'loopback' ? 'selected' : '' ?>>Loopback (<code>127.0.0.1:&lt;port&gt;</code>) — use if the app only responds when Host matches a direct local hit</option>
                  </select>
                  <p class="setting-description">Regenerates <code>projects.conf</code> on save. Try <code>loopback</code> if the app only accepts requests whose <code>Host</code> matches <code>127.0.0.1:&lt;port&gt;</code>.</p>
                </div>

                <label class="feature-toggle" style="margin-top: 14px;">
                  <input type="checkbox" name="nginxDockerProxyStripCookies" <?= !empty($settings['nginxDockerProxyStripCookies']) ? 'checked' : '' ?>>
                  <div class="feature-toggle-content">
                    <span class="feature-toggle-title">Strip browser Cookie + Authorization to Docker upstream</span>
                    <span class="feature-toggle-desc">Clears the browser <code>Cookie</code> and <code>Authorization</code> headers on the hop to the container (after Station <code>auth_request</code>). Use when a stack misbehaves for signed-in Station users but works in a clean session. Turn off if the app needs those headers. Regenerates <code>projects.conf</code> when you save project defaults.</span>
                  </div>
                </label>
              <?php endif; ?>

              <div class="setting-item">
                <label class="setting-label">Default Visibility</label>
                <select name="defaultProjectVisibility">
                  <option value="private" <?= ($settings['defaultProjectVisibility'] ?? 'private') === 'private' ? 'selected' : '' ?>>Private (owner only)</option>
                  <option value="public" <?= ($settings['defaultProjectVisibility'] ?? 'private') === 'public' ? 'selected' : '' ?>>Public (anyone with link)</option>
                </select>
                <p class="setting-description">Access level for newly created projects.</p>
              </div>

              <div class="setting-item">
                <label class="setting-label">Default Access Mode</label>
                <select name="defaultProjectAccessMode">
                  <?php foreach ($accessModes as $value => $label): ?>
                    <option value="<?= station_h($value) ?>" <?= ($settings['defaultProjectAccessMode'] ?? 'admin') === $value ? 'selected' : '' ?>><?= station_h($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <p class="setting-description">Controls how projects can be accessed and edited.</p>
              </div>

              <div class="setting-item">
                <label class="setting-label">Activity Log Size</label>
                <input type="number" name="auditLogLimit" min="50" max="200000" step="50" value="<?= station_h((string) station_audit_log_limit($settings)) ?>">
                <p class="setting-description">Number of activity log entries to retain.</p>
              </div>
            </div>

            <h3 style="font-size: 15px; margin: 24px 0 16px;">Project Features</h3>
            <div class="settings-form-group">
              <div class="setting-item">
                <label class="setting-label">Starter Templates</label>
                <a class="quick-link" href="template-manager.php">Manage custom starter templates</a>
                <p class="setting-description">Create reusable project starters for the dashboard project creator.</p>
              </div>

              <label class="feature-toggle">
                <input type="checkbox" name="allowPublicProjects" <?= !empty($settings['allowPublicProjects']) ? 'checked' : '' ?>>
                <div class="feature-toggle-content">
                  <span class="feature-toggle-title">Allow Public Projects</span>
                  <span class="feature-toggle-desc">Let project owners mark projects as publicly accessible.</span>
                </div>
              </label>
            </div>

            <button type="submit" name="admin_save_section" value="project-defaults" style="margin-top: 20px;">Save Project Defaults</button>
          </div>
        </div>

        <!-- DOCKER SETTINGS -->
        <div class="settings-section docker<?= $activeTab === 'docker' ? ' active' : '' ?>" id="docker">
          <div class="settings-panel">
            <div class="settings-panel-head">
              <h1 class="settings-panel-heading">Docker Integration</h1>
              <p class="settings-panel-subtitle">Configure Docker container deployment, services, and database options.</p>
            </div>

            <?php
              $dockerDiagnostics = station_docker_runtime_diagnostics();
              $engineOk = !empty($dockerDiagnostics['engineOk']);
              $socketExists = !empty($dockerDiagnostics['socketExists']);
              $socketWritable = !empty($dockerDiagnostics['socketWritable']);
              $isSnap = !empty($dockerDiagnostics['binaryIsSnap']);
              $hostFleetSnap = station_admin_host_config_snapshot();
              $fleetRows = station_admin_containerized_project_rows();
              $globalPsLines = station_docker_global_ps();
            ?>
            <?php if ($isSnap): ?>
              <div class="docker-diag-card is-bad" style="margin-bottom: 14px;">
                <div class="docker-diag-header">
                  <strong>⚠ Docker is installed via snap</strong>
                  <span class="docker-diag-meta">Not recommended for PHP-FPM</span>
                </div>
                <p style="margin: 8px 0 6px;">Snap-confined docker refuses to run when <code>$HOME</code> is outside <code>/home</code>, which is the default for the <code><?= station_h($dockerDiagnostics['phpUser']) ?></code> user (<code>HOME=<?= station_h($dockerDiagnostics['inheritedHome']) ?: '/var/www' ?></code>). You'll likely see:</p>
                <pre class="code-mini">cannot create snap home dir: mkdir /var/www/snap: permission denied
Sorry, home directories outside of /home needs configuration.</pre>
                <p style="margin: 10px 0 6px;"><strong>Recommended fix — replace snap docker with the apt package:</strong></p>
                <pre class="code-mini">sudo snap remove docker
sudo apt update
sudo apt install -y docker.io docker-compose-plugin
# If docker-compose-plugin is unavailable in your repo, use either:
#   sudo apt install -y docker-compose-v2
# or install the static v2 binary (see "Compose binary path" below) from:
#   https://github.com/docker/compose/releases/latest
sudo systemctl enable --now docker
sudo usermod -aG docker <?= station_h($dockerDiagnostics['phpUser']) ?>

sudo systemctl restart php*-fpm
# then refresh this page — diagnostics should turn green</pre>
                <p style="margin: 10px 0 0;"><strong>Or, keep snap docker</strong> by allowing the station's data dir as a snap home:</p>
                <pre class="code-mini">sudo snap set system homedirs=<?= station_h(dirname($dockerDiagnostics['runtimeHome'])) ?></pre>
                <p style="margin: 6px 0 0; font-size: 12px; opacity: 0.85;">The station now isolates docker's home at <code><?= station_h($dockerDiagnostics['runtimeHome']) ?></code> instead of <code><?= station_h($dockerDiagnostics['inheritedHome']) ?: '/var/www' ?></code>, which fixes most non-snap setups automatically.</p>
              </div>
            <?php endif; ?>
            <div class="docker-diag-card <?= $engineOk ? 'is-ok' : 'is-bad' ?>">
              <div class="docker-diag-header">
                <strong><?= $engineOk ? '✓ Docker engine reachable from PHP' : '⚠ Docker engine not reachable from PHP' ?></strong>
                <span class="docker-diag-meta"><?= $engineOk ? 'v' . station_h($dockerDiagnostics['engineVersion'] ?: 'unknown') : 'Fix below before enabling Docker' ?></span>
              </div>
              <dl class="docker-diag-list">
                <dt>Resolved docker binary</dt><dd><code><?= station_h($dockerDiagnostics['binary']) ?></code><?= $isSnap ? ' <span style="color:#b45309;">(snap)</span>' : '' ?></dd>
                <dt>Compose command</dt><dd><code><?= station_h($dockerDiagnostics['composeCommand']) ?></code><?php if (!empty($dockerDiagnostics['composeOk'])): ?> <span style="color:#15803d;">(ok)</span><?php else: ?> <span style="color:#b45309;">(failed)</span><?php endif; ?></dd>
                <?php if (empty($dockerDiagnostics['composeOk']) && trim((string) ($dockerDiagnostics['composeVersionOutput'] ?? '')) !== ''): ?>
                  <dt>Compose probe output</dt><dd><pre class="code-mini"><?= station_h($dockerDiagnostics['composeVersionOutput']) ?></pre></dd>
                <?php endif; ?>
                <dt>PHP user</dt><dd><code><?= station_h($dockerDiagnostics['phpUser']) ?></code></dd>
                <dt>Docker socket</dt><dd>
                  <?php if (!$socketExists): ?>
                    <code>/var/run/docker.sock</code> not found — is dockerd running on this host?
                  <?php elseif (!$socketWritable): ?>
                    <code>/var/run/docker.sock</code> exists but PHP user (<code><?= station_h($dockerDiagnostics['phpUser']) ?></code>) cannot write to it. Add the user to the <code>docker</code> group: <code>sudo usermod -aG docker <?= station_h($dockerDiagnostics['phpUser']) ?> &amp;&amp; sudo systemctl restart php*-fpm apache2 nginx</code>
                  <?php else: ?>
                    <code>/var/run/docker.sock</code> readable &amp; writable.
                  <?php endif; ?>
                </dd>
                <dt>Effective PATH</dt><dd><code><?= station_h($dockerDiagnostics['runtimePath']) ?></code></dd>
                <dt>HOME used for docker</dt><dd><code><?= station_h($dockerDiagnostics['runtimeHome']) ?></code><?php if (($dockerDiagnostics['inheritedHome'] ?? '') !== '' && $dockerDiagnostics['inheritedHome'] !== $dockerDiagnostics['runtimeHome']): ?> <span style="opacity:0.7;">(was <code><?= station_h($dockerDiagnostics['inheritedHome']) ?></code>)</span><?php endif; ?></dd>
                <?php if (!$engineOk && trim($dockerDiagnostics['engineOutput']) !== ''): ?>
                  <dt>Last output</dt><dd><pre class="code-mini"><?= station_h($dockerDiagnostics['engineOutput']) ?></pre></dd>
                <?php endif; ?>
              </dl>
              <?php if (!$engineOk && !$isSnap): ?>
                <p class="docker-diag-hint">Most often this means PHP-FPM's PATH doesn't include where docker lives, or the web user isn't in the <code>docker</code> group. Set "Docker Binary Path" below to the absolute path (e.g. <code>/usr/local/bin/docker</code>) and save, then refresh this page.</p>
              <?php endif; ?>
            </div>

            <div class="settings-panel" style="margin-top: 22px;">
              <div class="settings-panel-head">
                <h2 class="settings-panel-heading" style="font-size: 18px;">Host paths &amp; web server</h2>
                <p class="settings-panel-subtitle">What this Station instance uses on disk and for nginx routing (read-only).</p>
              </div>
              <dl class="docker-diag-list">
                <dt><code>STATION_DATA_DIR</code> (env)</dt>
                <dd><?= $hostFleetSnap['stationDataDirEnv'] !== '' ? '<code>' . station_h($hostFleetSnap['stationDataDirEnv']) . '</code>' : '<span style="opacity:0.75;">(not set — using default)</span>' ?></dd>
                <dt>Data directory</dt>
                <dd><code><?= station_h($hostFleetSnap['stationDataDir']) ?></code></dd>
                <dt>Projects directory</dt>
                <dd><code><?= station_h($hostFleetSnap['projectsDir']) ?></code></dd>
                <dt>Station PHP tree</dt>
                <dd><code><?= station_h($hostFleetSnap['stationPhpDir']) ?></code></dd>
                <dt>Web base path (<code>SCRIPT_NAME</code>)</dt>
                <dd><code><?= station_h($hostFleetSnap['webBasePath'] !== '' ? $hostFleetSnap['webBasePath'] : '/') ?></code></dd>
                <dt>Nginx <code>auth_request</code> URL prefix</dt>
                <dd><?php $authSaved = trim((string) ($hostFleetSnap['nginxAuthRequestBasePath'] ?? '')); ?>
                  <?php if ($authSaved !== ''): ?>Override <code><?= station_h($authSaved) ?></code> — <?php endif; ?>effective <code><?= station_h((string) ($hostFleetSnap['nginxAuthRequestResolved'] ?? '')) ?></code> (used in <code>projects.conf</code>)</dd>
                <dt>Production web server (admin default)</dt>
                <dd><code><?= station_h($hostFleetSnap['serverInfrastructure']) ?></code></dd>
                <dt>Nginx include file</dt>
                <dd><code><?= station_h($hostFleetSnap['nginxIncludePath']) ?></code></dd>
                <dt>Nginx reload after route updates</dt>
                <dd><?= !empty($hostFleetSnap['nginxReloadAfterRoutes']) ? 'Yes (Nginx mode)' : 'No (not Nginx)' ?><?= !empty($hostFleetSnap['nginxReloadCommandConfigured']) ? ' <span style="opacity:0.8;">(custom reload command in admin)</span>' : '' ?></dd>
              </dl>
            </div>

            <div class="settings-panel" style="margin-top: 22px;">
              <div class="settings-panel-head">
                <h2 class="settings-panel-heading" style="font-size: 18px;">Containerized projects (Station)</h2>
                <p class="settings-panel-subtitle">Projects with Docker enabled and a host port. Status comes from <code>docker compose ps</code> for each stack.</p>
              </div>
              <?php if ($fleetRows === []): ?>
                <p class="setting-description">No containerized projects yet.</p>
              <?php else: ?>
                <div style="overflow-x: auto;">
                  <table style="width:100%; border-collapse:collapse; font-size: 13px;">
                    <thead>
                      <tr style="text-align:left; border-bottom:1px solid var(--border,#e2e8f0);">
                        <th style="padding:8px 6px;">Project</th>
                        <th style="padding:8px 6px;">State</th>
                        <th style="padding:8px 6px;">Host → app port</th>
                        <th style="padding:8px 6px;">Compose file</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($fleetRows as $row): ?>
                        <tr style="border-bottom:1px solid var(--border,#f1f5f9);">
                          <td style="padding:8px 6px;">
                            <a href="docker-config.php?project=<?= urlencode($row['slug']) ?>"><?= station_h($row['slug']) ?></a>
                          </td>
                          <td style="padding:8px 6px;"><code><?= station_h($row['state']) ?></code></td>
                          <td style="padding:8px 6px;"><code>127.0.0.1:<?= (int) $row['hostPort'] ?> → :<?= (int) $row['appPort'] ?></code></td>
                          <td style="padding:8px 6px;"><code style="word-break:break-all;"><?= station_h($row['composePath']) ?></code></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>

            <div class="settings-panel" style="margin-top: 22px;">
              <div class="settings-panel-head">
                <h2 class="settings-panel-heading" style="font-size: 18px;">All Docker containers on this host</h2>
                <p class="settings-panel-subtitle">Output of <code>docker ps -a</code> (not only Station projects). <?php if (!empty($globalPsLines['truncated'])): ?><strong>Truncated</strong> for display.<?php endif; ?></p>
              </div>
              <pre class="code-mini" style="max-height: 320px; overflow: auto; white-space: pre; font-size: 12px;"><?= station_h($globalPsLines['output']) ?></pre>
            </div>

            <div class="settings-panel" style="margin-top: 22px;">
              <div class="settings-panel-head">
                <h2 class="settings-panel-heading" style="font-size: 18px;">Bulk operations</h2>
                <p class="settings-panel-subtitle">Runs sequentially for every <strong>containerized</strong> project, refreshes <code>docker-compose.yml</code> from saved settings, then regenerates the nginx routes file.</p>
              </div>
              <p class="setting-description" style="margin-bottom: 12px;"><strong>Restart all</strong> runs <code>docker compose restart</code> per project (containers only, no image rebuild). <strong>Force recreate all</strong> runs <code>docker compose up -d --force-recreate</code> — still no image rebuild unless you rebuild per project; named volumes (e.g. databases) are kept.</p>
              <div style="display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end;">
                <?php /* Do not nest <form> inside the mega-form: browsers close the outer form and orphan Docker fields. */ ?>
                <button type="submit" class="secondary-btn" form="admin-docker-restart-all-form" <?= !station_docker_enabled() || !$engineOk ? 'disabled' : '' ?>>Restart all container stacks</button>
                <div>
                  <label style="display:flex; align-items:center; gap:8px; font-size:13px; margin-bottom:8px;">
                    <input type="checkbox" name="confirm_recreate_all" value="1" form="admin-docker-recreate-all-form">
                    I want to force-recreate every stack
                  </label>
                  <button type="submit" class="secondary-btn" form="admin-docker-recreate-all-form" <?= !station_docker_enabled() || !$engineOk ? 'disabled' : '' ?>>Force recreate all</button>
                </div>
              </div>
            </div>

            <h3 style="font-size: 15px; margin: 0 0 16px;">Docker Deployment</h3>
            <p class="setting-description" style="margin-bottom: 14px;">Running stacks are exposed at site-root URLs like <code>/p/&lt;slug&gt;/</code> (for example <code>https://your.domain/p/myapp/</code>), not under the Station PHP folder. Nginx reads the generated include from your <code>server</code> block.</p>
            <label class="feature-toggle">
              <input type="checkbox" name="dockerEnabled" <?= !empty($dockerSettings['enabled']) ? 'checked' : '' ?>>
              <div class="feature-toggle-content">
                <span class="feature-toggle-title">Enable Docker Container Deployment</span>
                <span class="feature-toggle-desc">Allow projects to be deployed inside Docker containers with automatic service orchestration.</span>
              </div>
            </label>

            <div class="settings-form-group" style="margin-top: 18px;">
              <div class="setting-item">
                <label class="setting-label" for="dockerBinaryPath">Docker Binary Path</label>
                <input id="dockerBinaryPath" type="text" name="dockerBinaryPath" value="<?= station_h((string) ($dockerSettings['dockerBinaryPath'] ?? '')) ?>" placeholder="auto-detect (e.g. /usr/local/bin/docker)" autocomplete="off">
                <p class="setting-description">Leave blank to auto-detect. Override when PHP-FPM can't find docker on its PATH — run <code>which docker</code> in your terminal to get the right value (commonly <code>/usr/local/bin/docker</code>, <code>/usr/bin/docker</code>, or <code>/snap/bin/docker</code>).</p>
              </div>
              <div class="setting-item" style="margin-top: 14px;">
                <label class="setting-label" for="composeBinaryPath">Compose binary path (optional)</label>
                <input id="composeBinaryPath" type="text" name="composeBinaryPath" value="<?= station_h((string) ($dockerSettings['composeBinaryPath'] ?? '')) ?>" placeholder="auto-detect (e.g. /usr/local/bin/docker-compose)" autocomplete="off">
                <p class="setting-description">Use when <code>docker compose</code> is not installed but the Compose v2 binary exists elsewhere — for example the static build from GitHub (install to <code>/usr/local/bin/docker-compose</code> and chmod +x), or Docker’s plugin path <code>/usr/libexec/docker/cli-plugins/docker-compose</code>. Leave blank to try <code>docker compose</code> first, then auto-detect <code>docker-compose</code>.</p>
                <p class="setting-description" style="margin-top: 8px;"><strong>Static install (amd64):</strong> <code>sudo curl -fsSL &quot;https://github.com/docker/compose/releases/latest/download/docker-compose-linux-x86_64&quot; -o /usr/local/bin/docker-compose &amp;&amp; sudo chmod +x /usr/local/bin/docker-compose</code> — use <code>docker-compose-linux-aarch64</code> on ARM64.</p>
              </div>
            </div>

            <div class="settings-form-group" style="margin-top: 24px;">
              <div class="setting-item">
                <label class="setting-label">Docker Compose Version</label>
                <select name="composeVersion">
                  <option value="3.9" <?= ($dockerSettings['composeVersion'] ?? '3.9') === '3.9' ? 'selected' : '' ?>>3.9</option>
                  <option value="3.8" <?= ($dockerSettings['composeVersion'] ?? '3.9') === '3.8' ? 'selected' : '' ?>>3.8</option>
                </select>
                <p class="setting-description">Docker Compose format version for generated files.</p>
              </div>

              <div class="setting-item">
                <label class="setting-label">Default Database</label>
                <select name="defaultDatabase">
                  <option value="mysql" <?= ($dockerSettings['defaultDatabase'] ?? 'mysql') === 'mysql' ? 'selected' : '' ?>>MySQL 8.0</option>
                  <option value="postgres" <?= ($dockerSettings['defaultDatabase'] ?? 'mysql') === 'postgres' ? 'selected' : '' ?>>PostgreSQL 15</option>
                  <option value="mongodb" <?= ($dockerSettings['defaultDatabase'] ?? 'mysql') === 'mongodb' ? 'selected' : '' ?>>MongoDB 7.0</option>
                  <option value="none" <?= ($dockerSettings['defaultDatabase'] ?? 'mysql') === 'none' ? 'selected' : '' ?>>None</option>
                </select>
                <p class="setting-description">Default database service for new projects.</p>
              </div>
            </div>

            <h3 style="font-size: 15px; margin: 28px 0 16px;">Available Services</h3>
            <p class="setting-description" style="margin-bottom: 16px;">Enable/disable services available for project deployment:</p>

            <div class="docker-settings-grid">
              <?php foreach ($dockerServices as $serviceKey => $serviceConfig): ?>
                <label class="docker-service-card <?= !empty($dockerSettings['services'][$serviceKey]) ? 'enabled' : '' ?>">
                  <div style="display: flex; align-items: flex-start; gap: 10px;">
                    <input type="checkbox" name="service_<?= station_h($serviceKey) ?>"
                      <?= !empty($dockerSettings['services'][$serviceKey]) ? 'checked' : '' ?>
                      style="margin-top: 2px; cursor: pointer;">
                    <div style="flex: 1;">
                      <div class="service-name"><?= station_h($serviceConfig['icon']) ?> <?= station_h($serviceConfig['name']) ?></div>
                      <div class="service-desc"><?= station_h($serviceConfig['description']) ?></div>
                    </div>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>

            <button type="submit" name="admin_save_section" value="docker" style="margin-top: 24px;">Save Docker Settings</button>
          </div>
        </div>

        <!-- INTEGRATIONS -->
        <div class="settings-section integrations<?= $activeTab === 'integrations' ? ' active' : '' ?>" id="integrations">
          <div class="settings-panel">
            <div class="settings-panel-head">
              <h1 class="settings-panel-heading">Integrations</h1>
              <p class="settings-panel-subtitle">Enable or disable optional integrations and features.</p>
            </div>

            <div class="settings-form-group">
              <label class="feature-toggle">
                <input type="checkbox" name="githubEnabled" <?= !empty($settings['githubEnabled']) ? 'checked' : '' ?>>
                <div class="feature-toggle-content">
                  <span class="feature-toggle-title">GitHub Integration</span>
                  <span class="feature-toggle-desc">Enable importing and linking projects from GitHub repositories.</span>
                </div>
              </label>

              <label class="feature-toggle">
                <input type="checkbox" name="vscodeEnabled" <?= !empty($settings['vscodeEnabled']) ? 'checked' : '' ?>>
                <div class="feature-toggle-content">
                  <span class="feature-toggle-title">VS Code Remote</span>
                  <span class="feature-toggle-desc">Allow opening projects directly in VS Code via remote development.</span>
                </div>
              </label>

              <label class="feature-toggle">
                <input type="checkbox" name="chatgptEnabled" <?= !empty($settings['chatgptEnabled']) ? 'checked' : '' ?>>
                <div class="feature-toggle-content">
                  <span class="feature-toggle-title">ChatGPT</span>
                  <span class="feature-toggle-desc">Integrate ChatGPT for AI-assisted development features.</span>
                </div>
              </label>

              <label class="feature-toggle">
                <input type="checkbox" name="codexEnabled" <?= !empty($settings['codexEnabled']) ? 'checked' : '' ?>>
                <div class="feature-toggle-content">
                  <span class="feature-toggle-title">GitHub Codex</span>
                  <span class="feature-toggle-desc">Enable AI code completion and generation features.</span>
                </div>
              </label>
            </div>

            <button type="submit" name="admin_save_section" value="integrations" style="margin-top: 24px;">Save Integration Settings</button>
          </div>
        </div>

        <!-- ONBOARDING -->
        <div class="settings-section onboarding<?= $activeTab === 'onboarding' ? ' active' : '' ?>" id="onboarding">
          <div class="settings-panel">
            <div class="settings-panel-head">
              <h1 class="settings-panel-heading">Onboarding</h1>
              <p class="settings-panel-subtitle">Configure first-time user experience and requirements.</p>
            </div>

            <div class="settings-form-group">
              <label class="feature-toggle">
                <input type="checkbox" name="onboardingRequired" <?= !empty($settings['onboardingRequired']) ? 'checked' : '' ?>>
                <div class="feature-toggle-content">
                  <span class="feature-toggle-title">Require Onboarding</span>
                  <span class="feature-toggle-desc">New users must complete onboarding tutorial on first login.</span>
                </div>
              </label>
            </div>

            <button type="submit" name="admin_save_section" value="onboarding" style="margin-top: 24px;">Save Onboarding Settings</button>
          </div>
        </div>

          </form>

          <!-- Stand-alone POST targets for Docker bulk actions (must live outside the mega-form). -->
          <form id="admin-docker-restart-all-form" method="post" action="admin-settings.php?tab=docker" style="display:none;" aria-hidden="true">
            <input type="hidden" name="action" value="admin_docker_restart_all">
          </form>
          <form id="admin-docker-recreate-all-form" method="post" action="admin-settings.php?tab=docker" style="display:none;" aria-hidden="true">
            <input type="hidden" name="action" value="admin_docker_recreate_all">
          </form>

          <!-- Tiny side-forms for station identity (kept out of the multipart
               icon form so file uploads can't accidentally affect them, and
               vice versa). Buttons inside their own form via the `form` attr. -->
          <form id="stationNameForm" method="post" action="admin-settings.php?tab=general" style="margin: 14px 0;">
            <input type="hidden" name="action" value="update_station_name">
            <button type="submit" class="secondary-btn">Save Display Name</button>
            <span class="setting-description" style="margin-left: 10px;">Updates the value in the "Display Name" field above.</span>
          </form>

          <form id="stationDirForm" method="post" action="admin-settings.php?tab=general" style="margin: 6px 0 20px;"
                onsubmit="return confirm('Rename the station directory? The URL will change and this page will reload at the new path.');">
            <input type="hidden" name="action" value="rename_station_dir">
            <button type="submit" class="secondary-btn">Rename Station Directory</button>
            <span class="setting-description" style="margin-left: 10px;">Uses the value in the "Station Directory" field above. The page will reload at the new <code>/secure/&lt;new&gt;/</code> path.</span>
          </form>
        </div>
      </div>
    </main>
  </div>

  <script>
    function switchTab(e, tabName) {
      e.preventDefault();

      document.querySelectorAll('.settings-section').forEach(el => {
        el.classList.remove('active');
      });

      document.querySelectorAll('.settings-nav-item').forEach(el => {
        el.classList.remove('active');
      });

      const section = document.getElementById(tabName);
      if (section) {
        section.classList.add('active');
      }

      const tabInput = document.querySelector('input[name="activeTab"]');
      if (tabInput) {
        tabInput.value = tabName;
      }

      const navItem = e.currentTarget || e.target.closest('.settings-nav-item');
      if (navItem) {
        navItem.classList.add('active');
      }

      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', 'admin-settings.php?tab=' + encodeURIComponent(tabName));
      }
    }

    (function syncTabFromHash() {
      var allowed = { general: 1, 'project-defaults': 1, docker: 1, integrations: 1, onboarding: 1 };
      function apply() {
        var m = /^#(general|project-defaults|docker|integrations|onboarding)$/.exec(location.hash || '');
        if (!m || !allowed[m[1]]) {
          return;
        }
        var tabInput = document.querySelector('input[name="activeTab"]');
        if (tabInput) {
          tabInput.value = m[1];
        }
      }
      window.addEventListener('hashchange', apply);
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', apply);
      } else {
        apply();
      }
    })();
  </script>

  <?= station_dashboard_nav_script_html() ?>
  <?= station_clipboard_fab_html() ?>
  <?= station_pwa_register_html() ?>
</body>
</html>
