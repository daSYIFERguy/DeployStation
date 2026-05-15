<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/docker.php';
require_once __DIR__ . '/lib/project-launch.php';

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

$file = trim(str_replace('\\', '/', (string) ($_GET['file'] ?? '')));
if ($file !== '' && !station_is_safe_relative_path($file)) {
    $file = '';
}

$canBuild = station_can_build($user);
$settings = station_project_settings($project);
$dockerPs = isset($settings['docker']) && is_array($settings['docker']) ? $settings['docker'] : [];
$dockerContainerized = !empty($dockerPs['containerized']) && station_docker_enabled();
$ghLinks = station_project_github_browser_links($project);

station_log_event('project.viewer.opened', ['project' => $project, 'file' => $file]);

$ideConfig = [
    'project' => $project,
    'canBuild' => $canBuild,
    'terminalUrl' => station_terminal_embed_url(),
    'dockerEnabled' => station_docker_enabled(),
    'dockerContainerized' => $dockerContainerized,
    'initialFile' => $file,
    'publicWebPath' => station_project_public_web_path($project),
];
?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('Workspace — ' . station_h($project), 'Project file explorer, editor, and terminal.') ?>
  <link rel="stylesheet" href="assets/project-workspace.css?v=20260522a">
</head>
<body class="station-body">
  <div class="dashboard-shell">
    <?= station_dashboard_nav_html('dashboard') ?>
    <main class="dashboard-main workspace-ide-main">
      <header class="dashboard-topbar">
        <div>
          <p class="dashboard-kicker">Workspace</p>
          <h1 class="dashboard-heading"><?= station_h($project) ?></h1>
          <p class="dashboard-subheading">Browse, edit, upload, and manage files<?= $canBuild ? ' — builder access' : ' — read-only' ?>.</p>
        </div>
        <nav class="nav-pills">
          <a href="station.php">Dashboard</a>
          <a href="launch.php?project=<?= urlencode($project) ?>" target="_blank" rel="noreferrer">Launch ↗</a>
          <a href="project-settings.php?project=<?= urlencode($project) ?>">Manage</a>
          <?php if (is_array($ghLinks)): ?>
            <a href="<?= station_h((string) $ghLinks['html']) ?>" target="_blank" rel="noreferrer">GitHub ↗</a>
          <?php endif; ?>
        </nav>
      </header>

      <?= station_flash_banners_html() ?>

      <div class="workspace-ide-toolbar">
        <div class="workspace-ide-toolbar-group">
          <button type="button" class="secondary-btn" id="wsRefresh">Refresh</button>
          <?php if ($canBuild): ?>
            <button type="button" class="secondary-btn" id="wsNewFile">New file</button>
            <button type="button" class="secondary-btn" id="wsMkdir">New folder</button>
            <label class="secondary-btn" style="cursor:pointer;margin:0;">
              Upload
              <input type="file" id="wsUploadInput" multiple hidden>
            </label>
            <button type="button" class="secondary-btn" id="wsDelete">Delete</button>
            <button type="button" class="btn-primary" id="wsSave">Save</button>
          <?php endif; ?>
        </div>
        <div class="workspace-ide-toolbar-group">
          <a class="secondary-btn" href="<?= station_h(station_project_public_web_path($project)) ?>" target="_blank" rel="noreferrer">Open site ↗</a>
          <button type="button" class="secondary-btn" id="wsToggleTerminal">Show terminal</button>
          <?php if (station_is_owner($user)): ?>
            <a class="secondary-btn" href="admin-host-health.php">Host nginx / PHP / Docker</a>
          <?php endif; ?>
        </div>
        <?php if ($canBuild && $dockerContainerized): ?>
        <div class="workspace-ide-toolbar-group">
          <button type="button" class="secondary-btn" id="wsDockerStart" data-docker-action="start">Docker start</button>
          <button type="button" class="secondary-btn" id="wsDockerStop" data-docker-action="stop">Stop</button>
          <button type="button" class="secondary-btn" id="wsDockerRestart" data-docker-action="restart">Restart</button>
        </div>
        <?php endif; ?>
      </div>

      <div class="workspace-ide-body">
        <aside class="workspace-ide-sidebar">
          <div class="workspace-ide-sidebar-head">
            <nav class="workspace-ide-breadcrumb" id="wsBreadcrumb" aria-label="Folder path"></nav>
          </div>
          <div class="workspace-ide-tree" id="wsTree"></div>
        </aside>
        <section class="workspace-ide-editor">
          <div class="workspace-ide-editor-head">
            <span>File: <code id="wsOpenPath"><?= $file !== '' ? station_h($file) : '—' ?></code></span>
          </div>
          <?php if ($canBuild): ?>
            <textarea id="wsEditor" class="workspace-ide-textarea" spellcheck="false" placeholder="Select a file from the tree…"></textarea>
          <?php else: ?>
            <textarea id="wsEditor" class="workspace-ide-textarea" spellcheck="false" readonly placeholder="Select a file to view (read-only)…"></textarea>
          <?php endif; ?>
          <p class="workspace-ide-status" id="wsStatus"></p>
        </section>
      </div>

      <div class="workspace-ide-terminal" id="wsTerminal" hidden>
        <div class="workspace-ide-terminal-head">
          <span>SSH terminal — <?= station_h(station_terminal_embed_url()) ?> (sign in when prompted)</span>
          <button type="button" class="secondary-btn" id="wsTerminalClose">Hide</button>
        </div>
        <iframe id="wsTerminalFrame" title="SSH terminal" sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-modals"></iframe>
      </div>
    </main>
  </div>

  <script>
  window.STATION_WORKSPACE_IDE = <?= json_encode($ideConfig, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) ?>;
  </script>
  <script src="assets/project-workspace.js?v=20260518c"></script>
  <?= station_dashboard_page_footer_html() ?>
</body>
</html>
