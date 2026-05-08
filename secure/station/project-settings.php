<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';

station_require_builder();

$user = station_current_user();
$project = station_safe_name((string) ($_GET['project'] ?? $_POST['project'] ?? ''));
if ($project === '' || !station_project_exists($project)) {
    station_flash_set('error', 'Project not found.');
    header('Location: station.php');
    exit;
}

if (!station_can_access_project($user, station_project_access_mode($project))) {
    station_flash_set('error', 'You do not have access to manage that project.');
    header('Location: station.php');
    exit;
}

$settings = station_project_settings($project);
$error = '';
$ok = station_flash_get('ok');

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
        $settings['scraperBuilder']['targetDomain'] = trim((string) ($_POST['target_domain'] ?? ''));
        $settings['scraperBuilder']['useCase'] = trim((string) ($_POST['use_case'] ?? ''));

        if (station_save_project_settings($project, $settings) && station_write_env_file($project, $settings['environment'])) {
            station_log_event('project.settings.saved', ['project' => $project]);
            station_flash_set('ok', 'Project settings saved.');
            header('Location: project-settings.php?project=' . urlencode($project));
            exit;
        }
        $error = 'Could not save project settings.';
    }

    if ($action === 'add_preset') {
        $name = trim((string) ($_POST['preset_name'] ?? ''));
        $prompt = trim((string) ($_POST['preset_prompt'] ?? ''));
        if ($name === '' || $prompt === '') {
            $error = 'Preset name and prompt are required.';
        } else {
            $presets = isset($settings['scraperBuilder']['presets']) && is_array($settings['scraperBuilder']['presets']) ? $settings['scraperBuilder']['presets'] : [];
            $presets[] = [
                'name' => $name,
                'prompt' => $prompt,
                'createdAt' => gmdate('c')
            ];
            $settings['scraperBuilder']['presets'] = $presets;
            station_save_project_settings($project, $settings);
            station_log_event('scraper.preset.added', ['project' => $project, 'name' => $name]);
            station_flash_set('ok', 'Prompt preset added.');
            header('Location: project-settings.php?project=' . urlencode($project));
            exit;
        }
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

$presets = isset($settings['scraperBuilder']['presets']) && is_array($settings['scraperBuilder']['presets']) ? $settings['scraperBuilder']['presets'] : [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Project Settings</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body class="station-body">
  <main class="station-shell">
    <header class="topbar card">
      <div>
        <p class="kicker">Project Control</p>
        <h1><?= station_h($project) ?></h1>
        <p>Environment variables, GitHub bootstrap, scraper prompt wizard, and notes.</p>
      </div>
      <nav class="nav-pills">
        <a href="station.php">Dashboard</a>
        <a href="viewer.php?project=<?= urlencode($project) ?>">Viewer</a>
        <a href="launch.php?project=<?= urlencode($project) ?>" target="_blank" rel="noreferrer">Launch</a>
      </nav>
    </header>

    <?php if ($ok !== ''): ?><div class="alert ok"><?= station_h($ok) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert error"><?= station_h($error) ?></div><?php endif; ?>

    <section class="grid-two">
      <form method="post" class="card form-grid">
        <input type="hidden" name="action" value="save_settings">
        <input type="hidden" name="project" value="<?= station_h($project) ?>">
        <h2>Environment + Notes</h2>
        <label>Environment Variables
          <textarea name="environment_text" rows="14" placeholder="API_URL=https://example.com&#10;API_KEY=secret"><?= station_h(station_env_text($settings['environment'] ?? [])) ?></textarea>
        </label>
        <label>Notes
          <textarea name="notes" rows="8"><?= station_h((string) ($settings['notes'] ?? '')) ?></textarea>
        </label>
        <button type="submit">Save Project Settings</button>
      </form>

      <form method="post" class="card form-grid">
        <input type="hidden" name="action" value="generate_github">
        <input type="hidden" name="project" value="<?= station_h($project) ?>">
        <h2>GitHub Repo Bootstrap</h2>
        <label>Repo Owner
          <input type="text" name="repo_owner" value="<?= station_h((string) ($settings['github']['repoOwner'] ?? '')) ?>">
        </label>
        <label>Repo Name
          <input type="text" name="repo_name" value="<?= station_h((string) ($settings['github']['repoName'] ?? $project)) ?>">
        </label>
        <label>Repository Visibility
          <select name="repo_visibility">
            <option value="private" <?= ((string) ($settings['github']['visibility'] ?? 'private')) === 'private' ? 'selected' : '' ?>>private</option>
            <option value="public" <?= ((string) ($settings['github']['visibility'] ?? 'private')) === 'public' ? 'selected' : '' ?>>public</option>
          </select>
        </label>
        <label>Default Branch
          <input type="text" name="default_branch" value="<?= station_h((string) ($settings['github']['defaultBranch'] ?? 'main')) ?>">
        </label>
        <label><input type="checkbox" name="release_workflow" <?= !empty($settings['github']['releaseWorkflow']) ? 'checked' : '' ?>> Generate release workflow</label>
        <button type="submit">Generate GitHub Files</button>
      </form>
    </section>

    <section class="grid-two">
      <form method="post" class="card form-grid">
        <input type="hidden" name="action" value="save_settings">
        <input type="hidden" name="project" value="<?= station_h($project) ?>">
        <h2>Scraper Builder Wizard</h2>
        <label>Target Domain
          <input type="text" name="target_domain" value="<?= station_h((string) ($settings['scraperBuilder']['targetDomain'] ?? '')) ?>" placeholder="example.com">
        </label>
        <label>End Goal / Use Case
          <textarea name="use_case" rows="8" placeholder="Explain the business goal, extracted fields, crawl behavior, and final output."><?= station_h((string) ($settings['scraperBuilder']['useCase'] ?? '')) ?></textarea>
        </label>
        <button type="submit">Save Scraper Wizard</button>
      </form>

      <form method="post" class="card form-grid">
        <input type="hidden" name="action" value="add_preset">
        <input type="hidden" name="project" value="<?= station_h($project) ?>">
        <h2>Saved Prompt Presets</h2>
        <label>Preset Name
          <input type="text" name="preset_name" placeholder="Lead scrape preset">
        </label>
        <label>Preset Prompt
          <textarea name="preset_prompt" rows="10" placeholder="Describe the target domain, extraction fields, anti-duplication rules, and export format."></textarea>
        </label>
        <button type="submit">Save Prompt Preset</button>
      </form>
    </section>

    <section class="card">
      <h2>Preset Library</h2>
      <?php if (!$presets): ?>
        <p>No saved presets yet.</p>
      <?php else: ?>
        <div class="grid-two">
          <?php foreach ($presets as $preset): ?>
            <article class="card feature-card">
              <h2><?= station_h((string) ($preset['name'] ?? 'Preset')) ?></h2>
              <p><?= station_h((string) ($preset['createdAt'] ?? '')) ?></p>
              <pre class="code-mini"><?= station_h((string) ($preset['prompt'] ?? '')) ?></pre>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
