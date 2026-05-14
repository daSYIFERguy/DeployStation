<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/host-health.php';

station_require_owner();

$error = '';
$runResult = null;

$commandKeys = [
    'hostNginxTestCommand',
    'hostRestartNginxCommand',
    'hostRestartPhpFpmCommand',
    'hostRestartDockerCommand',
    'hostDiagExtraCommand',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['host_health_action'] ?? '');

    if ($action === 'save_commands') {
        $settings = station_admin_settings();
        foreach ($commandKeys as $key) {
            $settings[$key] = trim((string) ($_POST[$key] ?? ''));
        }
        if (station_save_admin_settings($settings)) {
            station_flash_set('ok', 'Host helper commands saved.');
            header('Location: admin-host-health.php');
            exit;
        }
        $error = 'Could not save admin settings (check filesystem permissions on the data directory).';
    } elseif ($action === 'run_command') {
        $which = (string) ($_POST['which'] ?? '');
        $allowedConfigured = [
            'restart_nginx' => 'hostRestartNginxCommand',
            'restart_php_fpm' => 'hostRestartPhpFpmCommand',
            'restart_docker' => 'hostRestartDockerCommand',
            'run_extra' => 'hostDiagExtraCommand',
        ];
        if ($which === 'nginx_test') {
            $runResult = station_host_health_run_nginx_syntax_test();
            station_log_event('host_health_nginx_test', [
                'ok' => !empty($runResult['ok']),
                'code' => (int) ($runResult['code'] ?? -1),
                'outputSnippet' => substr((string) ($runResult['output'] ?? ''), 0, 800),
            ]);
        } elseif ($which === 'nginx_projects_include_test') {
            $runResult = station_host_health_run_nginx_projects_include_syntax_test();
            station_log_event('host_health_nginx_projects_include_test', [
                'ok' => !empty($runResult['ok']),
                'code' => (int) ($runResult['code'] ?? -1),
                'outputSnippet' => substr((string) ($runResult['output'] ?? ''), 0, 800),
            ]);
        } elseif (isset($allowedConfigured[$which])) {
            $settingsKey = $allowedConfigured[$which];
            $runResult = station_host_health_run_configured_command($settingsKey);
            station_log_event('host_health_shell', [
                'which' => $which,
                'settingsKey' => $settingsKey,
                'ok' => !empty($runResult['ok']),
                'code' => (int) ($runResult['code'] ?? -1),
                'outputSnippet' => substr((string) ($runResult['output'] ?? ''), 0, 800),
            ]);
        } else {
            $error = 'Unknown action.';
        }
    }
}

$settings = station_admin_settings();
$snapshot = station_host_health_snapshot();
$routing = station_host_health_routing_map();
$upstreamMatrix = station_host_health_docker_upstream_matrix();
$missionSlugs = [];
foreach ($routing['dockerProjects'] ?? [] as $dp) {
    $s = trim((string) ($dp['slug'] ?? ''));
    if ($s !== '') {
        $missionSlugs[] = $s;
    }
}
$missionPayload = station_mission_fleet_payload();

$nginxTestLabel = trim((string) ($settings['hostNginxTestCommand'] ?? '')) !== ''
    ? 'nginx config test (custom hostNginxTestCommand)'
    : 'nginx -t (full system nginx.conf as the PHP user)';

$labels = [
    'uptime' => 'Uptime',
    'memory' => 'Memory (free -h)',
    'disk' => 'Disk (df -h)',
    'top_cpu' => 'Top processes by CPU',
    'top_mem' => 'Top processes by memory',
    'listeners' => 'Listening TCP ports (ss / netstat)',
    'systemd_nginx' => 'systemd: nginx',
    'systemd_docker' => 'systemd: docker',
    'php_fpm_units' => 'PHP-FPM units (if present)',
    'nginx_syntax' => $nginxTestLabel,
    'nginx_projects_include' => 'nginx: projects.conf only (isolated -c; ignores /etc/nginx/nginx.conf)',
    'docker_ps' => 'docker ps',
    'docker_stats' => 'docker stats (one-shot)',
];

$infra = (string) ($routing['infrastructure'] ?? 'apache');
$includePath = (string) ($routing['nginxInclude'] ?? '');
$hostMode = (string) ($routing['nginxUpstreamHostMode'] ?? 'preserve');
$stripDockerCookies = !empty($routing['nginxDockerProxyStripCookies']);
$nginxReloadAfterWrites = !empty($routing['nginxReloadAfterRouteWrites']);
$webBase = (string) ($routing['webBasePath'] ?? '');
$secureBase = station_secure_base_path();

$trunc = static function (string $text, int $max = 20000): string {
    if (strlen($text) <= $max) {
        return $text;
    }
    return substr($text, 0, $max) . "\n\n… output truncated for the browser …\n";
};

$diagram = <<<'TXT'
┌─────────────────────────────────────────────────────────────────────────┐
│  Browser                                                                 │
└───────────────────────────────┬─────────────────────────────────────────┘
                                │
                    Host reverse proxy (your choice in Admin)
                                │
         ┌──────────────────────┴──────────────────────┐
         │                                             │
    Station PHP app                          Per-project Docker
    (this Deployment Station)                  (compose on host)
         │                                             │
    Typical paths:                               Public URL prefix:
    …/station/…  (dashboard)                    /p/<slug>/…
    …/secure/station/…  (admin, uploads)               │
         │                                    nginx location →
         │                                    proxy_pass http://127.0.0.1:<hostPort>
         │                                             │
         │                                    Container listens on <appPort>
         └─────────────────────────────────────────────┘
TXT;

?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('Host health', 'Live host diagnostics, routing map, and optional restart commands.') ?>
</head>
<body class="station-body">
  <div class="dashboard-shell">
    <?= station_dashboard_nav_html('host_health') ?>
    <main class="dashboard-main">
      <header class="dashboard-topbar">
        <div>
          <p class="dashboard-kicker">Owner · Mission control</p>
          <h1 class="dashboard-heading">Host health &amp; routing</h1>
          <p class="dashboard-subheading">At-a-glance rings for containers, compose health, memory, and disk; live fleet bars; routing; and raw diagnostics from the same shell user as Station (often <code>www-data</code>).</p>
        </div>
        <nav class="nav-pills">
          <a href="admin-settings.php">← Admin settings</a>
        </nav>
      </header>

      <?= station_flash_banners_html() ?>

      <?php if (is_array($runResult)): ?>
        <div class="alert <?= !empty($runResult['ok']) ? 'ok' : 'error' ?>" style="margin-top:12px;">
          <strong><?= !empty($runResult['ok']) ? 'Command finished' : 'Command failed' ?></strong>
          — exit <?= (int) ($runResult['code'] ?? -1) ?>.
          <?= station_h((string) ($runResult['message'] ?? '')) ?>
        </div>
        <pre class="host-routing-pre" style="margin-top:8px;"><?= station_h($trunc((string) ($runResult['output'] ?? ''))) ?></pre>
      <?php endif; ?>

      <div class="settings-panel mc-cockpit-wrap" style="margin-top: 16px; border: none; padding: 0; background: transparent; box-shadow: none;">
        <?= station_host_health_mission_cockpit_html(
            $snapshot,
            $missionPayload,
            $routing,
            $upstreamMatrix,
            $missionSlugs
        ) ?>
      </div>

      <p class="setting-description" style="margin-top:8px;">
        <label class="feature-toggle" style="display:inline-flex;align-items:center;gap:8px;">
          <input type="checkbox" id="hostHealthAutoRefresh">
          <span>Auto-refresh this page every 25 seconds (GET only — clears inline command output above).</span>
        </label>
      </p>

      <div class="settings-panel" style="margin-top: 20px; border: none; padding: 0; background: transparent; box-shadow: none;">
        <?= station_mission_fleet_markup(
            $missionPayload,
            $missionSlugs,
            'Container fleet — relative load',
            'Each bar is scaled to the busiest container on this host so you can compare CPU and RAM at a glance. Station-managed stacks are highlighted when the container name includes the project slug.'
        ) ?>
      </div>

      <div class="settings-panel" style="margin-top: 20px;">
        <h2 class="settings-panel-heading" style="font-size:18px;">Routing map (from Station config)</h2>
        <p class="setting-description">Infrastructure mode: <strong><?= station_h($infra) ?></strong>.
          Nginx docker upstream Host header: <strong><?= station_h($hostMode) ?></strong>
          (<code>preserve</code> = <code>$host</code>; <code>loopback</code> = literal <code>127.0.0.1:port</code>).
          Strip <code>Cookie</code> + <code>Authorization</code> to container: <strong><?= $stripDockerCookies ? 'on' : 'off' ?></strong>.
          <code>/p/…</code> proxy: <strong>open</strong> at nginx (no Station <code>auth_request</code>); restrict with firewall or vhost if needed.
          Nginx reload after route file writes: <strong><?= $nginxReloadAfterWrites ? 'yes' : 'no' ?></strong> (yes when production web server is Nginx).
        </p>
        <p class="setting-description">Web base path: <code><?= station_h($webBase !== '' ? $webBase : '(empty — site root)') ?></code>.
          Secure path prefix: <code><?= station_h($secureBase !== '' ? $secureBase : '(empty)') ?></code>.
        </p>
        <p class="setting-description">Generated include path (nginx): <code><?= station_h($includePath) ?></code></p>
        <pre class="host-routing-pre" aria-label="Routing diagram"><?= station_h($diagram) ?></pre>

        <?php if ($infra === 'nginx'): ?>
          <p class="setting-description" style="margin-top:12px;">Snippet to merge into your server block is under <a href="admin-settings.php?tab=project-defaults">Admin → Projects</a> (nginx project route snippet). Ensure <code>include <?= station_h($includePath) ?>;</code> appears <em>before</em> a catch-all <code>location /</code>.</p>
        <?php else: ?>
          <p class="setting-description" style="margin-top:12px;">Apache mode: Docker URL rewrites still use <code>/p/&lt;slug&gt;/</code> through your vhost; confirm mod_proxy and the Station-managed include or equivalent.</p>
        <?php endif; ?>

        <table class="host-health-table">
          <thead>
            <tr>
              <th>Slug</th>
              <th>Public path</th>
              <th>Host port → container</th>
              <th>Compose</th>
              <th>Loopback (bypass nginx)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($routing['dockerProjects'] as $row): ?>
              <tr>
                <td><code><?= station_h((string) ($row['slug'] ?? '')) ?></code></td>
                <td><code><?= station_h((string) ($row['publicPath'] ?? '')) ?></code></td>
                <td><code><?= (int) ($row['hostPort'] ?? 0) ?></code> → app <code><?= (int) ($row['appPort'] ?? 0) ?></code></td>
                <td><?= station_h((string) ($row['composeState'] ?? '')) ?></td>
                <td><?php $lb = (string) ($row['loopbackRoot'] ?? ''); ?>
                  <?php if ($lb !== ''): ?><code><?= station_h($lb) ?></code><?php else: ?><span class="host-health-meta">no port</span><?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if ($routing['dockerProjects'] === []): ?>
              <tr><td colspan="5" class="host-health-meta">No dockerized projects registered.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($upstreamMatrix !== []): ?>
      <details class="settings-panel mc-advanced-upstream" style="margin-top: 20px;">
        <summary class="settings-panel-heading" style="font-size:18px;cursor:pointer;">Advanced: full upstream matrix (verbose)</summary>
        <p class="setting-description" style="margin-top:8px;">Same probes as the mission-control strip; open when you need every curl line on one page.</p>
        <?php foreach ($upstreamMatrix as $um): ?>
          <?php
            $slug = (string) ($um['slug'] ?? '');
            $pr = $um['probe'] ?? [];
            $tcpOk = !empty($pr['tcp']);
            $httpC = (int) ($pr['httpCode'] ?? 0);
            $sum = $tcpOk ? ('TCP ok' . ($httpC > 0 ? ' · HTTP ' . $httpC : '')) : ('TCP failed: ' . (string) ($pr['tcpError'] ?? ''));
            ?>
          <div class="mc-advanced-upstream-block">
            <h3 class="mc-advanced-upstream-title"><code><?= station_h($slug) ?></code> — <?= station_h($sum) ?></h3>
            <?php if ((string) ($pr['httpError'] ?? '') !== ''): ?>
              <p class="setting-description"><strong>HTTP layer:</strong> <?= station_h((string) $pr['httpError']) ?></p>
            <?php endif; ?>
            <?php if ((string) ($pr['bodySnippet'] ?? '') !== ''): ?>
              <pre class="host-routing-pre"><?= station_h((string) $pr['bodySnippet']) ?></pre>
            <?php endif; ?>
            <pre class="host-routing-pre"><?= station_h((string) ($um['shellCurlLoopVerbose'] ?? '')) ?></pre>
            <pre class="host-routing-pre"><?= station_h((string) ($um['shellSs'] ?? '')) ?></pre>
          </div>
        <?php endforeach; ?>
      </details>
      <?php endif; ?>

      <div class="settings-panel" style="margin-top: 24px;">
        <h2 class="settings-panel-heading" style="font-size:18px;">Quick actions</h2>
        <p class="setting-description">Uses the shell one-liners saved at the bottom of this page (defaults match typical Debian/Ubuntu installs).</p>
        <div class="host-health-actions">
          <form method="post">
            <input type="hidden" name="host_health_action" value="run_command">
            <input type="hidden" name="which" value="nginx_test">
            <button type="submit" class="secondary-btn">Run nginx config test</button>
          </form>
          <form method="post">
            <input type="hidden" name="host_health_action" value="run_command">
            <input type="hidden" name="which" value="nginx_projects_include_test">
            <button type="submit" class="secondary-btn">Re-run projects.conf syntax test</button>
          </form>
          <form method="post" onsubmit="return confirm('Run the configured extra diagnostic command?');">
            <input type="hidden" name="host_health_action" value="run_command">
            <input type="hidden" name="which" value="run_extra">
            <button type="submit" class="secondary-btn">Run extra diagnostic</button>
          </form>
          <form method="post" onsubmit="return confirm('Restart nginx using the saved command?');">
            <input type="hidden" name="host_health_action" value="run_command">
            <input type="hidden" name="which" value="restart_nginx">
            <button type="submit" class="secondary-btn">Restart nginx</button>
          </form>
          <form method="post" onsubmit="return confirm('Restart PHP-FPM using the saved command?');">
            <input type="hidden" name="host_health_action" value="run_command">
            <input type="hidden" name="which" value="restart_php_fpm">
            <button type="submit" class="secondary-btn">Restart PHP-FPM</button>
          </form>
          <form method="post" onsubmit="return confirm('This affects the whole Docker engine on the host. Continue?');">
            <input type="hidden" name="host_health_action" value="run_command">
            <input type="hidden" name="which" value="restart_docker">
            <button type="submit" style="background:#b45309;color:#fff;border:none;padding:8px 14px;border-radius:8px;cursor:pointer;">Restart Docker daemon</button>
          </form>
        </div>
      </div>

      <div class="host-health-grid">
        <?php foreach ($labels as $key => $label):
            $block = $snapshot[$key] ?? null;
            if (!is_array($block)) {
                continue;
            }
            $out = $trunc((string) ($block['output'] ?? ''));
            $meta = 'exit ' . (int) ($block['code'] ?? -1);
            if (!empty($block['message'])) {
                $meta .= ' — ' . (string) $block['message'];
            }
            ?>
          <div class="host-health-card">
            <h3><?= station_h($label) ?></h3>
            <div class="host-health-meta"><?= station_h($meta) ?> · <?= !empty($block['ok']) ? 'ok' : 'error' ?></div>
            <pre><?= station_h($out !== '' ? $out : '(no output)') ?></pre>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="settings-panel host-health-commands" style="margin-top: 28px;">
        <h2 class="settings-panel-heading" style="font-size:18px;">Helper shell one-liners (saved in admin settings)</h2>
        <p class="setting-description">Leave blank for a plain <code>nginx -t 2>&1</code> (validates the real system main config). If the PHP user cannot read the configured pid file, use e.g. <code>sudo -n nginx -t 2>&1</code> (requires <code>sudoers</code>). To validate only Station’s generated routes without touching <code>/etc/nginx/nginx.conf</code>, use the <strong>projects.conf only</strong> snapshot card or “Re-run projects.conf syntax test” above — not this field.</p>
        <form method="post">
          <input type="hidden" name="host_health_action" value="save_commands">
          <label for="hostNginxTestCommand">Nginx config test command (optional)</label>
          <textarea id="hostNginxTestCommand" name="hostNginxTestCommand" placeholder="Clear field to use default (sudo -n nginx -t)"><?= station_h(station_admin_resolved_shell_command($settings, 'hostNginxTestCommand')) ?></textarea>
          <label for="hostRestartNginxCommand">Restart nginx</label>
          <textarea id="hostRestartNginxCommand" name="hostRestartNginxCommand"><?= station_h(station_admin_resolved_shell_command($settings, 'hostRestartNginxCommand')) ?></textarea>
          <label for="hostRestartPhpFpmCommand">Restart PHP-FPM</label>
          <textarea id="hostRestartPhpFpmCommand" name="hostRestartPhpFpmCommand"><?= station_h(station_admin_resolved_shell_command($settings, 'hostRestartPhpFpmCommand')) ?></textarea>
          <label for="hostRestartDockerCommand">Restart Docker</label>
          <textarea id="hostRestartDockerCommand" name="hostRestartDockerCommand"><?= station_h(station_admin_resolved_shell_command($settings, 'hostRestartDockerCommand')) ?></textarea>
          <label for="hostDiagExtraCommand">Extra diagnostic (tail, journalctl, etc.)</label>
          <textarea id="hostDiagExtraCommand" name="hostDiagExtraCommand"><?= station_h(station_admin_resolved_shell_command($settings, 'hostDiagExtraCommand')) ?></textarea>
          <div style="margin-top:16px;">
            <button type="submit" class="secondary-btn">Save helper commands</button>
          </div>
        </form>
      </div>
    </main>
  </div>
  <?= station_pwa_register_html() ?>
  <script>
(function () {
  var cb = document.getElementById('hostHealthAutoRefresh');
  if (!cb) { return; }
  var k = 'station_host_health_autorefresh';
  try {
    cb.checked = sessionStorage.getItem(k) === '1';
  } catch (e) {}
  var timer = null;
  function arm() {
    if (timer) { clearInterval(timer); timer = null; }
    if (!cb.checked) { return; }
    timer = setInterval(function () { window.location.reload(); }, 25000);
  }
  cb.addEventListener('change', function () {
    try { sessionStorage.setItem(k, cb.checked ? '1' : '0'); } catch (e) {}
    arm();
  });
  arm();
})();
  </script>
</body>
</html>
