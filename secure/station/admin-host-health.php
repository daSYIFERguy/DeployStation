<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/host-health.php';

station_require_owner();

$ok = station_flash_get('ok');
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
$autoReload = !empty($routing['nginxAutoReload']);
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

500 "nginx/1.24" usually means nginx reached an upstream and the upstream
returned 500 — compare browser response with a direct curl to the loopback URL
for that project (see table below).
TXT;

?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('Host health', 'Live host diagnostics, routing map, and optional restart commands.') ?>
  <style>
    .host-health-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; margin-top: 16px; }
    .host-health-card { background: var(--panel-bg, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 14px 16px; }
    .host-health-card h3 { margin: 0 0 8px; font-size: 15px; }
    .host-health-card pre { margin: 0; font-size: 12px; line-height: 1.35; max-height: 280px; overflow: auto; white-space: pre-wrap; word-break: break-word; background: var(--code-bg, #f8fafc); padding: 10px; border-radius: 6px; }
    .host-health-meta { font-size: 13px; color: var(--muted, #64748b); margin-bottom: 6px; }
    .host-routing-pre { font-size: 12px; line-height: 1.4; white-space: pre-wrap; background: var(--code-bg, #f8fafc); padding: 12px; border-radius: 8px; overflow: auto; }
    .host-health-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 12px; }
    .host-health-table th, .host-health-table td { border: 1px solid var(--border, #e5e7eb); padding: 8px 10px; text-align: left; vertical-align: top; }
    .host-health-table th { background: var(--code-bg, #f8fafc); }
    .host-health-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; align-items: center; }
    .host-health-actions form { display: inline; }
    .host-health-commands label { display: block; margin-top: 12px; font-weight: 600; font-size: 13px; }
    .host-health-commands textarea { width: 100%; min-height: 52px; font-family: ui-monospace, monospace; font-size: 12px; margin-top: 4px; padding: 8px; border-radius: 6px; border: 1px solid var(--border, #e5e7eb); }
  </style>
</head>
<body class="station-body">
  <div class="dashboard-shell">
    <?= station_dashboard_nav_html('host_health') ?>
    <main class="dashboard-main">
      <header class="dashboard-topbar">
        <div>
          <p class="dashboard-kicker">Owner only</p>
          <h1 class="dashboard-heading">Host health & routing</h1>
          <p class="dashboard-subheading">Bounded snapshots from the same shell user as Station (often <code>www-data</code>). The <strong>HTTP 500 playbook</strong> probes each stack on <code>127.0.0.1:&lt;hostPort&gt;</code> and lists copy-paste <code>curl</code>, <code>ss</code>, and compose log commands for SSH. Use the routing map and optional sudo one-liners the same way as Admin → Projects nginx reload.</p>
        </div>
        <nav class="nav-pills">
          <a href="admin-settings.php">← Admin settings</a>
        </nav>
      </header>

      <?php if ($ok !== ''): ?><div class="alert ok"><?= station_h($ok) ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="alert error"><?= station_h($error) ?></div><?php endif; ?>

      <?php if (is_array($runResult)): ?>
        <div class="alert <?= !empty($runResult['ok']) ? 'ok' : 'error' ?>" style="margin-top:12px;">
          <strong><?= !empty($runResult['ok']) ? 'Command finished' : 'Command failed' ?></strong>
          — exit <?= (int) ($runResult['code'] ?? -1) ?>.
          <?= station_h((string) ($runResult['message'] ?? '')) ?>
        </div>
        <pre class="host-routing-pre" style="margin-top:8px;"><?= station_h($trunc((string) ($runResult['output'] ?? ''))) ?></pre>
      <?php endif; ?>

      <div class="settings-panel" style="margin-top: 16px;">
        <h2 class="settings-panel-heading" style="font-size:16px;">About nginx checks and HTTP 500</h2>
        <p class="setting-description" style="margin:0;">The Host health page runs shell commands as the <strong>same Unix user as PHP</strong> (often <code>www-data</code>), not as root. A full <code>nginx -t</code> reads your real <code>/etc/nginx/nginx.conf</code>. If that file is invalid (for example <strong>duplicate <code>pid</code></strong> lines), the test fails until you fix the file on the server — that is unrelated to Station’s generated <code>projects.conf</code>.</p>
        <p class="setting-description" style="margin:8px 0 0;">The card <strong>nginx: projects.conf only</strong> runs an isolated <code>nginx -t -c</code> on a temporary main config that includes only the Station-managed include, so you can still verify the generated <code>location ^~ /p/…</code> blocks even when the system main config is broken. The warning about the <code>user</code> directive is normal when the test is not run as root. For a root-equivalent full test, set <strong>Nginx config test command</strong> below to e.g. <code>sudo -n nginx -t 2>&1</code> (with matching <code>sudoers</code>). HTTP 500 on <code>/p/…</code> is often a bad <code>auth_request</code> URL, the app inside the container, or upstream — compare the public URL with the loopback URL in the routing table and check nginx / PHP error logs.</p>
      </div>

      <p class="setting-description" style="margin-top:8px;">
        <label class="feature-toggle" style="display:inline-flex;align-items:center;gap:8px;">
          <input type="checkbox" id="hostHealthAutoRefresh">
          <span>Auto-refresh this page every 25 seconds (GET only — clears inline command output above).</span>
        </label>
      </p>

      <div class="settings-panel" style="margin-top: 20px;">
        <h2 class="settings-panel-heading" style="font-size:18px;">Routing map (from Station config)</h2>
        <p class="setting-description">Infrastructure mode: <strong><?= station_h($infra) ?></strong>.
          Nginx docker upstream Host header: <strong><?= station_h($hostMode) ?></strong>
          (<code>preserve</code> = <code>$host</code>; <code>loopback</code> = literal <code>127.0.0.1:port</code>).
          Auto nginx reload after regen: <strong><?= $autoReload ? 'on' : 'off' ?></strong>.
        </p>
        <p class="setting-description"><code>auth_request</code> base path (must match where nginx can reach Station PHP): <code><?= station_h((string) ($routing['nginxAuthRequestBase'] ?? '')) ?></code> — full probe: <code><?= station_h((string) ($routing['nginxAuthRequestBase'] ?? '')) ?>/nginx-docker-auth.php?project=&lt;slug&gt;</code></p>
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

      <div class="settings-panel" style="margin-top: 24px;">
        <h2 class="settings-panel-heading" style="font-size:18px;">HTTP 500 playbook (loopback vs nginx vs app)</h2>
        <p class="setting-description" style="margin:0;">There is no single magic test for every 500. Use this order: <strong>(1)</strong> TCP + HTTP to <code>127.0.0.1:&lt;hostPort&gt;</code> below (same user as PHP — mimics <code>proxy_pass</code> without nginx). <strong>(2)</strong> If loopback is OK but the public <code>/p/…</code> URL fails, suspect <strong>nginx</strong> (<code>auth_request</code>, wrong include, stale reload) or <strong>Host header</strong> (try Admin → Projects loopback vs preserve). <strong>(3)</strong> If loopback returns 5xx, the <strong>container app</strong> is failing — use compose logs. <strong>(4)</strong> Ping is not useful for TCP services; use <code>curl</code> or <code>ss</code> instead.</p>
        <ol class="setting-description" style="margin:12px 0 0 18px;line-height:1.55;">
          <li><strong>Loopback OK, public 500/502</strong> — nginx error log; verify <code>auth_request</code> URL is reachable by nginx; run isolated <code>projects.conf</code> test in the grid above.</li>
          <li><strong>Loopback fails TCP</strong> — container not listening, wrong host port, or compose not up (<code>docker compose ps</code>).</li>
          <li><strong>Loopback HTTP 5xx</strong> — fix the app or its env inside the stack; nginx is not the root cause.</li>
          <li><strong>Loopback 200 but browser 403 on /p/</strong> — Station session / project access (auth subrequest returns 403), not 500.</li>
          <li><strong>Loopback 200 but browser 500 on /p/</strong> — often <code>auth_request</code>: nginx turns a <strong>404/502/500 from the auth subrequest</strong> (wrong path, wrong <code>server_name</code> for Cloudflare Tunnel host, PHP fatal) into <strong>HTTP 500</strong> on the public URL. The loopback container test <strong>never runs auth</strong> — use the auth <code>curl</code> block below.</li>
        </ol>
        <?php
          $authProbeHost = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
          $authBasePath = (string) ($routing['nginxAuthRequestBase'] ?? '');
          $authProbeSlug = '';
          if ($upstreamMatrix !== []) {
              $authProbeSlug = (string) (($upstreamMatrix[0]['slug'] ?? '') ?: '');
          }
          if ($authProbeSlug === '' && !empty($routing['dockerProjects'][0]['slug'])) {
              $authProbeSlug = (string) $routing['dockerProjects'][0]['slug'];
          }
          $authQuery = $authBasePath !== '' && $authProbeSlug !== ''
              ? $authBasePath . '/nginx-docker-auth.php?project=' . rawurlencode($authProbeSlug)
              : '';
        ?>
        <?php if ($authQuery !== '' && $authProbeHost !== ''): ?>
        <div style="margin-top:16px;padding:12px 14px;border-left:4px solid #b45309;background:var(--panel-soft,#fffbeb);border-radius:0 8px 8px 0;">
          <h3 style="margin:0 0 8px;font-size:15px;">Auth subrequest (Cloudflare / reverse proxy)</h3>
          <p class="setting-description" style="margin:0;">Nginx calls <code><?= station_h($authQuery) ?></code> as an internal GET. A bare <code>curl</code> <strong>without</strong> your browser <code>Cookie</code> almost always gets <strong>403</strong> for a <strong>private</strong> project — that is correct, not a misconfiguration. Use <strong>204</strong> only after pasting a real <code>PHPSESSID=…</code> from your browser, or set the project to <strong>Public</strong> in Station (then auth returns <strong>204</strong> without login).</p>
          <p class="setting-description" style="margin:8px 0 0;">If you <strong>are</strong> signed in in the browser but <code>/p/…</code> still fails: behind Cloudflare, PHP often must trust <code>X-Forwarded-Proto: https</code> so the session cookie is marked <strong>Secure</strong>. Station now does that automatically. If your login hostname differs from the URL bar (tunnel hostname), set env <code>STATION_SESSION_COOKIE_DOMAIN</code> and optionally <code>STATION_SESSION_SAMESITE=None</code> (requires HTTPS) in PHP-FPM — see <code>station_session_cookie_params_from_env()</code> in <code>lib/bootstrap.php</code>.</p>
          <p class="setting-description" style="margin:8px 0 6px;">Probe from SSH (add <code>-H &quot;Cookie: PHPSESSID=…&quot;</code> to mimic a logged-in browser):</p>
          <pre class="host-routing-pre" style="margin:0 0 10px;">curl -sSI -H "Host: <?= station_h($authProbeHost) ?>" "http://127.0.0.1<?= station_h($authQuery) ?>"</pre>
          <p class="setting-description" style="margin:0 0 6px;">If nginx only listens on TLS locally:</p>
          <pre class="host-routing-pre" style="margin:0;">curl -sSI -k -H "Host: <?= station_h($authProbeHost) ?>" "https://127.0.0.1<?= station_h($authQuery) ?>"</pre>
          <p class="setting-description" style="margin:10px 0 0;font-size:12px;opacity:0.9;"><strong>404</strong> on these curls → wrong <code>server_name</code> / tunnel mapping or wrong auth path (Admin → Projects). <strong>403</strong> without cookie → expected for private projects. <strong>204</strong> in curl but browser still broken → compare <code>Host</code> / cookie domain / SameSite.</p>
        </div>
        <?php endif; ?>
        <?php if ($upstreamMatrix === []): ?>
          <p class="setting-description" style="margin-top:14px;">No dockerized projects — nothing to probe yet.</p>
        <?php else: ?>
          <p class="setting-description" style="margin-top:14px;">Probes run from this PHP request (typically <code>www-data</code>). Copy any block into SSH on the server.</p>
          <?php foreach ($upstreamMatrix as $um): ?>
            <?php
              $slug = (string) ($um['slug'] ?? '');
              $hp = (int) ($um['hostPort'] ?? 0);
              $pr = $um['probe'] ?? [];
              $tcpOk = !empty($pr['tcp']);
              $httpC = (int) ($pr['httpCode'] ?? 0);
              $sum = $tcpOk ? ('TCP ok' . ($httpC > 0 ? ' · HTTP ' . $httpC : '')) : ('TCP failed: ' . (string) ($pr['tcpError'] ?? ''));
            ?>
            <div style="margin-top:18px;padding:14px;border:1px solid var(--border,#e5e7eb);border-radius:10px;background:var(--panel-soft,#f8fafc);">
              <h3 style="margin:0 0 8px;font-size:15px;"><code><?= station_h($slug) ?></code> — <?= station_h($sum) ?></h3>
              <?php if ((string) ($pr['httpError'] ?? '') !== ''): ?>
                <p class="setting-description" style="margin:0 0 8px;"><strong>HTTP layer:</strong> <?= station_h((string) $pr['httpError']) ?></p>
              <?php endif; ?>
              <?php if ((string) ($pr['bodySnippet'] ?? '') !== ''): ?>
                <p class="setting-description" style="margin:0 0 6px;"><strong>Body snippet:</strong></p>
                <pre class="host-routing-pre" style="margin:0 0 10px;"><?= station_h((string) $pr['bodySnippet']) ?></pre>
              <?php endif; ?>
              <p class="setting-description" style="margin:0 0 6px;">Quick status (HTTP code only):</p>
              <pre class="host-routing-pre" style="margin:0 0 10px;"><?= station_h((string) ($um['shellCurlLoop'] ?? '')) ?></pre>
              <p class="setting-description" style="margin:0 0 6px;">Verbose loopback (headers + tail of body):</p>
              <pre class="host-routing-pre" style="margin:0 0 10px;"><?= station_h((string) ($um['shellCurlLoopVerbose'] ?? '')) ?></pre>
              <?php if ((string) ($um['shellCurlPublic'] ?? '') !== ''): ?>
                <p class="setting-description" style="margin:0 0 6px;">Public URL (through nginx — same as browser without your session cookie):</p>
                <pre class="host-routing-pre" style="margin:0 0 10px;"><?= station_h((string) $um['shellCurlPublic']) ?></pre>
                <p class="setting-description" style="margin:0 0 10px;font-size:12px;opacity:0.85;">A 403 here with 200 on loopback often means <code>auth_request</code> / session; a 5xx on both points at the upstream app or nginx upstream config.</p>
              <?php endif; ?>
              <p class="setting-description" style="margin:0 0 6px;">Who is listening on this port:</p>
              <pre class="host-routing-pre" style="margin:0 0 10px;"><?= station_h((string) ($um['shellSs'] ?? '')) ?></pre>
              <p class="setting-description" style="margin:0 0 6px;">Fetch recent container logs (run on server):</p>
              <pre class="host-routing-pre" style="margin:0;"><?= station_h((string) ($um['shellComposeLogs'] ?? '')) ?></pre>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="settings-panel" style="margin-top: 24px;">
        <h2 class="settings-panel-heading" style="font-size:18px;">Quick actions</h2>
        <p class="setting-description">Default full-config test: <code>nginx -t</code> (or your custom command below). Isolated include test: see the <strong>projects.conf only</strong> card in the grid. Restart lines run only when configured.</p>
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
