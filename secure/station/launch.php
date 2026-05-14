<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/docker.php';

station_require_setup();

$slug = station_safe_name((string) ($_GET['project'] ?? ''));
if ($slug === '' || !station_project_exists($slug)) {
    station_flash_set('error', 'Project not found.');
    header('Location: station.php');
    exit;
}

$user = station_current_user();
if (!station_user_may_access_project($user, $slug)) {
    if (!$user) {
        header('Location: ' . station_station_url('index.php'));
        exit;
    }

    header('Location: ' . station_station_url('access-denied.php?project=' . urlencode($slug)));
    exit;
}

station_log_event('project.launch', ['slug' => $slug, 'accessMode' => station_project_access_mode($slug)]);

// Detect chrome extension: check stored templateType or presence of manifest.json
$meta = station_projects_meta();
$metaProjects = isset($meta['projects']) && is_array($meta['projects']) ? $meta['projects'] : [];
$templateType = '';
foreach ($metaProjects as $p) {
    if (is_array($p) && (string) ($p['slug'] ?? '') === $slug) {
        $templateType = (string) ($p['templateType'] ?? '');
        break;
    }
}

$projectPath = station_project_path($slug);
if ($templateType === '' || $templateType === 'chrome-extension') {
    $manifestPath = $projectPath . '/manifest.json';
    if (file_exists($manifestPath)) {
        $manifest = @json_decode((string) (@file_get_contents($manifestPath) ?: ''), true);
        if (is_array($manifest) && isset($manifest['manifest_version'])) {
            $templateType = 'chrome-extension';
        }
    }
}

if ($templateType === 'chrome-extension') {
    $readmePath = $projectPath . '/README.md';
    $readme = file_exists($readmePath) ? (string) (@file_get_contents($readmePath) ?: '') : '';
    $manifestData = [];
    $manifestPath = $projectPath . '/manifest.json';
    if (file_exists($manifestPath)) {
        $manifestData = json_decode((string) (@file_get_contents($manifestPath) ?: ''), true) ?: [];
    }
    $extName    = (string) ($manifestData['name'] ?? $slug);
    $extVersion = (string) ($manifestData['version'] ?? '1.0');
    ?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html($extName . ' — Chrome Extension', 'Download and install this Chrome extension package from the deployment station.') ?>
</head>
<body class="station-body">
  <main class="station-shell narrow">
    <header class="topbar card">
      <div class="topbar-brand">
        <p class="kicker">Chrome Extension</p>
        <h1><?= station_h($extName) ?></h1>
        <p class="topbar-sub">v<?= station_h($extVersion) ?> &mdash; <?= station_h($slug) ?></p>
      </div>
      <nav class="nav-pills">
        <a href="station.php">← Dashboard</a>
        <a href="viewer.php?project=<?= urlencode($slug) ?>">Browse Files</a>
      </nav>
    </header>

    <section class="card form-grid">
      <h2>Deploy to Chrome</h2>
      <ol class="deploy-steps">
        <li>Download the zip file below and unzip it to a folder on your computer.</li>
        <li>Open Chrome and navigate to <code>chrome://extensions</code>.</li>
        <li>Enable <strong>Developer mode</strong> using the toggle in the top-right corner.</li>
        <li>Click <strong>Load unpacked</strong> and select the unzipped folder.</li>
        <li>The extension appears in your toolbar immediately — pin it if needed.</li>
      </ol>
      <a class="download-btn" href="download.php?project=<?= urlencode($slug) ?>">⬇ Download <?= station_h($slug) ?>.zip</a>
    </section>

    <?php if ($readme !== ''): ?>
    <section class="card">
      <h2>README</h2>
      <pre class="code-block"><?= station_h($readme) ?></pre>
    </section>
    <?php endif; ?>
  </main>
  <?= station_pwa_register_html() ?>
</body>
</html>
    <?php
    exit;
}

$projectSettings = station_project_settings($slug);
$dockerConfig = isset($projectSettings['docker']) && is_array($projectSettings['docker']) ? $projectSettings['docker'] : [];
$isContainerized = $dockerConfig !== [] && !empty($dockerConfig['containerized']);

if ($isContainerized) {
    $appPort = station_normalize_docker_port($dockerConfig['appPort'] ?? 80);
    $hostPort = (int) ($dockerConfig['hostPort'] ?? 0);
    if ($hostPort <= 0) {
        $hostPort = station_allocate_project_host_port($slug);
        $dockerConfig['hostPort'] = $hostPort;
        $projectSettings['docker'] = $dockerConfig;
        station_save_project_settings($slug, $projectSettings);
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    $directTarget = $scheme . '://' . $host . ':' . $hostPort . '/';

    $adminSettingsForLaunch = station_admin_settings();
    $usesNginx = station_normalize_server_infrastructure((string) ($adminSettingsForLaunch['serverInfrastructure'] ?? 'apache')) === 'nginx';
    $friendlyTarget = $scheme . '://' . $host . '/p/' . rawurlencode($slug) . '/';

    $status = station_project_docker_status($slug);
    if (($status['state'] ?? '') === 'running') {
        // Only use /p/<slug>/ when the managed include actually contains this
        // route; otherwise nginx falls through to the site root (wrong page).
        $target = $directTarget;
        if ($usesNginx) {
            $sync = station_write_nginx_projects_conf();
            if (empty($sync['ok'])) {
                station_log_event('nginx.include.failed', [
                    'project' => $slug,
                    'phase' => 'launch',
                    'message' => (string) ($sync['message'] ?? ''),
                ]);
            }
            if (station_nginx_proxy_route_present_for_slug($slug)) {
                $target = $friendlyTarget;
            }
        }
        header('Location: ' . $target);
        exit;
    }

    $stateLabels = [
        'running' => ['label' => 'Running', 'tone' => 'ok'],
        'partial' => ['label' => 'Partially Running', 'tone' => 'warn'],
        'stopped' => ['label' => 'Stopped', 'tone' => 'bad'],
        'unknown' => ['label' => 'Unknown', 'tone' => 'warn'],
        'unavailable' => ['label' => 'Docker Engine Unreachable', 'tone' => 'bad'],
        'unconfigured' => ['label' => 'Not Configured', 'tone' => 'warn'],
    ];
    $stateMeta = $stateLabels[$status['state'] ?? 'unknown'] ?? $stateLabels['unknown'];
    $canBuild = station_can_build($user);
    ?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('Launch ' . $slug, 'Container deployment status for ' . $slug . '.') ?>
</head>
<body class="station-body">
  <main class="station-shell narrow" style="padding-top: 32px;">
    <section class="card form-grid">
      <p class="kicker">Container deployment</p>
      <h1><?= station_h($slug) ?></h1>
      <p>This project is configured to run in Docker. Current status:</p>
      <p>
        <span class="status-pill <?= $stateMeta['tone'] === 'ok' ? 'is-public' : 'is-private' ?>" style="font-size:12px;padding:6px 14px;">
          ● <?= station_h($stateMeta['label']) ?>
        </span>
      </p>

      <?php if (($status['state'] ?? '') !== 'running'): ?>
        <p>The container is not running. Use the controls below to start it.</p>
        <?php if ($usesNginx): ?>
          <p class="topbar-sub">After start, reload nginx if you use friendly URLs and automatic reload is off in Admin → Projects. Put <code>include …/projects.conf</code> <strong>before</strong> <code>location /</code> in your server block, then <code>sudo nginx -t &amp;&amp; sudo systemctl reload nginx</code>. Friendly URL: <code><?= station_h($friendlyTarget) ?></code></p>
        <?php else: ?>
          <p class="topbar-sub">Direct access (when running): <code><?= station_h($directTarget) ?></code>. Switch the production web server to Nginx in Admin Settings to also expose a friendly <code>/p/<?= station_h($slug) ?>/</code> URL.</p>
        <?php endif; ?>
        <div class="action-row">
          <?php if ($canBuild): ?>
            <form method="post" action="docker-actions.php">
              <input type="hidden" name="project" value="<?= station_h($slug) ?>">
              <input type="hidden" name="action" value="start">
              <input type="hidden" name="return" value="launch.php?project=<?= urlencode($slug) ?>">
              <button type="submit" class="btn-primary">Start container</button>
            </form>
            <a class="quick-link" href="docker-config.php?project=<?= urlencode($slug) ?>">Configure Docker</a>
          <?php endif; ?>
          <a class="quick-link" href="station.php">Back to dashboard</a>
        </div>
        <?php if (!empty($status['output'])): ?>
          <details>
            <summary class="mini-link">Show diagnostic output</summary>
            <pre class="code-block"><?= station_h((string) $status['output']) ?></pre>
          </details>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </main>
  <?= station_pwa_register_html() ?>
</body>
</html>
    <?php
    exit;
}

header('Location: ' . station_project_serve_path($slug));
exit;
