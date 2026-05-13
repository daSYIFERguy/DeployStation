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

$accessMode = station_project_access_mode($slug);
$user = station_current_user();
if (!station_can_access_project($user, $accessMode)) {
  if (!$user) {
    header('Location: ' . station_station_url('index.php'));
    exit;
  }

  header('Location: ' . station_station_url('access-denied.php?project=' . urlencode($slug)));
    exit;
}

station_log_event('project.launch', ['slug' => $slug, 'accessMode' => $accessMode]);

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

$settings = station_project_settings($slug);
$dockerSettings = station_project_docker_settings($slug, $settings);
if (!empty($dockerSettings['enabled'])) {
    $dockerStatus = station_project_docker_status($slug, $settings);
    $dockerUrl = station_project_docker_url($dockerSettings);
    $dockerResult = null;
    $canManageDocker = station_can_build($user);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $dockerAction = (string) ($_POST['docker_action'] ?? '');
        if ($dockerAction === 'start') {
            $dockerResult = station_start_project_docker($slug, $settings);
            if (!empty($dockerResult['ok'])) {
                header('Location: ' . (string) ($dockerResult['url'] ?? $dockerUrl));
                exit;
            }
        } elseif ($dockerAction === 'stop' && $canManageDocker) {
            $dockerResult = station_stop_project_docker($slug, $settings);
            $dockerStatus = station_project_docker_status($slug, $settings);
        }
    } elseif (!empty($dockerStatus['running'])) {
        header('Location: ' . $dockerUrl);
        exit;
    } elseif (!empty($dockerSettings['autoStart'])) {
        $dockerResult = station_start_project_docker($slug, $settings);
        if (!empty($dockerResult['ok'])) {
            header('Location: ' . (string) ($dockerResult['url'] ?? $dockerUrl));
            exit;
        }
    }

    $dockerStatus = station_project_docker_status($slug, $settings);
    ?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html($slug . ' Docker Launch', 'Build, run, and open this containerized project from Station.') ?>
</head>
<body class="station-body">
  <main class="station-shell">
    <header class="topbar card settings-hero">
      <div class="topbar-brand">
        <p class="kicker">Docker Launch</p>
        <h1><?= station_h($slug) ?></h1>
        <p>Containerized launch is enabled for this project.</p>
      </div>
      <nav class="nav-pills">
        <a href="station.php">Dashboard</a>
        <a href="viewer.php?project=<?= urlencode($slug) ?>">Files</a>
        <?php if ($canManageDocker): ?><a href="project-settings.php?project=<?= urlencode($slug) ?>">Settings</a><?php endif; ?>
      </nav>
    </header>

    <?php if (is_array($dockerResult) && empty($dockerResult['ok'])): ?>
      <div class="alert error"><?= station_h((string) ($dockerResult['message'] ?? 'Docker launch failed.')) ?></div>
    <?php elseif (is_array($dockerResult) && !empty($dockerResult['ok'])): ?>
      <div class="alert ok"><?= station_h((string) ($dockerResult['message'] ?? 'Docker container started.')) ?></div>
    <?php endif; ?>

    <section class="card launch-panel">
      <div class="settings-card-head">
        <div>
          <p class="kicker">Status</p>
          <h2><?= station_h((string) ($dockerStatus['label'] ?? 'Unknown')) ?></h2>
          <p class="section-note">Image <code><?= station_h((string) $dockerSettings['imageName']) ?></code> runs as <code><?= station_h((string) $dockerSettings['containerName']) ?></code>.</p>
        </div>
        <span class="status-pill <?= !empty($dockerStatus['running']) ? 'is-public' : 'is-private' ?>"><?= !empty($dockerStatus['running']) ? 'Running' : 'Offline' ?></span>
      </div>

      <div class="grid-two launch-grid">
        <div class="card feature-card">
          <h2>Open App</h2>
          <p>When running, the app opens at this Docker host port.</p>
          <p><code><?= station_h($dockerUrl) ?></code></p>
          <div class="settings-actions">
            <?php if (!empty($dockerStatus['running'])): ?>
              <a class="download-btn" href="<?= station_h($dockerUrl) ?>" target="_blank" rel="noreferrer">Open Running App</a>
            <?php endif; ?>
            <form method="post" class="inline-form">
              <input type="hidden" name="docker_action" value="start">
              <button type="submit"><?= !empty($dockerStatus['running']) ? 'Rebuild & Restart' : 'Build & Start' ?></button>
            </form>
            <a class="download-btn secondary-link" href="<?= station_h(station_project_serve_path($slug)) ?>">Open Direct Serve</a>
            <?php if ($canManageDocker && !empty($dockerStatus['exists'])): ?>
              <form method="post" class="inline-form">
                <input type="hidden" name="docker_action" value="stop">
                <button type="submit" class="secondary-btn">Stop Container</button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <div class="card feature-card">
          <h2>Docker Profile</h2>
          <p>Runtime: <?= station_h((string) $dockerSettings['runtime']) ?></p>
          <p>Ports: <?= station_h((string) $dockerSettings['hostPort']) ?> -> <?= station_h((string) $dockerSettings['containerPort']) ?></p>
          <p>Dockerfile: <code><?= station_h((string) $dockerSettings['dockerfile']) ?></code></p>
          <p>Auto-start: <?= !empty($dockerSettings['autoStart']) ? 'enabled' : 'disabled' ?></p>
        </div>
      </div>

      <?php if (!empty($dockerResult['output'])): ?>
        <h2>Last Docker Output</h2>
        <pre class="code-block"><?= station_h((string) $dockerResult['output']) ?></pre>
      <?php elseif (!empty($dockerStatus['detail'])): ?>
        <h2>Docker Detail</h2>
        <pre class="code-block"><?= station_h((string) $dockerStatus['detail']) ?></pre>
      <?php endif; ?>

      <h2>Equivalent CLI</h2>
      <pre class="code-block"><?= station_h(station_project_docker_cli_preview($slug, $settings)) ?></pre>
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
