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
        $wroteEnv = true;
        if (array_key_exists('environment_text', $_POST)) {
            $settings['environment'] = station_parse_env_text((string) ($_POST['environment_text'] ?? ''));
            $wroteEnv = station_write_env_file($project, $settings['environment']);
        }
        if (array_key_exists('notes', $_POST)) {
            $settings['notes'] = trim((string) ($_POST['notes'] ?? ''));
        }
        if (array_key_exists('repo_owner', $_POST) || array_key_exists('repo_name', $_POST)) {
            $settings['github'] = [
                'repoOwner' => trim((string) ($_POST['repo_owner'] ?? '')),
                'repoName' => trim((string) ($_POST['repo_name'] ?? '')),
                'visibility' => ((string) ($_POST['repo_visibility'] ?? 'private')) === 'public' ? 'public' : 'private',
                'defaultBranch' => trim((string) ($_POST['default_branch'] ?? 'main')) ?: 'main',
                'releaseWorkflow' => isset($_POST['release_workflow'])
            ];
        }
        if (array_key_exists('target_domain', $_POST) || array_key_exists('use_case', $_POST)) {
            $settings['scraperBuilder']['targetDomain'] = trim((string) ($_POST['target_domain'] ?? ''));
            $settings['scraperBuilder']['useCase'] = trim((string) ($_POST['use_case'] ?? ''));
        }

        if (station_save_project_settings($project, $settings) && $wroteEnv) {
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

    if ($action === 'save_docker' || $action === 'generate_docker') {
        $docker = station_project_docker_settings($project, $settings);
        $runtime = (string) ($_POST['docker_runtime'] ?? $docker['runtime']);
        $docker['enabled'] = isset($_POST['docker_enabled']);
        $docker['autoStart'] = isset($_POST['docker_autostart']);
        $docker['runtime'] = in_array($runtime, ['node', 'php', 'static'], true) ? $runtime : (string) $docker['runtime'];
        $docker['imageName'] = station_docker_safe_identifier((string) ($_POST['docker_image'] ?? $docker['imageName']), (string) $docker['imageName']);
        $docker['containerName'] = station_docker_safe_identifier((string) ($_POST['docker_container'] ?? $docker['containerName']), (string) $docker['containerName']);
        $docker['hostPort'] = station_normalize_docker_port($_POST['docker_host_port'] ?? $docker['hostPort'], (int) $docker['hostPort']);
        $docker['containerPort'] = station_normalize_docker_port($_POST['docker_container_port'] ?? $docker['containerPort'], (int) $docker['containerPort']);
        $dockerfileInput = (string) ($_POST['dockerfile'] ?? 'Dockerfile');
        $docker['dockerfile'] = station_is_safe_relative_path($dockerfileInput) ? $dockerfileInput : 'Dockerfile';
        $launchPath = trim((string) ($_POST['docker_launch_path'] ?? '/'));
        $docker['launchPath'] = $launchPath !== '' ? '/' . ltrim(str_replace('\\', '/', $launchPath), '/') : '/';
        $settings['docker'] = station_project_docker_settings($project, ['docker' => $docker]);

        if (!station_save_project_settings($project, $settings)) {
            $error = 'Could not save Docker settings.';
        } elseif ($action === 'generate_docker') {
            $generated = station_generate_project_docker_assets($project, $settings, isset($_POST['docker_overwrite']));
            if (!empty($generated['ok'])) {
                station_log_event('project.docker.generated', ['project' => $project]);
                station_flash_set('ok', (string) ($generated['message'] ?? 'Docker files generated.'));
                header('Location: project-settings.php?project=' . urlencode($project));
                exit;
            }
            $error = (string) ($generated['message'] ?? 'Could not generate Docker files.');
        } else {
            station_log_event('project.docker.saved', ['project' => $project, 'enabled' => $settings['docker']['enabled']]);
            station_flash_set('ok', 'Docker launch settings saved.');
            header('Location: project-settings.php?project=' . urlencode($project));
            exit;
        }
    }
}

$presets = isset($settings['scraperBuilder']['presets']) && is_array($settings['scraperBuilder']['presets']) ? $settings['scraperBuilder']['presets'] : [];
$dockerSettings = station_project_docker_settings($project, $settings);
$dockerStatus = station_project_docker_status($project, $settings);
$dockerUrl = station_project_docker_url($dockerSettings);
?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('Project Settings', 'Manage environment variables, GitHub setup, scraper presets, and notes for this project.') ?>
</head>
<body class="station-body">
  <main class="station-shell">
    <header class="topbar card settings-hero">
      <div>
        <p class="kicker">Project Control</p>
        <h1><?= station_h($project) ?></h1>
        <p>Deployment, Docker launch, environment, GitHub bootstrap, scraper prompts, and notes.</p>
      </div>
      <nav class="nav-pills">
        <a href="station.php">Dashboard</a>
        <a href="viewer.php?project=<?= urlencode($project) ?>">Viewer</a>
        <a href="launch.php?project=<?= urlencode($project) ?>" target="_blank" rel="noreferrer">Launch</a>
      </nav>
    </header>

    <?php if ($ok !== ''): ?><div class="alert ok"><?= station_h($ok) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert error"><?= station_h($error) ?></div><?php endif; ?>

    <section class="grid-two settings-grid">
      <form method="post" class="card form-grid setting-card">
        <input type="hidden" name="action" value="save_settings">
        <input type="hidden" name="project" value="<?= station_h($project) ?>">
        <h2>Environment + Notes</h2>
        <p class="section-note">Values are written to <code>.env.local</code> and are also passed into Docker launches.</p>
        <label>Environment Variables
          <textarea name="environment_text" rows="14" placeholder="API_URL=https://example.com&#10;API_KEY=secret"><?= station_h(station_env_text($settings['environment'] ?? [])) ?></textarea>
        </label>
        <label>Notes
          <textarea name="notes" rows="8"><?= station_h((string) ($settings['notes'] ?? '')) ?></textarea>
        </label>
        <button type="submit">Save Project Settings</button>
      </form>

      <form method="post" class="card form-grid setting-card">
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

    <section class="card form-grid setting-card docker-launch-card">
      <div class="settings-card-head">
        <div>
          <p class="kicker">Docker Launch</p>
          <h2>Containerized Web App</h2>
          <p class="section-note">Build and run this project as a container when the server has Docker available.</p>
        </div>
        <span class="status-pill <?= !empty($dockerStatus['running']) ? 'is-public' : 'is-private' ?>"><?= station_h((string) ($dockerStatus['label'] ?? 'Not started')) ?></span>
      </div>
      <form method="post" class="form-grid">
        <input type="hidden" name="action" value="save_docker">
        <input type="hidden" name="project" value="<?= station_h($project) ?>">
        <div class="grid-two">
          <label><input type="checkbox" name="docker_enabled" <?= !empty($dockerSettings['enabled']) ? 'checked' : '' ?>> Launch with Docker</label>
          <label><input type="checkbox" name="docker_autostart" <?= !empty($dockerSettings['autoStart']) ? 'checked' : '' ?>> Auto-start when Launch is opened</label>
        </div>
        <div class="grid-two">
          <label>Runtime
            <select name="docker_runtime">
              <option value="node" <?= $dockerSettings['runtime'] === 'node' ? 'selected' : '' ?>>Node / npm app</option>
              <option value="php" <?= $dockerSettings['runtime'] === 'php' ? 'selected' : '' ?>>PHP / Apache app</option>
              <option value="static" <?= $dockerSettings['runtime'] === 'static' ? 'selected' : '' ?>>Static web app</option>
            </select>
          </label>
          <label>Dockerfile
            <input type="text" name="dockerfile" value="<?= station_h((string) $dockerSettings['dockerfile']) ?>" placeholder="Dockerfile">
          </label>
        </div>
        <div class="grid-two">
          <label>Host Port
            <input type="number" name="docker_host_port" min="1" max="65535" value="<?= station_h((string) $dockerSettings['hostPort']) ?>">
          </label>
          <label>Container Port
            <input type="number" name="docker_container_port" min="1" max="65535" value="<?= station_h((string) $dockerSettings['containerPort']) ?>">
          </label>
        </div>
        <div class="grid-two">
          <label>Image Name
            <input type="text" name="docker_image" value="<?= station_h((string) $dockerSettings['imageName']) ?>">
          </label>
          <label>Container Name
            <input type="text" name="docker_container" value="<?= station_h((string) $dockerSettings['containerName']) ?>">
          </label>
        </div>
        <label>Launch Path
          <input type="text" name="docker_launch_path" value="<?= station_h((string) $dockerSettings['launchPath']) ?>" placeholder="/">
        </label>
        <label><input type="checkbox" name="docker_overwrite"> Overwrite generated Dockerfile and .dockerignore</label>
        <div class="settings-actions">
          <button type="submit">Save Docker Settings</button>
          <button type="submit" name="action" value="generate_docker" class="secondary-btn">Generate Docker Files</button>
          <a class="download-btn secondary-link" href="launch.php?project=<?= urlencode($project) ?>" target="_blank" rel="noreferrer">Open Launch Center</a>
        </div>
        <p class="section-note">Container URL: <code><?= station_h($dockerUrl) ?></code></p>
        <?php if (!empty($dockerStatus['detail'])): ?>
          <pre class="code-mini"><?= station_h((string) $dockerStatus['detail']) ?></pre>
        <?php endif; ?>
      </form>
    </section>

    <section class="grid-two settings-grid">
      <form method="post" class="card form-grid setting-card">
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

      <form method="post" class="card form-grid setting-card">
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

    <section class="card setting-card">
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
  <?= station_pwa_register_html() ?>
</body>
</html>
