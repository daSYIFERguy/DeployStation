<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/github-sync.php';

station_require_builder();

$user = station_current_user();
$username = station_current_username();
$profile = station_user_profile($username);
$adminSettings = station_admin_settings();
$stationGithubOn = !empty($adminSettings['githubEnabled']);
$ghProfile = isset($profile['integrations']['github']) && is_array($profile['integrations']['github'])
    ? $profile['integrations']['github']
    : [];
$token = trim((string) ($ghProfile['token'] ?? ''));
$githubUserEnabled = !empty($ghProfile['enabled']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $stationGithubOn && $githubUserEnabled && $token !== '') {
    $act = (string) ($_POST['github_sync_action'] ?? '');
    $slug = station_safe_name(trim((string) ($_POST['slug'] ?? '')));

    $run = static function (string $message, bool $ok = true): void {
        station_flash_set($ok ? 'ok' : 'error', $message);
        header('Location: github-sync.php');
        exit;
    };

    if ($act === 'refresh_all') {
        $run('Refreshed sync status for all projects you can manage.');
    }

    if ($slug === '' || !station_github_user_may_sync_project($user, $slug)) {
        $run('Unknown project or no permission to sync that slug.', false);
    }

    if ($act === 'pull') {
        $r = station_github_project_git_pull($slug, $token);
        $run(($r['message'] ?? 'Done') . '', !empty($r['ok']));
    }
    if ($act === 'push') {
        $r = station_github_project_git_push($slug, $token);
        $run(($r['message'] ?? 'Done') . '', !empty($r['ok']));
    }
    if ($act === 'init_link') {
        if (!station_git_available()) {
            $run('Git is not available on this host (install git).', false);
        }
        $r = station_github_project_init_and_link($slug, $token);
        $run(($r['message'] ?? 'Done') . '', !empty($r['ok']));
    }
    if ($act === 'create_repo') {
        $settings = station_project_settings($slug);
        $gh = isset($settings['github']) && is_array($settings['github']) ? $settings['github'] : [];
        $owner = trim((string) ($gh['repoOwner'] ?? ''));
        $name = trim((string) ($gh['repoName'] ?? ''));
        if ($owner === '' || $name === '') {
            $run('Set repo owner and name under Project → GitHub before creating a repository.', false);
        }
        $r = station_github_create_private_repo($token, $owner, $name, 'Private mirror: ' . $slug);
        $run(($r['message'] ?? 'Done') . (isset($r['html_url']) && $r['html_url'] !== '' ? ' ' . $r['html_url'] : ''), !empty($r['ok']));
    }
    if ($act === 'create_repo_and_link') {
        if (!station_git_available()) {
            $run('Git is not available on this host (install git).', false);
        }
        $settings = station_project_settings($slug);
        $gh = isset($settings['github']) && is_array($settings['github']) ? $settings['github'] : [];
        $owner = trim((string) ($gh['repoOwner'] ?? ''));
        $name = trim((string) ($gh['repoName'] ?? ''));
        if ($owner === '' || $name === '') {
            $run('Set repo owner and name under Project → GitHub first.', false);
        }
        $cr = station_github_create_private_repo($token, $owner, $name, 'Private mirror: ' . $slug);
        if (empty($cr['ok'])) {
            $run((string) ($cr['message'] ?? 'Create repo failed'), false);
        }
        $il = station_github_project_init_and_link($slug, $token);
        $run(
            'Repository created, then: ' . ($il['message'] ?? ''),
            !empty($il['ok'])
        );
    }
    if ($act === 'pull_redeploy') {
        $pull = station_github_project_git_pull($slug, $token);
        if (empty($pull['ok'])) {
            $run((string) ($pull['message'] ?? 'Pull failed'), false);
        }
        $rd = station_github_project_redeploy_after_pull($slug);
        $run(
            ($pull['message'] ?? 'Pulled.') . ' ' . ($rd['message'] ?? ''),
            !empty($rd['ok'])
        );
    }

    $run('Unknown action.', false);
}

$syncRows = [];
if ($stationGithubOn && $githubUserEnabled && $token !== '') {
    foreach (station_list_projects() as $p) {
        $slug = station_safe_name((string) ($p['slug'] ?? ''));
        if ($slug === '' || !station_github_user_may_sync_project($user, $slug)) {
            continue;
        }
        $syncRows[] = station_github_project_sync_row($slug, $token);
    }
}

?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('GitHub sync', 'Push, pull, and private-repo workflows for your projects.') ?>
</head>
<body class="station-body">
  <div class="dashboard-shell">
    <?= station_dashboard_nav_html('github_sync') ?>
    <main class="dashboard-main">
      <header class="dashboard-topbar">
        <div>
          <p class="dashboard-kicker">Builders</p>
          <h1 class="dashboard-heading">GitHub sync</h1>
          <p class="dashboard-subheading">Scan every project you can manage: compare with GitHub, pull latest, push local commits, create a <strong>private</strong> empty repository, or pull and refresh Docker. Open PRs on GitHub when histories diverge.</p>
        </div>
        <nav class="nav-pills">
          <a href="station.php">Dashboard</a>
          <a href="user-settings.php">User settings</a>
        </nav>
      </header>

      <?= station_flash_banners_html() ?>

      <?php if (!$stationGithubOn): ?>
        <div class="settings-panel gh-sync-panel" style="margin-top:16px;">
          <p class="setting-description">GitHub integration is turned off in admin settings. Ask the station owner to enable it under <a href="admin-settings.php?tab=github">Admin → GitHub</a>.</p>
        </div>
      <?php elseif (!$githubUserEnabled || $token === ''): ?>
        <div class="settings-panel gh-sync-panel" style="margin-top:16px;">
          <p class="setting-description">Enable GitHub for your account and paste a personal access token under <a href="user-settings.php">User settings → GitHub</a> (scopes: <code>repo</code> for private repositories).</p>
        </div>
      <?php else: ?>
        <div class="gh-sync-toolbar">
          <form method="post">
            <input type="hidden" name="github_sync_action" value="refresh_all">
            <button type="submit" class="secondary-btn">Refresh all rows</button>
          </form>
          <p class="gh-sync-toolbar-hint">Each row runs <code>git fetch</code> and lists open pull requests from the GitHub API (up to 30).</p>
        </div>

        <div class="settings-panel gh-sync-panel" style="margin-top:16px;">
          <?php if ($syncRows === []): ?>
            <p class="setting-description">No projects visible to your account, or none exist yet.</p>
          <?php else: ?>
            <div class="gh-sync-table-wrap">
              <table class="gh-sync-table">
                <thead>
                  <tr>
                    <th>Project</th>
                    <th>Repo</th>
                    <th>Git / delta</th>
                    <th>Open PRs</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($syncRows as $row):
                      $slug = (string) ($row['slug'] ?? '');
                      $own = (string) ($row['repoOwner'] ?? '');
                      $nm = (string) ($row['repoName'] ?? '');
                      $br = (string) ($row['branch'] ?? 'main');
                      $err = (string) ($row['error'] ?? '');
                      $ahead = (int) ($row['ahead'] ?? 0);
                      $behind = (int) ($row['behind'] ?? 0);
                      $dirty = !empty($row['dirty']);
                      $untracked = (int) ($row['untracked_count'] ?? 0);
                      $ignoredLocal = (int) ($row['untracked_ignored_count'] ?? 0);
                      ?>
                    <tr>
                      <td data-label="Project">
                        <strong><a href="project-settings.php?project=<?= station_h(rawurlencode($slug)) ?>"><?= station_h($slug) ?></a></strong>
                        <?php if ($err !== ''): ?>
                          <div class="gh-sync-err"><?= station_h($err) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($row['pr_api_error'])): ?>
                          <div class="gh-sync-warn"><?= station_h((string) $row['pr_api_error']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td data-label="Repo">
                        <?php if ($own !== '' && $nm !== ''): ?>
                          <code><?= station_h($own . '/' . $nm) ?></code>
                          <div class="gh-sync-repo-meta">branch <code><?= station_h($br) ?></code></div>
                          <div class="gh-sync-links">
                            <a href="<?= station_h((string) ($row['prs_url'] ?? '#')) ?>" target="_blank" rel="noreferrer">Pull requests</a>
                            · <a href="<?= station_h((string) ($row['compare_url'] ?? '#')) ?>" target="_blank" rel="noreferrer">Compare</a>
                          </div>
                        <?php else: ?>
                          <span class="host-health-meta">Configure under Project → GitHub</span>
                        <?php endif; ?>
                      </td>
                      <td data-label="Delta">
                        <?php if (!empty($row['has_git']) && $err === ''): ?>
                          <?php if ($dirty): ?>
                            <span class="gh-sync-pill gh-pill-warn" title="<?= station_h((string) ($row['dirty_preview'] ?? '')) ?>">modified (<?= (int) ($row['dirty_count'] ?? 0) ?>)</span>
                          <?php elseif ($untracked > 0): ?>
                            <span class="gh-sync-pill gh-pill-muted" title="<?= station_h((string) ($row['untracked_preview'] ?? '')) ?>">untracked (<?= $untracked ?>)</span>
                          <?php else: ?>
                            <span class="gh-sync-pill gh-pill-ok">clean</span>
                          <?php endif; ?>
                          <div class="gh-sync-delta">behind <strong><?= $behind ?></strong> · ahead <strong><?= $ahead ?></strong></div>
                          <?php if ($ignoredLocal > 0): ?>
                            <p class="gh-sync-hint"><?= $ignoredLocal ?> local-only file<?= $ignoredLocal === 1 ? '' : 's' ?> (.env.local, etc.) — not counted as dirty.</p>
                          <?php endif; ?>
                          <?php if ($dirty && (string) ($row['dirty_preview'] ?? '') !== ''): ?>
                            <p class="gh-sync-hint"><code><?= station_h((string) $row['dirty_preview']) ?></code></p>
                          <?php elseif ($untracked > 0 && (string) ($row['untracked_preview'] ?? '') !== ''): ?>
                            <p class="gh-sync-hint"><code><?= station_h((string) $row['untracked_preview']) ?></code></p>
                          <?php endif; ?>
                          <?php if ($ahead > 0 && $behind > 0): ?>
                            <p class="gh-sync-hint">Diverged: resolve on GitHub (PR / merge) or reset locally with care.</p>
                          <?php elseif ($behind > 0): ?>
                            <p class="gh-sync-hint">Remote has new commits — pull or review open PRs.</p>
                          <?php elseif ($ahead > 0): ?>
                            <p class="gh-sync-hint">Local commits not on origin — push, then open a PR from GitHub if needed.</p>
                          <?php endif; ?>
                        <?php elseif ($own !== '' && $nm !== ''): ?>
                          <span class="gh-sync-pill gh-pill-muted">no .git</span>
                        <?php else: ?>
                          —
                        <?php endif; ?>
                      </td>
                      <td data-label="PRs">
                        <?php
                          $opc = (int) ($row['open_pr_count'] ?? 0);
                        $prs = isset($row['open_prs']) && is_array($row['open_prs']) ? $row['open_prs'] : [];
                        $trunc = !empty($row['open_prs_truncated']);
                        ?>
                        <?php if ($own !== '' && $nm !== '' && $err === ''): ?>
                          <div class="gh-sync-pr-count"><?= $opc ?><?= $trunc ? '+' : '' ?> open<?= $trunc ? ' (first 30)' : '' ?></div>
                          <ul class="gh-sync-pr-list">
                            <?php foreach ($prs as $pr): ?>
                              <li><a href="<?= station_h((string) ($pr['url'] ?? '#')) ?>" target="_blank" rel="noreferrer">#<?= (int) ($pr['number'] ?? 0) ?> <?= station_h(mb_substr((string) ($pr['title'] ?? ''), 0, 72)) ?></a></li>
                            <?php endforeach; ?>
                          </ul>
                        <?php else: ?>
                          —
                        <?php endif; ?>
                      </td>
                      <td data-label="Actions" class="gh-sync-actions">
                        <?php if ($slug !== ''): ?>
                          <form method="post" class="gh-sync-form">
                            <input type="hidden" name="slug" value="<?= station_h($slug) ?>">
                            <input type="hidden" name="github_sync_action" value="pull">
                            <button type="submit" class="secondary-btn" <?= ($own === '' || $nm === '' || empty($row['has_git'])) ? 'disabled' : '' ?>>Pull</button>
                          </form>
                          <form method="post" class="gh-sync-form">
                            <input type="hidden" name="slug" value="<?= station_h($slug) ?>">
                            <input type="hidden" name="github_sync_action" value="push">
                            <button type="submit" class="secondary-btn" <?= ($own === '' || $nm === '' || empty($row['has_git']) || $dirty) ? 'disabled' : '' ?>>Push</button>
                          </form>
                          <form method="post" class="gh-sync-form" onsubmit="return confirm('Create an empty private repo on GitHub if it does not exist?');">
                            <input type="hidden" name="slug" value="<?= station_h($slug) ?>">
                            <input type="hidden" name="github_sync_action" value="create_repo">
                            <button type="submit" class="secondary-btn" <?= ($own === '' || $nm === '') ? 'disabled' : '' ?>>Create private repo</button>
                          </form>
                          <form method="post" class="gh-sync-form" onsubmit="return confirm('Create private repo (if missing) then git init, commit, and push?');">
                            <input type="hidden" name="slug" value="<?= station_h($slug) ?>">
                            <input type="hidden" name="github_sync_action" value="create_repo_and_link">
                            <button type="submit" class="secondary-btn" <?= ($own === '' || $nm === '') ? 'disabled' : '' ?>>Create + init &amp; push</button>
                          </form>
                          <form method="post" class="gh-sync-form">
                            <input type="hidden" name="slug" value="<?= station_h($slug) ?>">
                            <input type="hidden" name="github_sync_action" value="init_link">
                            <button type="submit" class="secondary-btn" <?= ($own === '' || $nm === '') ? 'disabled' : '' ?>>Init &amp; link</button>
                          </form>
                          <form method="post" class="gh-sync-form" onsubmit="return confirm('Pull latest from GitHub, then docker compose pull && up -d for this project (if containerized)?');">
                            <input type="hidden" name="slug" value="<?= station_h($slug) ?>">
                            <input type="hidden" name="github_sync_action" value="pull_redeploy">
                            <button type="submit" class="secondary-btn" <?= ($own === '' || $nm === '' || empty($row['has_git'])) ? 'disabled' : '' ?>>Pull + redeploy</button>
                          </form>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </main>
  </div>
  <?= station_dashboard_nav_script_html() ?>
  <?= station_pwa_register_html() ?>
</body>
</html>
