<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';

station_require_login();
$user = station_current_user();

$project = station_safe_name((string) ($_GET['project'] ?? ''));
if ($project === '' || !station_project_exists($project)) {
    station_flash_set('error', 'Project not found.');
    header('Location: station.php');
    exit;
}

  if (!station_user_may_access_project($user, $project)) {
    station_flash_set('error', 'You do not have access to that project.');
    header('Location: station.php');
    exit;
  }

$file = trim((string) ($_GET['file'] ?? ''));
$files = station_scan_project_files($project);
$content = null;
if ($file !== '') {
    $content = station_read_project_file($project, $file);
}

station_log_event('project.viewer.opened', ['project' => $project, 'file' => $file]);

$error = station_flash_get('error');
$ok = station_flash_get('ok');
?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('Project Viewer', 'Browse project files from the deployment station.') ?>
</head>
<body class="station-body">
  <main class="station-shell">
    <header class="topbar card">
      <div>
        <p class="kicker">Project Viewer</p>
        <h1><?= station_h($project) ?></h1>
        <p>User: <?= station_h((string) ($user['username'] ?? '')) ?></p>
      </div>
      <nav class="nav-pills">
        <a href="station.php">Dashboard</a>
        <a href="launch.php?project=<?= urlencode($project) ?>" target="_blank" rel="noreferrer">Launch Site</a>
        <?php if (station_can_build($user)): ?>
          <a href="integration-help.php#workspace-api">Workspace API</a>
        <?php endif; ?>
        <a href="logout.php">Logout</a>
      </nav>
    </header>

    <?php if ($ok !== ''): ?><div class="alert ok"><?= station_h($ok) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert error"><?= station_h($error) ?></div><?php endif; ?>

    <section class="grid-split">
      <aside class="card list-panel">
        <h2>Files</h2>
        <ul class="file-list">
          <?php foreach ($files as $entry): ?>
            <?php $path = (string) ($entry['path'] ?? ''); ?>
            <li>
              <a class="file-item <?= $path === $file ? 'active' : '' ?>" href="viewer.php?project=<?= urlencode($project) ?>&file=<?= urlencode($path) ?>"><?= station_h($path) ?></a>
              <a class="mini-link" href="editor.php?project=<?= urlencode($project) ?>&file=<?= urlencode($path) ?>">Edit</a>
            </li>
          <?php endforeach; ?>
        </ul>
      </aside>

      <section class="card editor-panel">
        <h2>Content</h2>
        <?php if ($file === ''): ?>
          <p>Select a file to view content.</p>
        <?php elseif ($content === null): ?>
          <p>File is not text-previewable or inaccessible.</p>
        <?php else: ?>
          <p class="file-meta">Path: <?= station_h($file) ?></p>
          <pre class="code-block"><?= station_h($content) ?></pre>
        <?php endif; ?>
      </section>
    </section>
  </main>
  <?= station_clipboard_fab_html() ?>
  <?= station_pwa_register_html() ?>
</body>
</html>
