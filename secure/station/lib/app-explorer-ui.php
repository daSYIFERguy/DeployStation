<?php

declare(strict_types=1);

/**
 * App Explorer page (launch.php) — dashboard layout + deploy chat.
 */
function station_render_app_explorer_page(
    string $projectSlug,
    array $launchProfile,
    ?array $user,
    array $options = []
): void {
    $slug = station_safe_name($projectSlug);
    $copy = station_openai_project_explorer_copy($slug, $launchProfile);
    $context = station_project_explorer_context($slug, $launchProfile);
    $canBuild = station_can_build($user);
    $dockerStopped = !empty($options['dockerStopped']);
    $dockerStateKey = (string) ($options['dockerStateKey'] ?? '');
    if ($dockerStateKey === '' && is_array($context['dockerRuntime'] ?? null)) {
        $dockerStateKey = (string) ($context['dockerRuntime']['state'] ?? '');
    }
    $dockerStateLabel = (string) ($options['dockerStateLabel'] ?? '');
    if ($dockerStateLabel === '' && $dockerStateKey !== '') {
        $dockerStateLabel = ucfirst(str_replace('_', ' ', $dockerStateKey));
    }
    $friendlyUrl = (string) ($options['friendlyUrl'] ?? '');
    $stackLabel = ucfirst((string) ($launchProfile['stack'] ?? 'app'));
    $kind = (string) ($launchProfile['launchKind'] ?? 'unknown');
    $icon = match ($kind) {
        'chrome-extension' => '🧩',
        'web' => '🌐',
        'node-cli' => '📦',
        default => '📁',
    };
    $launchPill = !empty($launchProfile['launchable']) ? 'Launchable' : 'Needs setup';
    $launchTone = (string) ($launchProfile['tone'] ?? 'warn');
    $containerized = !empty($context['docker']['containerized']);
    $contextJson = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($contextJson)) {
        $contextJson = '{}';
    }
    $gh = is_array($context['github'] ?? null) ? $context['github'] : [];
    $ghPublic = !empty($gh['htmlUrl']) && ($gh['visibility'] ?? '') === 'public';
    $showDockerActions = $containerized && ($dockerStopped || in_array($dockerStateKey, ['stopped', 'partial', 'unknown', 'unavailable'], true));
    ?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('App explorer — ' . station_h($slug), 'Deploy assistant and launch guide for ' . $slug, 'assets/style.css?v=20260518a') ?>
</head>
<body class="station-body">
  <div class="dashboard-shell">
    <?= station_dashboard_nav_html('dashboard') ?>
    <main class="dashboard-main app-explorer-main">
      <header class="dashboard-topbar">
        <div>
          <p class="dashboard-kicker">App explorer</p>
          <h1 class="dashboard-heading"><?= station_h((string) ($launchProfile['title'] ?? $slug)) ?></h1>
          <p class="dashboard-subheading">
            <code><?= station_h($slug) ?></code> · <?= station_h($stackLabel) ?>
            <?php if ($ghPublic): ?>
              · <a href="<?= station_h((string) $gh['htmlUrl']) ?>" target="_blank" rel="noreferrer">Public GitHub ↗</a>
            <?php elseif (!empty($gh['configured'])): ?>
              · GitHub <code><?= station_h((string) $gh['owner'] . '/' . (string) $gh['name']) ?></code>
            <?php endif; ?>
          </p>
        </div>
        <nav class="nav-pills">
          <a href="station.php">Dashboard</a>
          <a href="viewer.php?project=<?= urlencode($slug) ?>">Files</a>
          <?php if ($canBuild): ?>
            <a href="project-settings.php?project=<?= urlencode($slug) ?>">Manage</a>
          <?php endif; ?>
        </nav>
      </header>

      <section class="docker-mc-strip app-explorer-status-strip" aria-label="Project status">
        <div class="docker-mc-head">
          <div>
            <p class="docker-mc-kicker">Status</p>
            <h2 class="docker-mc-title"><?= station_h($icon) ?> <?= station_h($stackLabel) ?></h2>
            <p class="docker-mc-desc"><?= station_h((string) ($launchProfile['summary'] ?? '')) ?></p>
          </div>
          <div class="docker-mc-kpis">
            <div class="mc-kpi mc-kpi-<?= station_h($launchTone === 'ok' ? 'ok' : 'warn') ?>">
              <strong><?= station_h($launchPill) ?></strong>
              <span>Launch</span>
            </div>
            <?php if ($containerized): ?>
            <?php
              $dockerKpiTone = ($dockerStateKey === 'running') ? 'ok' : 'warn';
            ?>
            <div class="mc-kpi mc-kpi-<?= station_h($dockerKpiTone) ?>">
              <strong><?= station_h($dockerStateLabel !== '' ? $dockerStateLabel : 'Docker') ?></strong>
              <span>Containers</span>
            </div>
            <div class="mc-kpi">
              <strong>:<?= (int) ($context['docker']['hostPort'] ?? 0) ?></strong>
              <span>Host port</span>
            </div>
            <?php endif; ?>
            <div class="mc-kpi">
              <strong><?= station_h($kind) ?></strong>
              <span>Type</span>
            </div>
            <?php if (!empty($context['hasLocalGit'])): ?>
            <div class="mc-kpi mc-kpi-ok">
              <strong>Git</strong>
              <span>Local repo</span>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($showDockerActions): ?>
        <div class="app-explorer-status-actions">
          <?php if ($canBuild): ?>
          <form method="post" action="docker-actions.php" class="app-explorer-inline-form">
            <input type="hidden" name="project" value="<?= station_h($slug) ?>">
            <input type="hidden" name="action" value="start">
            <input type="hidden" name="return" value="launch.php?project=<?= urlencode($slug) ?>">
            <button type="submit" class="btn-primary">Start containers</button>
          </form>
          <a class="secondary-btn" href="project-settings.php?project=<?= urlencode($slug) ?>#docker">Docker settings</a>
          <?php endif; ?>
          <?php if ($friendlyUrl !== ''): ?>
            <a class="secondary-btn" href="<?= station_h($friendlyUrl) ?>" target="_blank" rel="noreferrer">Preview URL ↗</a>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </section>

      <div class="app-explorer-layout">
        <section class="settings-panel app-explorer-overview">
          <div class="settings-panel-head">
            <h2 class="settings-panel-heading">Overview</h2>
            <p class="settings-panel-subtitle">
              <?php if (($copy['source'] ?? '') === 'openai'): ?>
                AI summary from your files and settings.
              <?php else: ?>
                Auto-detected from project files (package.json, README, etc.). Add OpenAI under Admin → Integrations for richer text.
              <?php endif; ?>
            </p>
          </div>

          <?php if (($launchProfile['launchKind'] ?? '') === 'files-only' || empty($launchProfile['launchable'])): ?>
          <p class="app-explorer-meta">
            <a href="project-settings.php?project=<?= urlencode($slug) ?>#web-entrypoint">Select entrypoint</a>
            if <code>index.html</code> is in a subfolder (optional, per project).
          </p>
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
          </div>

          <div class="app-explorer-actions">
            <?php if (!empty($launchProfile['launchable']) && $launchProfile['launchKind'] === 'web' && !$dockerStopped): ?>
              <a class="btn-primary" href="<?= station_h(station_project_public_web_path($slug)) ?>">Open app ↗</a>
            <?php endif; ?>
            <?php if ($friendlyUrl !== ''): ?>
              <a class="btn-action" href="<?= station_h($friendlyUrl) ?>" target="_blank" rel="noreferrer">Public URL ↗</a>
            <?php endif; ?>
            <?php if ($canBuild && station_docker_enabled()): ?>
              <a class="btn-action" href="project-settings.php?project=<?= urlencode($slug) ?>#docker">Configure Docker</a>
            <?php endif; ?>
          </div>

          <details class="app-explorer-context-details">
            <summary>Workspace context (JSON for chat)</summary>
            <pre class="app-explorer-context-pre" id="appExplorerContextPre"><?= station_h($contextJson) ?></pre>
          </details>
        </section>

        <section class="settings-panel app-explorer-chat-panel" id="appExplorerChat">
          <div class="settings-panel-head">
            <h2 class="settings-panel-heading">Deploy assistant</h2>
            <p class="settings-panel-subtitle">
              <?php if (station_openai_configured()): ?>
                <?= station_h(station_openai_status_label()) ?> — context includes files, Docker, and GitHub metadata.
              <?php else: ?>
                <a href="admin-settings.php?tab=github">Add OpenAI</a> under Admin → Integrations to enable chat.
              <?php endif; ?>
            </p>
          </div>

          <div class="app-explorer-chat-log" id="appExplorerChatLog" aria-live="polite">
            <div class="app-explorer-chat-msg app-explorer-chat-msg-system">
              Ask how to run, dockerize, or fix this project. I receive the workspace JSON (file list, stack, ports, GitHub) with each message when enabled below.
            </div>
          </div>

          <label class="feature-toggle app-explorer-chat-opt">
            <input type="checkbox" id="appExplorerIncludeContext" checked>
            <span class="feature-toggle-content">
              <span class="feature-toggle-title">Include full workspace JSON</span>
              <span class="feature-toggle-desc">Turn off for short follow-ups that should not resend the whole report.</span>
            </span>
          </label>

          <label class="setting-label" for="appExplorerExtra">Extra context (optional)</label>
          <textarea id="appExplorerExtra" class="project-settings-textarea" rows="4" placeholder="Paste logs, errors, or notes for this message only…"></textarea>

          <div class="app-explorer-chat-compose">
            <textarea id="appExplorerInput" class="project-settings-textarea" rows="3" placeholder="e.g. How do I add a start script and deploy with Docker?"></textarea>
            <button type="button" class="btn-primary" id="appExplorerSend"<?= station_openai_configured() ? '' : ' disabled' ?>>Send</button>
          </div>
          <p class="app-explorer-meta" id="appExplorerChatStatus"></p>
        </section>
      </div>
    </main>
  </div>
  <?= station_dashboard_nav_script_html() ?>
  <script src="assets/station-assist.js?v=20260518a"></script>
  <script>
  (function () {
    var slug = <?= json_encode($slug, JSON_THROW_ON_ERROR) ?>;

    var api = (window.stationApiUrl || function (p) { return p; })('app-explorer-api.php?project=' + encodeURIComponent(slug));
    (window.stationFetchJson || function (url) { return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }); })(api)
      .then(function (data) {
        if (!data || !data.ok) { return; }
        var box = document.getElementById('appExplorerCopy');
        if (data.description && box) {
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
        }
        if (data.context) {
          var pre = document.getElementById('appExplorerContextPre');
          if (pre) { pre.textContent = JSON.stringify(data.context, null, 2); }
        }
      })
      .catch(function () {});

    var log = document.getElementById('appExplorerChatLog');
    var input = document.getElementById('appExplorerInput');
    var extra = document.getElementById('appExplorerExtra');
    var sendBtn = document.getElementById('appExplorerSend');
    var statusEl = document.getElementById('appExplorerChatStatus');
    var includeCtx = document.getElementById('appExplorerIncludeContext');

    function appendMsg(role, text) {
      if (!log || !text) { return; }
      var div = document.createElement('div');
      div.className = 'app-explorer-chat-msg app-explorer-chat-msg-' + role;
      div.textContent = text;
      log.appendChild(div);
      log.scrollTop = log.scrollHeight;
    }

    function sendChat() {
      if (!input || !sendBtn) { return; }
      var msg = (input.value || '').trim();
      if (!msg) { return; }
      appendMsg('user', msg);
      input.value = '';
      sendBtn.disabled = true;
      if (statusEl) { statusEl.textContent = 'Thinking…'; }

      var postUrl = (window.stationApiUrl || function (p) { return p; })('app-explorer-api.php?project=' + encodeURIComponent(slug));
      (window.stationFetchJson || function (url, opts) { return fetch(url, opts).then(function (r) { return r.json(); }); })(postUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          project: slug,
          message: msg,
          extraContext: extra ? extra.value : '',
          includeContext: includeCtx ? includeCtx.checked : true
        })
      })
        .then(function (data) {
          sendBtn.disabled = false;
          if (statusEl) { statusEl.textContent = ''; }
          if (!data || !data.ok) {
            appendMsg('error', (data && data.message) ? data.message : 'Chat failed.');
            return;
          }
          appendMsg('assistant', data.reply || '(empty reply)');
        })
        .catch(function (err) {
          sendBtn.disabled = false;
          if (statusEl) { statusEl.textContent = ''; }
          appendMsg('error', err && err.message ? err.message : 'Network error.');
        });
    }

    if (sendBtn) { sendBtn.addEventListener('click', sendChat); }
    if (input) {
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          sendChat();
        }
      });
    }
  })();
  </script>
  <?= station_pwa_register_html() ?>
</body>
</html>
    <?php
}
