<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/docker.php';

station_require_builder();
$user = station_current_user();
$username = station_current_username();
$dockerServices = station_docker_services();
$dockerSettings = station_docker_settings();

$projectSlug = station_safe_name((string) ($_GET['project'] ?? $_POST['project'] ?? ''));
if ($projectSlug === '' || !station_project_exists($projectSlug)) {
    station_flash_set('error', 'Project not found.');
    header('Location: station.php');
    exit;
}

$projectMeta = null;
foreach (station_list_projects() as $candidateProject) {
    if ((string) ($candidateProject['slug'] ?? '') === $projectSlug) {
        $projectMeta = $candidateProject;
        break;
    }
}

if (!$projectMeta) {
    station_flash_set('error', 'Project not found.');
    header('Location: station.php');
    exit;
}

$projectOwner = station_safe_name((string) ($projectMeta['owner'] ?? ''));
$isProjectOwner = $projectOwner === station_safe_name($username);
if (!station_is_owner($user) && !station_is_admin($user) && !$isProjectOwner) {
    station_flash_set('error', 'You do not have access to configure Docker for that project.');
    header('Location: station.php');
    exit;
}

if (!station_docker_enabled()) {
    station_flash_set('error', 'Docker deployment is disabled. Enable it in Admin Settings first.');
    header('Location: project-settings.php?project=' . urlencode($projectSlug));
    exit;
}

$error = '';
$ok = station_flash_get('ok');
$existing = station_project_settings($projectSlug);
$projectConfig = isset($existing['docker']) && is_array($existing['docker']) ? $existing['docker'] : [
    'services' => [],
    'credentials' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newConfig = ['services' => [], 'credentials' => []];
    foreach ($dockerServices as $serviceKey => $serviceConfig) {
        if (isset($_POST['service_' . $serviceKey])) {
            $newConfig['services'][$serviceKey] = true;
            $newConfig['credentials'][$serviceKey] = [
                'version' => (string) ($_POST['service_' . $serviceKey . '_version'] ?? $serviceConfig['defaultVersion']),
                'rootPassword' => (string) ($_POST['service_' . $serviceKey . '_root_password'] ?? ''),
                'user' => (string) ($_POST['service_' . $serviceKey . '_user'] ?? ''),
                'password' => (string) ($_POST['service_' . $serviceKey . '_password'] ?? ''),
                'database' => (string) ($_POST['service_' . $serviceKey . '_database'] ?? 'app_db'),
            ];
        }
    }

    $newConfig['containerized'] = isset($_POST['containerized']);
    $newConfig['devMount'] = isset($_POST['dev_mount']);
    $defaultAppPort = station_detect_project_container_port($projectSlug);
    $newConfig['appPort'] = station_normalize_docker_port($_POST['app_port'] ?? $defaultAppPort, $defaultAppPort);

    $hostPortRaw = trim((string) ($_POST['host_port'] ?? ''));
    if ($hostPortRaw === '' || $hostPortRaw === '0') {
        $newConfig['hostPort'] = station_allocate_project_host_port($projectSlug);
    } else {
        $newConfig['hostPort'] = station_normalize_docker_port($hostPortRaw, station_allocate_project_host_port($projectSlug));
    }

    $newConfig['composePath'] = '/docker-compose.yml';
    $newConfig['forceRebuildDockerfile'] = isset($_POST['rebuild_dockerfile']);

    if (station_encrypt_credentials($newConfig['credentials'], $projectSlug)) {
        $projectSettings = station_project_settings($projectSlug);
        $projectSettings['docker'] = $newConfig;
        station_save_project_settings($projectSlug, $projectSettings);

        if (!empty($newConfig['containerized']) || !empty($newConfig['services'])) {
            station_ensure_project_dockerfile($projectSlug, !empty($newConfig['forceRebuildDockerfile']));
            $composePath = station_projects_dir() . '/' . $projectSlug . '/docker-compose.yml';
            $composeContent = station_generate_docker_compose($newConfig);
            if (@file_put_contents($composePath, $composeContent, LOCK_EX) !== false) {
                station_log_event('project.docker.configured', ['project' => $projectSlug]);
                station_flash_set('ok', 'Docker configuration saved. Generated docker-compose.yml.');
                header('Location: docker-config.php?project=' . urlencode($projectSlug));
                exit;
            }
            $error = 'Could not write docker-compose.yml.';
        } else {
            station_flash_set('ok', 'Docker configuration saved.');
            header('Location: docker-config.php?project=' . urlencode($projectSlug));
            exit;
        }
    } else {
        $error = 'Could not save Docker configuration.';
    }

    $projectConfig = $newConfig;
}

$defaultAppPort = station_detect_project_container_port($projectSlug);
$projectConfig['appPort'] = station_normalize_docker_port($projectConfig['appPort'] ?? $defaultAppPort, $defaultAppPort);
if (empty($projectConfig['hostPort']) || (int) $projectConfig['hostPort'] <= 0) {
    $projectConfig['hostPort'] = station_allocate_project_host_port($projectSlug);
}
$projectConfig['containerized'] = !empty($projectConfig['containerized']);
$projectConfig['devMount'] = !empty($projectConfig['devMount']);
$credentials = station_decrypt_credentials($projectSlug) ?? [];

$composePath = station_projects_dir() . '/' . $projectSlug . '/docker-compose.yml';
$composeExists = is_file($composePath);
$dockerfileExists = is_file(station_projects_dir() . '/' . $projectSlug . '/Dockerfile');
$engineCheck = station_docker_engine_available();
$dockerDiagnostics = station_docker_runtime_diagnostics();
$status = station_project_docker_status($projectSlug);
$logTail = station_read_project_docker_log($projectSlug, 8192);

$stateLabels = [
    'running' => ['label' => 'Running', 'tone' => 'ok'],
    'partial' => ['label' => 'Partially Running', 'tone' => 'warn'],
    'stopped' => ['label' => 'Stopped', 'tone' => 'bad'],
    'unknown' => ['label' => 'Unknown', 'tone' => 'warn'],
    'unavailable' => ['label' => 'Engine Unreachable', 'tone' => 'bad'],
    'unconfigured' => ['label' => 'Not Configured', 'tone' => 'warn'],
];
$stateKey = (string) ($status['state'] ?? 'unconfigured');
$stateMeta = $stateLabels[$stateKey] ?? $stateLabels['unknown'];
?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('Docker — ' . station_h($projectSlug), 'Configure Docker services and database for this project.') ?>
</head>
<body class="station-body">
  <div class="dashboard-shell">
    <?= station_dashboard_nav_html('dashboard') ?>
    <main class="dashboard-main">
      <header class="dashboard-topbar">
        <div>
          <p class="dashboard-kicker">Container deployment</p>
          <h1 class="dashboard-heading">Docker · <?= station_h($projectSlug) ?></h1>
          <p class="dashboard-subheading">Pick services, set credentials, and launch this project as a self-contained Docker stack.</p>
        </div>
        <nav class="nav-pills">
          <a href="station.php">Dashboard</a>
          <a href="project-settings.php?project=<?= urlencode($projectSlug) ?>">Project Settings</a>
          <a href="launch.php?project=<?= urlencode($projectSlug) ?>" target="_blank" rel="noreferrer">Open ↗</a>
        </nav>
      </header>

      <?php if ($ok !== ''): ?><div class="alert ok"><?= station_h($ok) ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="alert error"><?= station_h($error) ?></div><?php endif; ?>

      <section class="docker-status-grid">
        <div class="docker-status-card status-<?= station_h($stateMeta['tone']) ?>">
          <span class="docker-status-label">Container state</span>
          <strong class="docker-status-value">
            <span class="status-dot"></span>
            <?= station_h($stateMeta['label']) ?>
          </strong>
          <span class="docker-status-meta"><?= count($status['services'] ?? []) ?> service<?= count($status['services'] ?? []) === 1 ? '' : 's' ?> tracked</span>
        </div>
        <div class="docker-status-card status-<?= !empty($engineCheck['ok']) ? 'ok' : 'bad' ?>">
          <span class="docker-status-label">Docker engine</span>
          <strong class="docker-status-value">
            <span class="status-dot"></span>
            <?= !empty($engineCheck['ok']) ? station_h('OK · ' . ($engineCheck['version'] ?: 'available')) : 'Unreachable' ?>
          </strong>
          <span class="docker-status-meta">
            <?php if (!empty($engineCheck['ok'])): ?>
              Web server can call docker at <code><?= station_h($dockerDiagnostics['binary']) ?></code>.
            <?php else: ?>
              <a href="admin-settings.php?tab=docker">Open Admin Settings → Docker</a> for full diagnostics + binary path override.
            <?php endif; ?>
          </span>
        </div>
        <div class="docker-status-card status-info">
          <span class="docker-status-label">Assigned host port</span>
          <strong class="docker-status-value">:<?= (int) $projectConfig['hostPort'] ?></strong>
          <span class="docker-status-meta">App listens on container port <?= (int) $projectConfig['appPort'] ?>.</span>
        </div>
        <div class="docker-status-card status-info">
          <span class="docker-status-label">Generated files</span>
          <strong class="docker-status-value">
            <?= $dockerfileExists ? '✓ Dockerfile' : '— Dockerfile' ?> · <?= $composeExists ? '✓ Compose' : '— Compose' ?>
          </strong>
          <span class="docker-status-meta">Saved on the next form submit.</span>
        </div>
      </section>

      <?php if ($projectConfig['containerized'] || $composeExists): ?>
      <section class="docker-actions-panel">
        <div class="docker-actions-row">
          <form method="post" action="docker-actions.php" data-docker-action>
            <input type="hidden" name="project" value="<?= station_h($projectSlug) ?>">
            <input type="hidden" name="action" value="start">
            <input type="hidden" name="return" value="docker-config.php?project=<?= urlencode($projectSlug) ?>">
            <button type="submit" class="btn-primary">Start / Rebuild</button>
          </form>
          <form method="post" action="docker-actions.php" data-docker-action>
            <input type="hidden" name="project" value="<?= station_h($projectSlug) ?>">
            <input type="hidden" name="action" value="restart">
            <input type="hidden" name="return" value="docker-config.php?project=<?= urlencode($projectSlug) ?>">
            <button type="submit" class="secondary-btn">Restart</button>
          </form>
          <form method="post" action="docker-actions.php" data-docker-action>
            <input type="hidden" name="project" value="<?= station_h($projectSlug) ?>">
            <input type="hidden" name="action" value="stop">
            <input type="hidden" name="return" value="docker-config.php?project=<?= urlencode($projectSlug) ?>">
            <button type="submit" class="secondary-btn">Stop</button>
          </form>
          <form method="post" action="docker-actions.php" data-docker-action onsubmit="return confirm('Destroy containers and start fresh? Data in named volumes is preserved.')">
            <input type="hidden" name="project" value="<?= station_h($projectSlug) ?>">
            <input type="hidden" name="action" value="down">
            <input type="hidden" name="return" value="docker-config.php?project=<?= urlencode($projectSlug) ?>">
            <button type="submit" class="danger-btn">Tear down</button>
          </form>
          <a class="quick-link" href="launch.php?project=<?= urlencode($projectSlug) ?>" target="_blank" rel="noreferrer">Open app ↗</a>
        </div>
        <p class="docker-actions-hint">Starting will rebuild the image and bring up dependencies. Tear down removes containers without deleting persistent volumes.</p>
      </section>
      <?php endif; ?>

      <form method="post" class="settings-shell docker-config-shell" enctype="application/x-www-form-urlencoded">
        <input type="hidden" name="project" value="<?= station_h($projectSlug) ?>">

        <nav class="settings-nav" aria-label="Docker sections">
          <a class="settings-nav-item active" href="#deployment"><span class="settings-nav-icon">🚀</span><span>Deployment</span></a>
          <a class="settings-nav-item" href="#services"><span class="settings-nav-icon">🧱</span><span>Services</span></a>
          <a class="settings-nav-item" href="#logs"><span class="settings-nav-icon">📜</span><span>Logs</span></a>
        </nav>

        <div class="settings-content">
          <section id="deployment" class="settings-panel">
            <div class="settings-panel-head">
              <h1 class="settings-panel-heading">Deployment</h1>
              <p class="settings-panel-subtitle">Choose how this project runs in Docker. Settings here only affect the auto-generated <code>Dockerfile</code> and <code>docker-compose.yml</code>.</p>
            </div>

            <label class="feature-toggle">
              <input type="checkbox" name="containerized" <?= $projectConfig['containerized'] ? 'checked' : '' ?>>
              <div class="feature-toggle-content">
                <span class="feature-toggle-title">Deploy this project in a container</span>
                <span class="feature-toggle-desc">Auto-generate a Dockerfile that matches the detected stack and run it inside docker compose alongside the selected services.</span>
              </div>
            </label>

            <div class="settings-form-group grid-2" style="margin-top: 18px;">
              <div class="setting-item">
                <label class="setting-label" for="app_port">App container port</label>
                <input id="app_port" type="number" name="app_port" min="1" max="65535" value="<?= station_h((string) $projectConfig['appPort']) ?>">
                <p class="setting-description">Port your code listens on inside the container. We detect 3000 for Node, 80 for PHP/static, 8000 for Python.</p>
              </div>
              <div class="setting-item">
                <label class="setting-label" for="host_port">Host port</label>
                <input id="host_port" type="number" name="host_port" min="0" max="65535" value="<?= station_h((string) $projectConfig['hostPort']) ?>">
                <p class="setting-description">Public port on this machine. Leave 0 to auto-assign a free port in the 8100–8999 range.</p>
              </div>
            </div>

            <div class="settings-form-group" style="margin-top: 18px;">
              <label class="feature-toggle">
                <input type="checkbox" name="dev_mount" <?= $projectConfig['devMount'] ? 'checked' : '' ?>>
                <div class="feature-toggle-content">
                  <span class="feature-toggle-title">Live-reload bind mount</span>
                  <span class="feature-toggle-desc">Mount the project directory into the container at <code>/app</code> so on-disk edits reload instantly. Disable for production builds.</span>
                </div>
              </label>

              <label class="feature-toggle">
                <input type="checkbox" name="rebuild_dockerfile">
                <div class="feature-toggle-content">
                  <span class="feature-toggle-title">Regenerate Dockerfile</span>
                  <span class="feature-toggle-desc">Overwrite the existing Dockerfile with a fresh one matching the detected stack on the next save.</span>
                </div>
              </label>
            </div>
          </section>

          <section id="services" class="settings-panel">
            <div class="settings-panel-head">
              <h1 class="settings-panel-heading">Services</h1>
              <p class="settings-panel-subtitle">Pick dependencies for this project. Credentials are encrypted and injected into the app container as environment variables.</p>
            </div>

            <div class="docker-settings-grid">
              <?php foreach ($dockerServices as $serviceKey => $serviceConfig): ?>
                <?php
                  $stationServiceEnabled = !empty($dockerSettings['services'][$serviceKey]);
                  $projectServiceEnabled = !empty($projectConfig['services'][$serviceKey]);
                ?>
                <article class="docker-service-card <?= $projectServiceEnabled ? 'enabled' : '' ?> <?= $stationServiceEnabled ? '' : 'disabled-by-admin' ?>" data-service="<?= station_h($serviceKey) ?>">
                  <label class="docker-service-head">
                    <input type="checkbox" name="service_<?= station_h($serviceKey) ?>"
                      <?= $projectServiceEnabled ? 'checked' : '' ?>
                      <?= $stationServiceEnabled ? '' : 'disabled' ?>
                      data-service-toggle="<?= station_h($serviceKey) ?>">
                    <span class="docker-service-icon"><?= station_h($serviceConfig['icon']) ?></span>
                    <span class="docker-service-text">
                      <span class="service-name"><?= station_h($serviceConfig['name']) ?></span>
                      <span class="service-desc"><?= station_h($serviceConfig['description']) ?></span>
                    </span>
                  </label>

                  <?php if (!$stationServiceEnabled): ?>
                    <p class="docker-service-warning">Disabled in Admin Settings. Enable it under Admin Settings → Docker first.</p>
                  <?php endif; ?>

                  <div class="docker-service-body" data-service-body="<?= station_h($serviceKey) ?>" hidden>
                    <div class="docker-service-grid">
                      <div class="setting-item">
                        <label class="setting-label">Version</label>
                        <select name="service_<?= station_h($serviceKey) ?>_version">
                          <?php foreach ($serviceConfig['versions'] as $version): ?>
                            <option value="<?= station_h($version) ?>"
                              <?= (string) ($credentials[$serviceKey]['version'] ?? $serviceConfig['defaultVersion']) === $version ? 'selected' : '' ?>>
                              <?= station_h($version) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>

                      <?php if (in_array($serviceKey, ['mysql', 'postgres', 'mongodb'], true)): ?>
                        <div class="setting-item">
                          <label class="setting-label">Root / admin password</label>
                          <input type="password" name="service_<?= station_h($serviceKey) ?>_root_password"
                            value="<?= station_h((string) ($credentials[$serviceKey]['rootPassword'] ?? '')) ?>"
                            placeholder="Generate a long password">
                        </div>
                        <div class="setting-item">
                          <label class="setting-label">Database name</label>
                          <input type="text" name="service_<?= station_h($serviceKey) ?>_database"
                            value="<?= station_h((string) ($credentials[$serviceKey]['database'] ?? 'app_db')) ?>"
                            placeholder="app_db">
                        </div>
                        <div class="setting-item">
                          <label class="setting-label">App user</label>
                          <input type="text" name="service_<?= station_h($serviceKey) ?>_user"
                            value="<?= station_h((string) ($credentials[$serviceKey]['user'] ?? '')) ?>"
                            placeholder="app_user (optional)">
                        </div>
                        <div class="setting-item">
                          <label class="setting-label">App user password</label>
                          <input type="password" name="service_<?= station_h($serviceKey) ?>_password"
                            value="<?= station_h((string) ($credentials[$serviceKey]['password'] ?? '')) ?>"
                            placeholder="User password (optional)">
                        </div>
                      <?php elseif ($serviceKey === 'redis'): ?>
                        <div class="setting-item">
                          <label class="setting-label">Password</label>
                          <input type="password" name="service_<?= station_h($serviceKey) ?>_password"
                            value="<?= station_h((string) ($credentials[$serviceKey]['password'] ?? '')) ?>"
                            placeholder="Leave blank for no auth">
                        </div>
                      <?php elseif ($serviceKey === 'elasticsearch'): ?>
                        <div class="setting-item">
                          <label class="setting-label">Elastic password</label>
                          <input type="password" name="service_<?= station_h($serviceKey) ?>_password"
                            value="<?= station_h((string) ($credentials[$serviceKey]['password'] ?? 'changeme')) ?>">
                        </div>
                      <?php elseif ($serviceKey === 'rabbitmq'): ?>
                        <div class="setting-item">
                          <label class="setting-label">Username</label>
                          <input type="text" name="service_<?= station_h($serviceKey) ?>_user"
                            value="<?= station_h((string) ($credentials[$serviceKey]['user'] ?? 'guest')) ?>">
                        </div>
                        <div class="setting-item">
                          <label class="setting-label">Password</label>
                          <input type="password" name="service_<?= station_h($serviceKey) ?>_password"
                            value="<?= station_h((string) ($credentials[$serviceKey]['password'] ?? 'guest')) ?>">
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          </section>

          <section id="logs" class="settings-panel">
            <div class="settings-panel-head">
              <h1 class="settings-panel-heading">Recent docker output</h1>
              <p class="settings-panel-subtitle">Captured from the last start / stop / restart action. Useful when something didn't build.</p>
            </div>
            <?php if ($logTail === ''): ?>
              <p class="empty-state">No docker output yet. Run a Start action to capture build logs.</p>
            <?php else: ?>
              <pre class="code-block docker-log"><?= station_h($logTail) ?></pre>
            <?php endif; ?>
            <div class="action-row" style="margin-top:12px;">
              <a class="quick-link" href="docker-actions.php?project=<?= urlencode($projectSlug) ?>&action=logs" target="_blank" rel="noreferrer">Open raw log ↗</a>
              <button type="button" class="quick-link" id="refreshDockerStatus">Refresh status</button>
            </div>
          </section>

          <div class="docker-config-save-bar">
            <button type="submit" class="btn-primary">Save configuration</button>
            <span class="docker-config-save-hint">Saving regenerates <code>docker-compose.yml</code>. Use the action bar above to actually start the container.</span>
          </div>
        </div>
      </form>
    </main>
  </div>

  <script>
    (function () {
      document.querySelectorAll('[data-service-toggle]').forEach(function (input) {
        function syncBody() {
          var body = document.querySelector('[data-service-body="' + input.dataset.serviceToggle + '"]');
          var card = input.closest('.docker-service-card');
          if (body) { body.hidden = !input.checked; }
          if (card) { card.classList.toggle('enabled', input.checked); }
        }
        syncBody();
        input.addEventListener('change', syncBody);
      });

      var navLinks = document.querySelectorAll('.docker-config-shell .settings-nav-item');
      navLinks.forEach(function (link) {
        link.addEventListener('click', function (event) {
          event.preventDefault();
          var hash = (link.getAttribute('href') || '').replace('#', '');
          var target = document.getElementById(hash);
          if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            navLinks.forEach(function (n) { n.classList.remove('active'); });
            link.classList.add('active');
          }
        });
      });

      var refresh = document.getElementById('refreshDockerStatus');
      if (refresh) {
        refresh.addEventListener('click', function () {
          refresh.textContent = 'Refreshing…';
          fetch('docker-status.php?project=<?= rawurlencode($projectSlug) ?>')
            .then(function (r) { return r.json(); })
            .then(function () { window.location.reload(); })
            .catch(function () { refresh.textContent = 'Failed to refresh'; });
        });
      }

      document.querySelectorAll('form[data-docker-action] button[type="submit"]').forEach(function (btn) {
        btn.form.addEventListener('submit', function () {
          btn.disabled = true;
          btn.dataset.originalLabel = btn.textContent || '';
          btn.textContent = 'Working…';
        });
      });
    })();
  </script>

  <?= station_dashboard_nav_script_html() ?>
  <?= station_clipboard_fab_html() ?>
  <?= station_pwa_register_html() ?>
</body>
</html>
