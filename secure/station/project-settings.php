<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/docker.php';
require_once __DIR__ . '/lib/github-sync.php';
require_once __DIR__ . '/lib/project-launch.php';

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

function station_project_github_from_post(array $existingGithub): array
{
    $gh = is_array($existingGithub) ? $existingGithub : [];

    return array_merge($gh, [
        'repoOwner' => trim((string) ($_POST['repo_owner'] ?? '')),
        'repoName' => trim((string) ($_POST['repo_name'] ?? '')),
        'visibility' => ((string) ($_POST['repo_visibility'] ?? 'private')) === 'public' ? 'public' : 'private',
        'defaultBranch' => trim((string) ($_POST['default_branch'] ?? 'main')) ?: 'main',
        'releaseWorkflow' => !empty($_POST['release_workflow']),
    ]);
}

$username = station_current_username();
$profile = station_user_profile($username);
$ghProfile = isset($profile['integrations']['github']) && is_array($profile['integrations']['github'])
    ? $profile['integrations']['github']
    : [];
$githubToken = trim((string) ($ghProfile['token'] ?? ''));
$githubUserEnabled = !empty($ghProfile['enabled']);
$stationGithubOn = !empty(station_admin_settings()['githubEnabled']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === '' && (isset($_POST['app_port']) || isset($_POST['containerized']) || isset($_POST['embedded']))) {
        station_flash_set('error', 'Docker settings must be saved from the Docker panel (form was sent to the wrong handler). Reload the page and try again.');
        header('Location: project-settings.php?project=' . urlencode($project) . '#docker');
        exit;
    }
    if ($action === '') {
        $action = 'save_settings';
    }

    if ($action === 'github_sync' && $stationGithubOn && $githubUserEnabled && $githubToken !== '') {
        $syncAct = (string) ($_POST['github_sync_action'] ?? '');
        $redirect = 'project-settings.php?project=' . urlencode($project) . '#github';
        $run = static function (string $message, bool $ok = true) use ($redirect): void {
            station_flash_set($ok ? 'ok' : 'error', $message);
            header('Location: ' . $redirect);
            exit;
        };
        $settings['github'] = station_project_github_from_post($settings['github'] ?? []);
        if (!station_save_project_settings($project, $settings)) {
            $run('Could not save GitHub metadata before sync action.', false);
        }
        if ($syncAct === 'pull') {
            $r = station_github_project_git_pull($project, $githubToken);
            $run((string) ($r['message'] ?? 'Done'), !empty($r['ok']));
        }
        if ($syncAct === 'push') {
            $r = station_github_project_git_push($project, $githubToken);
            $run((string) ($r['message'] ?? 'Done'), !empty($r['ok']));
        }
        if ($syncAct === 'init_link') {
            if (!station_git_available()) {
                $run('Git is not available on this host.', false);
            }
            $r = station_github_project_init_and_link($project, $githubToken);
            $run((string) ($r['message'] ?? 'Done'), !empty($r['ok']));
        }
        if ($syncAct === 'create_repo_and_link') {
            if (!station_git_available()) {
                $run('Git is not available on this host.', false);
            }
            $gh = $settings['github'] ?? [];
            $owner = trim((string) ($gh['repoOwner'] ?? ''));
            $name = trim((string) ($gh['repoName'] ?? ''));
            if ($owner === '' || $name === '') {
                $run('Set repo owner and name in the form above, then try again.', false);
            }
            $cr = station_github_create_private_repo($githubToken, $owner, $name, 'Private mirror: ' . $project);
            if (empty($cr['ok'])) {
                $run((string) ($cr['message'] ?? 'Create repo failed'), false);
            }
            $il = station_github_project_init_and_link($project, $githubToken);
            $run('Repository created, then: ' . ($il['message'] ?? ''), !empty($il['ok']));
        }
        if ($syncAct === 'pull_redeploy') {
            $pull = station_github_project_git_pull($project, $githubToken);
            if (empty($pull['ok'])) {
                $run((string) ($pull['message'] ?? 'Pull failed'), false);
            }
            $rd = station_github_project_redeploy_after_pull($project);
            $run(($pull['message'] ?? 'Pulled.') . ' ' . ($rd['message'] ?? ''), !empty($rd['ok']));
        }
        $run('Unknown GitHub action.', false);
    }

    if ($action === 'save_web_entrypoint') {
        $dir = trim(str_replace('\\', '/', (string) ($_POST['web_entry_dir'] ?? '')), '/');
        $file = trim((string) ($_POST['web_entry_file'] ?? '')) ?: 'index.html';
        $check = station_project_validate_web_entry_input($project, $dir, $file);
        if (empty($check['ok'])) {
            station_flash_set('error', (string) ($check['message'] ?? 'Invalid entrypoint.'));
        } else {
            $settings['launch'] = [
                'webEntryManual' => true,
                'webEntryDir' => (string) ($check['dir'] ?? $dir),
                'webEntryFile' => (string) ($check['file'] ?? $file),
            ];
            if (station_save_project_settings($project, $settings)) {
                station_flash_set('ok', 'Web entrypoint saved: ' . ($settings['launch']['webEntryDir'] !== ''
                    ? $settings['launch']['webEntryDir'] . '/'
                    : '') . $settings['launch']['webEntryFile']);
            } else {
                station_flash_set('error', 'Could not save entrypoint settings.');
            }
        }
        header('Location: project-settings.php?project=' . urlencode($project) . '#general');
        exit;
    }

    if ($action === 'clear_web_entrypoint') {
        $settings['launch'] = [
            'webEntryManual' => false,
            'webEntryDir' => '',
            'webEntryFile' => 'index.html',
        ];
        if (station_save_project_settings($project, $settings)) {
            station_flash_set('ok', 'Manual web entrypoint cleared — auto-detection is used again.');
        } else {
            station_flash_set('error', 'Could not clear entrypoint settings.');
        }
        header('Location: project-settings.php?project=' . urlencode($project) . '#general');
        exit;
    }

    if ($action === 'save_settings' || $action === 'save_github') {
        if ($action === 'save_settings') {
            $settings['environment'] = station_parse_env_text((string) ($_POST['environment_text'] ?? ''));
            $settings['notes'] = trim((string) ($_POST['notes'] ?? ''));
            $settings['github'] = station_project_github_from_post($settings['github'] ?? []);
        } else {
            $settings['github'] = station_project_github_from_post($settings['github'] ?? []);
        }

        $redirectHash = $action === 'save_github' ? 'github' : 'general';
        if (!station_save_project_settings($project, $settings)) {
            $error = $action === 'save_github'
                ? 'Could not save GitHub metadata (check data directory permissions).'
                : 'Could not save project settings.';
        } else {
            station_log_event('project.settings.saved', ['project' => $project, 'section' => $action]);
            $msg = $action === 'save_github' ? 'GitHub metadata saved.' : 'Project settings saved.';
            if ($action === 'save_settings' && !station_write_env_file($project, $settings['environment'])) {
                station_flash_set('warning', $msg . ' (.env.local could not be written — check project folder permissions.)');
            } else {
                station_flash_set('ok', $msg);
            }
            header('Location: project-settings.php?project=' . urlencode($project) . '#' . $redirectHash);
            exit;
        }
    }

    if ($action === 'generate_github') {
        $settings['github'] = station_project_github_from_post($settings['github'] ?? []);
        if (!station_save_project_settings($project, $settings)) {
            $error = 'Could not save GitHub metadata before generating files.';
        } else {
            $result = station_generate_github_bootstrap_files($project, $settings);
            if (!empty($result['ok'])) {
                station_log_event('github.bootstrap.generated', ['project' => $project]);
                station_flash_set('ok', (string) ($result['message'] ?? 'GitHub files generated.'));
                header('Location: project-settings.php?project=' . urlencode($project) . '#github');
                exit;
            }
            $error = (string) ($result['message'] ?? 'GitHub generation failed.');
        }
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
$launchProfile = station_project_launch_profile($project);
$launchCfg = station_project_launch_config($settings);
$webEntryResolved = station_project_resolve_web_entry($project);
$githubSyncRow = ($stationGithubOn && $githubUserEnabled && $githubToken !== '')
    ? station_github_project_sync_row($project, $githubToken)
    : null;
$canGithubSync = $githubSyncRow !== null && station_github_user_may_sync_project($user, $project);
?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('Project Settings — ' . station_h($project), 'Environment variables, repository metadata, and deployment notes for this project.', 'assets/style.css?v=20260518b') ?>
</head>
<body class="station-body">
  <div class="dashboard-shell">
    <?= station_dashboard_nav_html('dashboard') ?>
    <main class="dashboard-main">
      <header class="dashboard-topbar">
        <div>
          <p class="dashboard-kicker">Project</p>
          <h1 class="dashboard-heading"><?= station_h($project) ?></h1>
          <p class="dashboard-subheading">Environment, GitHub, Docker, and administration for this deployment.</p>
        </div>
        <nav class="nav-pills">
          <a href="station.php">Dashboard</a>
          <a href="viewer.php?project=<?= urlencode($project) ?>">Files</a>
          <a href="launch.php?project=<?= urlencode($project) ?>" target="_blank" rel="noreferrer">Launch ↗</a>
          <?php if (station_is_admin($user)): ?>
            <a href="template-manager.php">Templates</a>
          <?php endif; ?>
        </nav>
      </header>

      <?= station_flash_banners_html() ?>
      <?php if ($error !== ''): ?><div class="alert error" style="margin-bottom:16px;"><?= station_h($error) ?></div><?php endif; ?>

      <div class="settings-shell project-settings-hub">
        <nav class="settings-nav" aria-label="Project settings sections">
          <a class="settings-nav-item active" href="#general"><span class="settings-nav-icon">⚙</span><span>General</span></a>
          <a class="settings-nav-item" href="#github"><span class="settings-nav-icon">🐙</span><span>GitHub</span></a>
          <?php if (station_docker_enabled()): ?>
          <a class="settings-nav-item" href="#docker"><span class="settings-nav-icon">🐳</span><span>Docker</span></a>
          <?php endif; ?>
          <a class="settings-nav-item" href="#administration"><span class="settings-nav-icon">🛡</span><span>Administration</span></a>
        </nav>

        <div class="settings-content project-settings-panels">
          <form method="post" id="project-metadata-form" class="project-metadata-form">
            <input type="hidden" name="project" value="<?= station_h($project) ?>">

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
              <div class="setting-item web-entrypoint-block" id="web-entrypoint">
                <h3 class="settings-subheading">Web entrypoint</h3>
                <p class="setting-description">
                  For repos where <code>index.html</code> lives in a subfolder (common with GitHub imports).
                  Optional — set manually; DeployStation will not guess a subdirectory.
                </p>
                <?php if ($webEntryResolved !== null): ?>
                  <p class="web-entrypoint-status web-entrypoint-status-ok">
                    Active: <code><?= station_h((string) $webEntryResolved['relative']) ?></code>
                    · <a href="<?= station_h(station_project_public_web_path($project)) ?>" target="_blank" rel="noreferrer">Open site ↗</a>
                  </p>
                <?php elseif ($launchCfg['webEntryManual']): ?>
                  <p class="web-entrypoint-status web-entrypoint-status-warn">Saved path missing on disk — select again.</p>
                <?php else: ?>
                  <p class="web-entrypoint-status"><?= station_h((string) ($launchProfile['summary'] ?? 'Auto-detect only')) ?></p>
                <?php endif; ?>
                <div class="web-entrypoint-actions">
                  <button type="button" class="btn-primary" id="openEntrypointPicker">Select entrypoint…</button>
                  <?php if ($launchCfg['webEntryManual']): ?>
                    <button type="submit" class="secondary-btn" name="action" value="clear_web_entrypoint" formnovalidate>Clear entrypoint</button>
                  <?php endif; ?>
                </div>
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
              <input type="checkbox" name="release_workflow" value="1" <?= !empty($settings['github']['releaseWorkflow']) ? 'checked' : '' ?>>
              <div class="feature-toggle-content">
                <span class="feature-toggle-title">Generate release workflow</span>
                <span class="feature-toggle-desc">Adds a GitHub Actions workflow file tailored to this repo name when you click generate below.</span>
              </div>
            </label>

            <div class="settings-form-actions" style="margin-top: 20px;">
              <button type="submit" class="btn-primary" name="action" value="save_github">Save GitHub metadata</button>
            </div>

            <div class="settings-panel-divider"></div>
            <h3 class="settings-subheading">Bootstrap files</h3>
            <p class="setting-description">Writes supporting files (e.g. CI workflow stubs) into the project directory based on the fields above.</p>
            <div class="settings-form-actions">
              <button type="submit" class="secondary-btn" name="action" value="generate_github" onclick="return confirm('Generate or overwrite bootstrap files in this project?');">Generate GitHub files</button>
            </div>
          </section>
          </form>

            <?php if ($canGithubSync && is_array($githubSyncRow)): ?>
            <section id="github-sync" class="settings-panel">
            <div class="settings-panel-head">
              <h2 class="settings-panel-heading">Repository sync</h2>
              <p class="settings-panel-subtitle">Pull, push, init, or create the GitHub repo. Owner and repo name are taken from the GitHub fields above when you click an action.</p>
            </div>
            <?php
              $linked = trim((string) ($githubSyncRow['repoOwner'] ?? '')) !== '' && trim((string) ($githubSyncRow['repoName'] ?? '')) !== '';
              $behind = (int) ($githubSyncRow['behind'] ?? 0);
              $ahead = (int) ($githubSyncRow['ahead'] ?? 0);
            ?>
            <div class="gh-sync-status-card">
              <p class="setting-description">
                <?php if ($linked): ?>
                  Linked to <strong><?= station_h($githubSyncRow['repoOwner'] . '/' . $githubSyncRow['repoName']) ?></strong>
                  on branch <code><?= station_h((string) ($githubSyncRow['branch'] ?? 'main')) ?></code>.
                  <?php if (!empty($githubSyncRow['has_git'])): ?>
                    <?php if ($behind > 0): ?><span class="gh-sync-pill gh-pill-warn"><?= $behind ?> behind GitHub</span><?php endif; ?>
                    <?php if ($ahead > 0): ?><span class="gh-sync-pill gh-pill-ok"><?= $ahead ?> ahead (push)</span><?php endif; ?>
                    <?php if (!empty($githubSyncRow['dirty'])): ?>
                      <span class="gh-sync-pill gh-pill-warn" title="<?= station_h((string) ($githubSyncRow['dirty_preview'] ?? '')) ?>">Modified tracked files (<?= (int) ($githubSyncRow['dirty_count'] ?? 0) ?>)</span>
                    <?php elseif (!empty($githubSyncRow['has_untracked'])): ?>
                      <span class="gh-sync-pill gh-pill-muted" title="<?= station_h((string) ($githubSyncRow['untracked_preview'] ?? '')) ?>"><?= (int) ($githubSyncRow['untracked_count'] ?? 0) ?> untracked file<?= ((int) ($githubSyncRow['untracked_count'] ?? 0)) === 1 ? '' : 's' ?> (not on GitHub yet)</span>
                    <?php endif; ?>
                    <?php if ((int) ($githubSyncRow['untracked_ignored_count'] ?? 0) > 0): ?>
                      <span class="gh-sync-pill gh-pill-muted"><?= (int) $githubSyncRow['untracked_ignored_count'] ?> local-only (.env.local)</span>
                    <?php endif; ?>
                    <?php if ($behind === 0 && $ahead === 0 && empty($githubSyncRow['dirty'])): ?><span class="gh-sync-pill gh-pill-ok">In sync with GitHub</span><?php endif; ?>
                  <?php else: ?>
                    <span class="gh-sync-pill gh-pill-warn">No local git — init &amp; link below</span>
                  <?php endif; ?>
                <?php else: ?>
                  Set owner and repository in the GitHub section above, then use an action below.
                <?php endif; ?>
              </p>
              <?php if ((string) ($githubSyncRow['error'] ?? '') !== ''): ?>
                <p class="gh-sync-err"><?= station_h((string) $githubSyncRow['error']) ?></p>
              <?php endif; ?>
              <div class="gh-sync-toolbar" style="margin-top: 12px;">
                <form method="post" class="gh-sync-form"><input type="hidden" name="project" value="<?= station_h($project) ?>"><input type="hidden" name="repo_owner" value="" data-gh-sync-field="repo_owner"><input type="hidden" name="repo_name" value="" data-gh-sync-field="repo_name"><input type="hidden" name="repo_visibility" value="" data-gh-sync-field="repo_visibility"><input type="hidden" name="default_branch" value="" data-gh-sync-field="default_branch"><input type="hidden" name="action" value="github_sync"><input type="hidden" name="github_sync_action" value="pull"><button type="submit" class="secondary-btn">Pull</button></form>
                <form method="post" class="gh-sync-form"><input type="hidden" name="project" value="<?= station_h($project) ?>"><input type="hidden" name="repo_owner" value="" data-gh-sync-field="repo_owner"><input type="hidden" name="repo_name" value="" data-gh-sync-field="repo_name"><input type="hidden" name="repo_visibility" value="" data-gh-sync-field="repo_visibility"><input type="hidden" name="default_branch" value="" data-gh-sync-field="default_branch"><input type="hidden" name="action" value="github_sync"><input type="hidden" name="github_sync_action" value="push"><button type="submit" class="secondary-btn">Push</button></form>
                <form method="post" class="gh-sync-form"><input type="hidden" name="project" value="<?= station_h($project) ?>"><input type="hidden" name="repo_owner" value="" data-gh-sync-field="repo_owner"><input type="hidden" name="repo_name" value="" data-gh-sync-field="repo_name"><input type="hidden" name="repo_visibility" value="" data-gh-sync-field="repo_visibility"><input type="hidden" name="default_branch" value="" data-gh-sync-field="default_branch"><input type="hidden" name="action" value="github_sync"><input type="hidden" name="github_sync_action" value="pull_redeploy"><button type="submit" class="secondary-btn">Pull &amp; redeploy</button></form>
                <?php if (empty($githubSyncRow['has_git'])): ?>
                <form method="post" class="gh-sync-form"><input type="hidden" name="project" value="<?= station_h($project) ?>"><input type="hidden" name="repo_owner" value="" data-gh-sync-field="repo_owner"><input type="hidden" name="repo_name" value="" data-gh-sync-field="repo_name"><input type="hidden" name="repo_visibility" value="" data-gh-sync-field="repo_visibility"><input type="hidden" name="default_branch" value="" data-gh-sync-field="default_branch"><input type="hidden" name="action" value="github_sync"><input type="hidden" name="github_sync_action" value="init_link"><button type="submit" class="btn-primary">Init &amp; link</button></form>
                <?php endif; ?>
                <form method="post" class="gh-sync-form" onsubmit="return confirm('Create private repo and push?');"><input type="hidden" name="project" value="<?= station_h($project) ?>"><input type="hidden" name="repo_owner" value="" data-gh-sync-field="repo_owner"><input type="hidden" name="repo_name" value="" data-gh-sync-field="repo_name"><input type="hidden" name="repo_visibility" value="" data-gh-sync-field="repo_visibility"><input type="hidden" name="default_branch" value="" data-gh-sync-field="default_branch"><input type="hidden" name="action" value="github_sync"><input type="hidden" name="github_sync_action" value="create_repo_and_link"><button type="submit" class="secondary-btn">Create private repo &amp; push</button></form>
              </div>
            </div>
            <?php elseif ($stationGithubOn && !$githubUserEnabled): ?>
              <p class="setting-description" style="margin-top:12px;">Enable GitHub in <a href="user-settings.php">User Settings</a>.</p>
            <?php elseif ($stationGithubOn && $githubToken === ''): ?>
              <p class="setting-description" style="margin-top:12px;">Connect GitHub in <a href="user-settings.php">User Settings</a>.</p>
            <?php endif; ?>
            </section>


          <?php if (station_docker_enabled()): ?>
          <section id="docker" class="settings-panel project-docker-embed">
            <div class="settings-panel-head">
              <h2 class="settings-panel-heading">Docker</h2>
              <p class="settings-panel-subtitle">Docker settings and status for this app.</p>
            </div>
            <?php
              $_GET['embedded'] = '1';
              $_REQUEST['embedded'] = '1';
              $_GET['project'] = $project;
              $projectSlug = $project;
              require __DIR__ . '/docker-config.php';
            ?>
          </section>
          <?php endif; ?>

          <section id="administration" class="settings-panel project-admin-panel">
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
      </div>
    </main>
  </div>

  <div id="entrypointModal" class="entrypoint-modal" hidden aria-hidden="true">
    <div class="entrypoint-modal-backdrop" data-entrypoint-close></div>
    <div class="entrypoint-modal-dialog" role="dialog" aria-labelledby="entrypointModalTitle" aria-modal="true">
      <header class="entrypoint-modal-head">
        <h2 id="entrypointModalTitle">Select web entrypoint</h2>
        <button type="button" class="entrypoint-modal-close" data-entrypoint-close aria-label="Close">×</button>
      </header>
      <p class="setting-description">Open a folder, then choose <code>index.html</code>, <code>index.htm</code>, or <code>index.php</code> in that folder.</p>
      <nav class="entrypoint-breadcrumb" id="entrypointBreadcrumb" aria-label="Path"></nav>
      <div class="entrypoint-list" id="entrypointList"></div>
      <p class="entrypoint-picker-status" id="entrypointPickerStatus"></p>
      <form method="post" class="entrypoint-modal-actions">
        <input type="hidden" name="project" value="<?= station_h($project) ?>">
        <input type="hidden" name="action" value="save_web_entrypoint">
        <input type="hidden" name="web_entry_dir" id="webEntryDir" value="">
        <input type="hidden" name="web_entry_file" id="webEntryFile" value="index.html">
        <button type="button" class="secondary-btn" data-entrypoint-close>Cancel</button>
        <button type="submit" class="btn-primary" id="entrypointSaveBtn" disabled>Use selected index</button>
      </form>
    </div>
  </div>

  <script>window.STATION_ENTRYPOINT_PICKER = <?= json_encode(['project' => $project], JSON_UNESCAPED_SLASHES) ?>;</script>
  <script src="assets/project-entrypoint-picker.js?v=20260518b"></script>
  <?= station_dashboard_nav_script_html() ?>
  <script>
    (function () {
      var navLinks = document.querySelectorAll('.project-settings-hub .settings-nav-item');
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

      document.querySelectorAll('.gh-sync-form').forEach(function (form) {
        form.addEventListener('submit', function () {
          ['repo_owner', 'repo_name', 'repo_visibility', 'default_branch'].forEach(function (field) {
            var src = document.getElementById(field);
            var hid = form.querySelector('[data-gh-sync-field="' + field + '"]');
            if (src && hid) {
              hid.value = src.value;
            }
          });
        });
      });
    })();
  </script>
  <?= station_clipboard_fab_html() ?>
  <?= station_pwa_register_html() ?>
</body>
</html>
