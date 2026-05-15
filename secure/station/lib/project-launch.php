<?php

declare(strict_types=1);

/**
 * Detect how a project can be launched (web app, extension, CLI-only, etc.).
 *
 * @return array{
 *   launchable: bool,
 *   launchKind: string,
 *   tone: string,
 *   title: string,
 *   summary: string,
 *   stack: string,
 *   hints: list<string>
 * }
 */
function station_project_launch_profile(string $projectSlug): array
{
    $slug = station_safe_name($projectSlug);
    $path = station_project_path($slug);
    $profile = [
        'launchable' => false,
        'launchKind' => 'unknown',
        'tone' => 'warn',
        'title' => $slug,
        'summary' => 'Open Manage to configure how this project runs.',
        'stack' => station_infer_docker_stack($slug),
        'hints' => [],
    ];

    if (!is_dir($path)) {
        return $profile;
    }

    if (is_file($path . '/manifest.json')) {
        $manifest = @json_decode((string) @file_get_contents($path . '/manifest.json'), true);
        if (is_array($manifest) && isset($manifest['manifest_version'])) {
            $profile['launchable'] = true;
            $profile['launchKind'] = 'chrome-extension';
            $profile['tone'] = 'ok';
            $profile['title'] = (string) ($manifest['name'] ?? $slug);
            $profile['summary'] = 'Chrome extension — download the zip and load unpacked in chrome://extensions.';

            return $profile;
        }
    }

    $hasIndex = is_file($path . '/index.html') || is_file($path . '/index.php') || is_file($path . '/public/index.php');
    $hasNode = is_file($path . '/package.json');
    $hasPhp = is_file($path . '/composer.json') || is_file($path . '/index.php');
    $hasPython = is_file($path . '/requirements.txt') || is_file($path . '/pyproject.toml');

    if ($hasIndex || $hasPhp) {
        $profile['launchable'] = true;
        $profile['launchKind'] = 'web';
        $profile['tone'] = 'ok';
        $profile['title'] = $slug;
        $profile['summary'] = 'Static or PHP web app — use Launch when the server route or container is running.';
        $profile['hints'][] = 'Served under /p/' . $slug . '/ when nginx routing is configured.';

        return $profile;
    }

    if ($hasNode) {
        $pkg = @json_decode((string) @file_get_contents($path . '/package.json'), true);
        $scripts = is_array($pkg['scripts'] ?? null) ? $pkg['scripts'] : [];
        if (isset($scripts['start']) || isset($scripts['dev'])) {
            $profile['launchable'] = true;
            $profile['launchKind'] = 'web';
            $profile['tone'] = 'ok';
            $profile['title'] = (string) ($pkg['name'] ?? $slug);
            $profile['summary'] = 'Node app with npm start/dev — enable Docker or run the process, then open Launch.';
            $profile['stack'] = 'node';

            return $profile;
        }
        $profile['launchKind'] = 'node-cli';
        $profile['summary'] = 'Node project without a start script — add "start" in package.json or enable Docker.';
        $profile['hints'][] = 'Use Files to edit package.json, or Manage → Docker.';

        return $profile;
    }

    if ($hasPython) {
        $profile['launchable'] = true;
        $profile['launchKind'] = 'web';
        $profile['tone'] = 'ok';
        $profile['summary'] = 'Python app — typically port 8000 inside Docker.';
        $profile['stack'] = 'python';

        return $profile;
    }

    $files = @scandir($path) ?: [];
    $fileCount = count(array_filter($files, static fn ($f) => $f !== '.' && $f !== '..'));
    if ($fileCount > 0) {
        $profile['launchKind'] = 'files-only';
        $profile['summary'] = 'Project files on disk — not detected as a web entrypoint. Browse in Files or add index.html / Docker.';
        $profile['hints'][] = 'Add index.html, enable containers, or link to GitHub under Manage.';
    }

    return $profile;
}

/**
 * Render the graphical App Explorer splash (launch.php).
 */
function station_render_app_explorer_page(
    string $projectSlug,
    array $launchProfile,
    ?array $user,
    array $options = []
): void {
    $slug = station_safe_name($projectSlug);
    $copy = station_openai_project_explorer_copy($slug, $launchProfile);
    $canBuild = station_can_build($user);
    $dockerStopped = !empty($options['dockerStopped']);
    $dockerState = (string) ($options['dockerStateLabel'] ?? '');
    $friendlyUrl = (string) ($options['friendlyUrl'] ?? '');
    $stackLabel = ucfirst((string) ($launchProfile['stack'] ?? 'app'));
    $kind = (string) ($launchProfile['launchKind'] ?? 'unknown');
    $icon = match ($kind) {
        'chrome-extension' => '🧩',
        'web' => '🌐',
        'node-cli' => '📦',
        default => '📁',
    };
    ?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('App explorer — ' . station_h($slug), 'How to run and launch ' . $slug) ?>
</head>
<body class="station-body app-explorer-body">
  <main class="app-explorer-shell">
    <article class="app-explorer-card">
      <div class="app-explorer-hero">
        <span class="app-explorer-icon" aria-hidden="true"><?= $icon ?></span>
        <div class="app-explorer-hero-copy">
          <p class="app-explorer-kicker">App explorer</p>
          <h1><?= station_h((string) ($launchProfile['title'] ?? $slug)) ?></h1>
          <p class="app-explorer-slug"><code><?= station_h($slug) ?></code> · <?= station_h($stackLabel) ?></p>
          <span class="app-explorer-launch-pill app-explorer-launch-pill-<?= station_h((string) ($launchProfile['tone'] ?? 'warn')) ?>">
            <?= !empty($launchProfile['launchable']) ? 'Launchable web app' : 'Needs setup' ?>
          </span>
        </div>
      </div>

      <?php if ($dockerStopped): ?>
      <div class="app-explorer-docker-banner">
        <strong>Containers:</strong> <?= station_h($dockerState !== '' ? $dockerState : 'Not running') ?>
        <?php if ($canBuild): ?>
        <form method="post" action="docker-actions.php" class="app-explorer-inline-form">
          <input type="hidden" name="project" value="<?= station_h($slug) ?>">
          <input type="hidden" name="action" value="start">
          <input type="hidden" name="return" value="launch.php?project=<?= urlencode($slug) ?>">
          <button type="submit" class="btn-primary">Start containers</button>
        </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="app-explorer-body-copy" id="appExplorerCopy">
        <p class="app-explorer-description"><?= station_h((string) $copy['description']) ?></p>
        <?php if (!empty($copy['steps'])): ?>
        <ol class="app-explorer-steps">
          <?php foreach ($copy['steps'] as $step): ?>
            <li><?= station_h((string) $step) ?></li>
          <?php endforeach; ?>
        </ol>
        <?php endif; ?>
        <?php if (($copy['source'] ?? '') === 'openai'): ?>
          <p class="app-explorer-meta">Summary generated with OpenAI<?= station_openai_configured() ? '' : ' (fallback)' ?>.</p>
        <?php endif; ?>
      </div>

      <div class="app-explorer-actions">
        <?php if (!empty($launchProfile['launchable']) && $launchProfile['launchKind'] === 'web' && !$dockerStopped): ?>
          <a class="btn-primary" href="<?= station_h(station_project_serve_path($slug)) ?>">Open app ↗</a>
        <?php endif; ?>
        <?php if ($friendlyUrl !== ''): ?>
          <a class="btn-action" href="<?= station_h($friendlyUrl) ?>" target="_blank" rel="noreferrer">Public URL ↗</a>
        <?php endif; ?>
        <a class="btn-action" href="viewer.php?project=<?= urlencode($slug) ?>">Browse files</a>
        <?php if ($canBuild): ?>
          <a class="btn-action" href="project-settings.php?project=<?= urlencode($slug) ?>">Manage</a>
          <?php if (station_docker_enabled()): ?>
            <a class="btn-action" href="project-settings.php?project=<?= urlencode($slug) ?>#docker">Docker</a>
          <?php endif; ?>
        <?php endif; ?>
        <a class="btn-action btn-action-ghost" href="station.php">Dashboard</a>
      </div>
    </article>
  </main>
  <script>
  (function () {
    if (<?= station_openai_configured() ? 'false' : 'true' ?>) { return; }
    fetch('app-explorer-api.php?project=<?= rawurlencode($slug) ?>', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok || !data.description) { return; }
        var box = document.getElementById('appExplorerCopy');
        if (!box) { return; }
        var p = box.querySelector('.app-explorer-description');
        if (p) { p.textContent = data.description; }
        if (data.steps && data.steps.length) {
          var ol = box.querySelector('.app-explorer-steps');
          if (!ol) {
            ol = document.createElement('ol');
            ol.className = 'app-explorer-steps';
            box.appendChild(ol);
          }
          ol.innerHTML = '';
          data.steps.forEach(function (s) {
            var li = document.createElement('li');
            li.textContent = s;
            ol.appendChild(li);
          });
        }
      })
      .catch(function () {});
  })();
  </script>
  <?= station_pwa_register_html() ?>
</body>
</html>
    <?php
}
