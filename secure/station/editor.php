<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';

station_require_builder();

$user = station_current_user();

$project = station_safe_name((string) ($_GET['project'] ?? $_POST['project'] ?? ''));
$file = trim((string) ($_GET['file'] ?? $_POST['file'] ?? ''));
if ($project === '' || !station_project_exists($project)) {
    station_flash_set('error', 'Project not found.');
    header('Location: station.php');
    exit;
}

  if (!station_user_may_access_project($user, $project)) {
    station_flash_set('error', 'You do not have access to edit that project.');
    header('Location: station.php');
    exit;
  }

if ($file === '') {
    station_flash_set('error', 'No file selected.');
    header('Location: viewer.php?project=' . urlencode($project));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = (string) ($_POST['content'] ?? '');
    if (!station_write_project_file($project, $file, $content)) {
        $error = 'Save failed. Check permissions and path.';
    } else {
    station_log_event('project.file.saved', ['project' => $project, 'file' => $file]);
        station_flash_set('ok', 'Saved ' . $file);
        header('Location: viewer.php?project=' . urlencode($project) . '&file=' . urlencode($file));
        exit;
    }
}

$content = station_read_project_file($project, $file);
if ($content === null) {
    $error = 'This file is not editable here.';
    $content = '';
}
?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('Editor', 'Edit project files from the deployment station.') ?>
</head>
<body class="station-body">
  <main class="station-shell">
    <header class="topbar card">
      <div>
        <p class="kicker">Editor</p>
        <h1><?= station_h($project) ?></h1>
        <p><?= station_h($file) ?></p>
      </div>
      <nav class="nav-pills">
        <a href="viewer.php?project=<?= urlencode($project) ?>&file=<?= urlencode($file) ?>">Back to Viewer</a>
        <a href="station.php">Dashboard</a>
      </nav>
    </header>

    <?php if ($error !== ''): ?><div class="alert error"><?= station_h($error) ?></div><?php endif; ?>

    <form method="post" class="card form-grid">
      <input type="hidden" name="project" value="<?= station_h($project) ?>">
      <input type="hidden" name="file" value="<?= station_h($file) ?>">
      <label>Code / Content
        <textarea name="content" rows="24"><?= station_h($content) ?></textarea>
      </label>
      <button type="submit">Save File</button>
    </form>
  </main>
  <?= station_pwa_register_html() ?>
</body>
</html>
