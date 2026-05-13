<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/docker.php';

station_require_owner();
$settings = station_admin_settings();
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

$activeTab = (string) ($_POST['activeTab'] ?? $_GET['tab'] ?? 'general');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Process form inputs based on active tab
    switch ($activeTab) {
        case 'general':
            $settings['stationHeading'] = trim((string) ($_POST['stationHeading'] ?? 'Deployment Station')) ?: 'Deployment Station';
            $settings['stationSubheading'] = trim((string) ($_POST['stationSubheading'] ?? ''));
            $settings['themeColor'] = station_normalize_theme_color((string) ($_POST['themeColor'] ?? '#2f7de2'));

            $iconResult = station_apply_brand_icon_inputs($settings, $_POST, $_FILES);
            if (empty($iconResult['ok'])) {
                $settings = (array) ($iconResult['settings'] ?? $settings);
                $error = (string) ($iconResult['message'] ?? 'Could not save uploaded icons.');
            } else {
                $settings = (array) ($iconResult['settings'] ?? $settings);
            }
            break;

        case 'project-defaults':
            $settings['serverInfrastructure'] = station_normalize_server_infrastructure((string) ($_POST['serverInfrastructure'] ?? ($settings['serverInfrastructure'] ?? 'apache')));
            $settings['defaultProjectVisibility'] = ((string) ($_POST['defaultProjectVisibility'] ?? 'private')) === 'public' ? 'public' : 'private';
            $defaultMode = (string) ($_POST['defaultProjectAccessMode'] ?? 'admin');
            $settings['defaultProjectAccessMode'] = isset($accessModes[$defaultMode]) ? $defaultMode : 'admin';
            $settings['auditLogLimit'] = station_normalize_audit_log_limit($_POST['auditLogLimit'] ?? ($settings['auditLogLimit'] ?? 50));
            $settings['allowPublicProjects'] = isset($_POST['allowPublicProjects']);
            break;

        case 'integrations':
            $settings['githubEnabled'] = isset($_POST['githubEnabled']);
            $settings['vscodeEnabled'] = isset($_POST['vscodeEnabled']);
            $settings['chatgptEnabled'] = isset($_POST['chatgptEnabled']);
            $settings['codexEnabled'] = isset($_POST['codexEnabled']);
            break;

        case 'docker':
            $dockerSettings['enabled'] = isset($_POST['dockerEnabled']);
            $dockerSettings['composeVersion'] = (string) ($_POST['composeVersion'] ?? '3.9');
            $dockerSettings['defaultDatabase'] = (string) ($_POST['defaultDatabase'] ?? 'mysql');
            $dockerSettings['dockerBinaryPath'] = trim((string) ($_POST['dockerBinaryPath'] ?? ''));

            // Enable/disable services
            foreach ($dockerServices as $serviceKey => $serviceConfig) {
                $dockerSettings['services'][$serviceKey] = isset($_POST['service_' . $serviceKey]);
            }

            $settings['dockerSettings'] = $dockerSettings;
            break;

        case 'onboarding':
            $settings['onboardingRequired'] = isset($_POST['onboardingRequired']);
            break;
    }

    if ($error === '' && station_save_admin_settings($settings)) {
        station_log_event('admin.settings.updated', ['tab' => $activeTab]);
        station_flash_set('ok', 'Settings saved successfully!');
        header('Location: admin-settings.php?tab=' . urlencode($activeTab));
        exit;
    }
    if ($error === '') {
        $error = 'Could not save settings.';
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
                        <img class="icon-preview-image" src="<?= station_h($currentIconUrl) ?>" alt="<?= station_h((string) $iconMeta['label']) ?> preview">
                        <span class="icon-preview-label">Current</span>
                      </div>
                    <?php endif; ?>
                    <div style="flex: 1; min-width: 220px;">
                      <input type="text" name="<?= station_h($urlField) ?>" value="<?= station_h($currentIconUrl) ?>" placeholder="https://example.com/icon.png or /secure/station/icos/icon.png">
                      <input type="file" name="<?= station_h($fileField) ?>" accept="<?= station_h($iconUploadAccept) ?>">
                    </div>
                  </div>
                  <p class="setting-description"><?= station_h((string) $iconMeta['description']) ?></p>
                </div>
              <?php endforeach; ?>
            </div>

            <button type="submit" style="margin-top: 20px;">Save Branding Settings</button>
          </div>
        </div>

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
                  <textarea rows="12" readonly><?= station_h(station_nginx_project_route_snippet()) ?></textarea>
                  <p class="setting-description">Add this inside your Nginx server block so project URLs route through Station access checks.</p>
                </div>
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

            <button type="submit" style="margin-top: 20px;">Save Project Defaults</button>
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
            ?>
            <div class="docker-diag-card <?= $engineOk ? 'is-ok' : 'is-bad' ?>">
              <div class="docker-diag-header">
                <strong><?= $engineOk ? '✓ Docker engine reachable from PHP' : '⚠ Docker engine not reachable from PHP' ?></strong>
                <span class="docker-diag-meta"><?= $engineOk ? 'v' . station_h($dockerDiagnostics['engineVersion'] ?: 'unknown') : 'Fix below before enabling Docker' ?></span>
              </div>
              <dl class="docker-diag-list">
                <dt>Resolved docker binary</dt><dd><code><?= station_h($dockerDiagnostics['binary']) ?></code></dd>
                <dt>Compose command</dt><dd><code><?= station_h($dockerDiagnostics['composeCommand']) ?></code></dd>
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
                <?php if (!$engineOk && trim($dockerDiagnostics['engineOutput']) !== ''): ?>
                  <dt>Last output</dt><dd><pre class="code-mini"><?= station_h($dockerDiagnostics['engineOutput']) ?></pre></dd>
                <?php endif; ?>
              </dl>
              <?php if (!$engineOk): ?>
                <p class="docker-diag-hint">Most often this means PHP-FPM's PATH doesn't include where docker lives, or the web user isn't in the <code>docker</code> group. Set "Docker Binary Path" below to the absolute path (e.g. <code>/usr/local/bin/docker</code>) and save, then refresh this page.</p>
              <?php endif; ?>
            </div>

            <h3 style="font-size: 15px; margin: 0 0 16px;">Docker Deployment</h3>
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

            <button type="submit" style="margin-top: 24px;">Save Docker Settings</button>
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

            <button type="submit" style="margin-top: 24px;">Save Integration Settings</button>
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

            <button type="submit" style="margin-top: 24px;">Save Onboarding Settings</button>
          </div>
        </div>

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
  </script>

  <?= station_dashboard_nav_script_html() ?>
  <?= station_clipboard_fab_html() ?>
  <?= station_pwa_register_html() ?>
</body>
</html>
