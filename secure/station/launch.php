<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';

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

header('Location: ' . station_project_serve_path($slug));
exit;
