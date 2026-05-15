<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/docker.php';

station_require_builder();

$user = station_current_user();
$project = station_safe_name((string) ($_GET['project'] ?? $_POST['project'] ?? ''));
if ($project === '' || !station_project_exists($project)) {
    station_flash_set('error', 'Project not found.');
    header('Location: station.php');
    exit;
}

if (!station_user_may_access_project($user, $project)) {
    station_flash_set('error', 'You do not have access to manage that project.');
    header('Location: station.php');
    exit;
}

$settings = station_project_settings($project);
$error = '';

function station_parse_env_text(string $text): array
{
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    $result = [];
    foreach ($lines as $line) {
        $trimmed = trim((string) $line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $trimmed, 2);
        $safeKey = strtoupper(trim($key));
        if ($safeKey === '') {
            continue;
        }
        $result[$safeKey] = trim($value);
    }
    return $result;
}

function station_env_text(array $env): string
{
    $lines = [];
    foreach ($env as $key => $value) {
        $lines[] = strtoupper((string) $key) . '=' . (string) $value;
    }
    return implode("\n", $lines);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save_settings');

    if ($action === 'save_settings') {
        $settings['environment'] = station_parse_env_text((string) ($_POST['environment_text'] ?? ''));
        $settings['notes'] = trim((string) ($_POST['notes'] ?? ''));
        $settings['github'] = [
            'repoOwner' => trim((string) ($_POST['repo_owner'] ?? '')),
            'repoName' => trim((string) ($_POST['repo_name'] ?? '')),
            'visibility' => ((string) ($_POST['repo_visibility'] ?? 'private')) === 'public' ? 'public' : 'private',
            'defaultBranch' => trim((string) ($_POST['default_branch'] ?? 'main')) ?: 'main',
            'releaseWorkflow' => isset($_POST['release_workflow'])
        ];

        if (station_save_project_settings($project, $settings) && station_write_env_file($project, $settings['environment'])) {
            station_log_event('project.settings.saved', ['project' => $project]);
            station_flash_set('ok', 'Project settings saved.');
            header('Location: project-settings.php?project=' . urlencode($project));
            exit;
        }
        $error = 'Could not save project settings.';
    }

    if ($action === 'generate_github') {
        $settings['github'] = [
            'repoOwner' => trim((string) ($_POST['repo_owner'] ?? '')),
            'repoName' => trim((string) ($_POST['repo_name'] ?? '')),
            'visibility' => ((string) ($_POST['repo_visibility'] ?? 'private')) === 'public' ? 'public' : 'private',
            'defaultBranch' => trim((string) ($_POST['default_branch'] ?? 'main')) ?: 'main',
            'releaseWorkflow' => isset($_POST['release_workflow'])
        ];
        station_save_project_settings($project, $settings);
        $result = station_generate_github_bootstrap_files($project, $settings);
        if (!empty($result['ok'])) {
            station_log_event('github.bootstrap.generated', ['project' => $project]);
            station_flash_set('ok', (string) ($result['message'] ?? 'GitHub files generated.'));
            header('Location: project-settings.php?project=' . urlencode($project));
            exit;
        }
        $error = (string) ($result['message'] ?? 'GitHub generation failed.');
    }
}

$dockerPs = isset($settings['docker']) && is_array($settings['docker']) ? $settings['docker'] : [];
$dockerContainerized = !empty($dockerPs['containerized']);
$accessModes = station_allowed_project_access_modes();
$adminSettings = station_admin_settings();
$projectMeta = null;
foreach (station_list_projects() as $p) {
    if ((string) ($p['slug'] ?? '') === $project) {
        $projectMeta = $p;
        break;
    }
}
$pAccess = (string) ($projectMeta['accessMode'] ?? 'admin');
$pOwner = (string) ($projectMeta['owner'] ?? 'unknown');
$isStationOwner = station_is_owner($user);
?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('Project Settings — ' . station_h($project), 'Environment variables, repository metadata, and deployment notes for this project.') ?>
</head>
<body class="station-body">
  <div class="dashboard-shell">
    <?= station_dashboard_nav_html('dashboard') ?>
    <main class="dashboard-main">
      <header class="dashboard-topbar">
        <div>
          <p class="dashboard-kicker">Project</p>
          <h1 class="dashboard-heading"><?= station_h($project) ?></h1>
          <p class="dashboard-subheading">Configuration for this deployment: environment, GitHub metadata, and where to manage containers.</p>
        </div>
        <nav class="nav-pills">
          <a href="station.php">Dashboard</a>
          <a href="viewer.php?project=<?= urlencode($project) ?>">Files</a>
          <a href="launch.php?project=<?= urlencode($project) ?>" target="_blank" rel="noreferrer">Launch ↗</a>
          <?php if (station_docker_enabled()): ?>
            <a href="docker-config.php?project=<?= urlencode($project) ?>">Docker</a>
          <?php endif; ?>
          <?php if (station_is_admin($user)): ?>
            <a href="template-manager.php">Templates</a>
          <?php endif; ?>
        </nav>
      </header>

      <?= station_flash_banners_html() ?>

      <form method="post" class="settings-shell project-settings-shell" id="project-settings-form">
        <input type="hidden" name="project" value="<?= station_h($project) ?>">

        <nav class="settings-nav" aria-label="Project settings sections">
          <a class="settings-nav-item active" href="#general"><span class="settings-nav-icon">⚙</span><span>General</span></a>
          <a class="settings-nav-item" href="#github"><span class="settings-nav-icon">🐙</span><span>GitHub</span></a>
          <a class="settings-nav-item" href="#runtime"><span class="settings-nav-icon">🐳</span><span>Runtime</span></a>
          <a class="settings-nav-item" href="#administration"><span class="settings-nav-icon">🛡</span><span>Administration</span></a>
        </nav>

        <div class="settings-content">
          <section id="general" class="settings-panel">
            <div class="settings-panel-head">
              <h2 class="settings-panel-heading">General</h2>
              <p class="settings-panel-subtitle">Environment variables are written to <code>.env</code> in the project tree for local tooling. One <code>KEY=value</code> per line; lines starting with <code>#</code> are ignored.</p>
            </div>

            <div class="settings-form-group">
              <div class="setting-item">
                <label class="setting-label" for="environment_text">Environment variables</label>
                <textarea id="environment_text" name="environment_text" class="project-settings-textarea" rows="16" placeholder="API_URL=https://example.com&#10;API_KEY=secret"><?= station_h(station_env_text($settings['environment'] ?? [])) ?></textarea>
              </div>
              <div class="setting-item">
                <label class="setting-label" for="notes">Internal notes</label>
                <textarea id="notes" name="notes" class="project-settings-textarea" rows="8" placeholder="Runbooks, credentials location, staging URLs…"><?= station_h((string) ($settings['notes'] ?? '')) ?></textarea>
                <p class="setting-description">Visible to everyone who can open this project’s settings. Do not store secrets here unless the project is appropriately restricted.</p>
              </div>
            </div>

            <div class="settings-form-actions">
              <button type="submit" class="btn-primary" name="action" value="save_settings">Save changes</button>
            </div>
          </section>

          <section id="github" class="settings-panel">
            <div class="settings-panel-head">
              <h2 class="settings-panel-heading">GitHub</h2>
              <p class="settings-panel-subtitle">Metadata used when generating workflow and helper files into the repository. This does not create the remote repository for you.</p>
            </div>

            <div class="settings-form-group grid-2">
              <div class="setting-item">
                <label class="setting-label" for="repo_owner">Owner or organization</label>
                <input id="repo_owner" type="text" name="repo_owner" value="<?= station_h((string) ($settings['github']['repoOwner'] ?? '')) ?>" placeholder="octocat" autocomplete="organization">
              </div>
              <div class="setting-item">
                <label class="setting-label" for="repo_name">Repository name</label>
                <input id="repo_name" type="text" name="repo_name" value="<?= station_h((string) ($settings['github']['repoName'] ?? $project)) ?>" placeholder="<?= station_h($project) ?>" autocomplete="off">
              </div>
              <div class="setting-item">
                <label class="setting-label" for="repo_visibility">Visibility</label>
                <select id="repo_visibility" name="repo_visibility">
                  <option value="private" <?= ((string) ($settings['github']['visibility'] ?? 'private')) === 'private' ? 'selected' : '' ?>>Private</option>
                  <option value="public" <?= ((string) ($settings['github']['visibility'] ?? 'private')) === 'public' ? 'selected' : '' ?>>Public</option>
                </select>
              </div>
              <div class="setting-item">
                <label class="setting-label" for="default_branch">Default branch</label>
                <input id="default_branch" type="text" name="default_branch" value="<?= station_h((string) ($settings['github']['defaultBranch'] ?? 'main')) ?>" placeholder="main">
              </div>
            </div>

            <label class="feature-toggle">
              <input type="checkbox" name="release_workflow" <?= !empty($settings['github']['releaseWorkflow']) ? 'checked' : '' ?>>
              <div class="feature-toggle-content">
                <span class="feature-toggle-title">Generate release workflow</span>
                <span class="feature-toggle-desc">Adds a GitHub Actions workflow file tailored to this repo name when you click generate below.</span>
              </div>
            </label>

            <div class="settings-form-actions" style="margin-top: 20px;">
              <button type="submit" class="btn-primary" name="action" value="save_settings">Save GitHub metadata</button>
            </div>

            <div class="settings-panel-divider"></div>

            <h3 class="settings-subheading">Bootstrap files</h3>
            <p class="setting-description">Writes supporting files (e.g. CI workflow stubs) into the project directory based on the fields above.</p>
            <div class="settings-form-actions">
              <button type="submit" class="secondary-btn" name="action" value="generate_github" onclick="return confirm('Generate or overwrite bootstrap files in this project?');">Generate GitHub files</button>
            </div>
          </section>

          <section id="runtime" class="settings-panel">
            <div class="settings-panel-head">
              <h2 class="settings-panel-heading">Containers &amp; templates</h2>
              <p class="settings-panel-subtitle">Docker is configured per project on a dedicated page. Starting templates and legacy scaffolds (for example scraper-builder) are managed under Templates.</p>
            </div>

            <?php if (station_docker_enabled()): ?>
              <div class="project-runtime-card">
                <div>
                  <strong>Docker</strong>
                  <p class="setting-description" style="margin: 6px 0 0;">
                    <?php if ($dockerContainerized): ?>
                      This project is set to run in a container. Open the Docker page to change services, ports, or lifecycle actions.
                    <?php else: ?>
                      Container deployment is available but not enabled for this project yet.
                    <?php endif; ?>
                  </p>
                </div>
                <a class="btn-primary" href="docker-config.php?project=<?= urlencode($project) ?>"><?= $dockerContainerized ? 'Open Docker' : 'Enable containers' ?></a>
              </div>
            <?php else: ?>
              <p class="setting-description">Docker deployment is disabled station-wide. An owner can enable it under Admin Settings → Docker.</p>
              <?php if (station_is_owner($user)): ?>
                <a class="quick-link" href="admin-settings.php?tab=docker">Admin → Docker</a>
              <?php endif; ?>
            <?php endif; ?>

            <?php if (station_is_admin($user)): ?>
              <div class="project-runtime-card" style="margin-top: 16px;">
                <div>
                  <strong>Templates</strong>
                  <p class="setting-description" style="margin: 6px 0 0;">Create projects from stacks, edit built-in scaffolds, and duplicate custom templates — including the legacy scraper-builder layout and prompts.</p>
                </div>
                <a class="secondary-btn" href="template-manager.php">Open Templates</a>
              </div>
            <?php endif; ?>
          </section>

          <section id="administration" class="settings-panel">
            <div class="settings-panel-head">
              <h2 class="settings-panel-heading">Administration</h2>
              <p class="settings-panel-subtitle">Rename, access, backups, and lifecycle actions for <strong><?= station_h($project) ?></strong> (owner: <?= station_h($pOwner) ?>).</p>
            </div>

            <form method="post" action="station.php" class="admin-action-form">
              <input type="hidden" name="action" value="rename_project">
              <input type="hidden" name="project_slug" value="<?= station_h($project) ?>">
              <input type="hidden" name="return_to" value="settings">
              <div class="setting-item">
                <label class="setting-label" for="new_project_slug">Rename project URL</label>
                <div class="admin-inline-row">
                  <input id="new_project_slug" type="text" name="new_project_slug" placeholder="new-slug" required autocomplete="off">
                  <button type="submit" class="secondary-btn">Rename</button>
                </div>
              </div>
            </form>

            <form method="post" action="station.php" class="admin-action-form">
              <input type="hidden" name="action" value="set_access_mode">
              <input type="hidden" name="project_slug" value="<?= station_h($project) ?>">
              <input type="hidden" name="return_to" value="settings">
              <div class="setting-item">
                <label class="setting-label" for="access_mode">Who can open this project</label>
                <div class="admin-inline-row">
                  <select id="access_mode" name="access_mode">
                    <?php foreach ($accessModes as $val => $lbl): ?>
                      <?php if ($val !== 'public' || !empty($adminSettings['allowPublicProjects'])): ?>
                        <option value="<?= station_h($val) ?>" <?= $pAccess === $val ? 'selected' : '' ?>><?= station_h($lbl) ?></option>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="secondary-btn">Update access</button>
                </div>
              </div>
            </form>

            <form method="post" action="station.php" class="admin-action-form">
              <input type="hidden" name="action" value="claim_project_owner">
              <input type="hidden" name="project_slug" value="<?= station_h($project) ?>">
              <input type="hidden" name="return_to" value="settings">
              <button type="submit" class="secondary-btn">Set me as owner</button>
            </form>

            <div class="settings-panel-divider"></div>

            <h3 class="settings-subheading">Backups &amp; archive</h3>
            <div class="admin-action-buttons">
              <form method="post" action="backups.php" class="admin-action-form">
                <input type="hidden" name="action" value="backup_project">
                <input type="hidden" name="project" value="<?= station_h($project) ?>">
                <button type="submit" class="secondary-btn">Create backup</button>
              </form>
              <form method="post" action="backups.php" class="admin-action-form">
                <input type="hidden" name="action" value="archive_project">
                <input type="hidden" name="project" value="<?= station_h($project) ?>">
                <button type="submit" class="secondary-btn">Archive project</button>
              </form>
            </div>

            <?php if ($isStationOwner): ?>
            <div class="settings-panel-divider"></div>
            <h3 class="settings-subheading">Danger zone</h3>
            <form method="post" action="backups.php" class="admin-action-form"
                  onsubmit="return confirm('Permanently delete <?= station_h($project) ?>? This cannot be undone.');">
              <input type="hidden" name="action" value="delete_project">
              <input type="hidden" name="project" value="<?= station_h($project) ?>">
              <button type="submit" class="danger-btn">Delete forever</button>
            </form>
            <?php endif; ?>
          </section>
        </div>
      </form>
    </main>
  </div>
  <?= station_dashboard_nav_script_html() ?>
  <script>
    (function () {
      var shell = document.querySelector('.project-settings-shell');
      if (!shell) return;
      var navLinks = shell.querySelectorAll('.settings-nav-item');
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
    })();
  </script>
  <?= station_clipboard_fab_html() ?>
  <?= station_pwa_register_html() ?>
</body>
</html>
