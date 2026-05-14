<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/docker.php';

/**
 * Command used for nginx config tests from Host health (PHP user, often www-data).
 *
 * Default: `nginx -t` with `pid` overridden to a file under /tmp so opening
 * /run/nginx.pid is not required (avoids false "test failed" when the real master runs as root).
 *
 * Optional admin override: set `hostNginxTestCommand` to e.g. `sudo -n nginx -t 2>&1` for the
 * same check root would run (requires matching sudoers for the web user).
 */
function station_host_health_nginx_syntax_test_command(): string
{
    $admin = station_admin_settings();
    $override = trim((string) ($admin['hostNginxTestCommand'] ?? ''));
    if ($override !== '') {
        return $override;
    }

    $suffix = (string) getmypid() . '_' . bin2hex(random_bytes(4));
    return 'TMP=/tmp/station-nginx-configtest-' . $suffix . '.pid; rm -f "$TMP" 2>/dev/null; '
        . 'touch "$TMP" && chmod 600 "$TMP" 2>/dev/null; '
        . 'nginx -t -g "pid $TMP;" 2>&1; RC=$?; rm -f "$TMP" 2>/dev/null; exit $RC';
}

/**
 * Run the configured / default nginx syntax test (same as snapshot panel).
 *
 * @return array{ok: bool, code: int, output: string, message: string}
 */
function station_host_health_run_nginx_syntax_test(): array
{
    return station_run_shell_command(station_host_health_nginx_syntax_test_command(), 25);
}

/**
 * Bounded shell snippets for the owner Host health dashboard (same execution
 * environment as Docker CLI from PHP).
 *
 * @return array<string, array{ok: bool, code: int, output: string, message: string}>
 */
function station_host_health_snapshot(): array
{
    $blocks = [
        'uptime' => 'uptime 2>&1',
        'memory' => 'free -h 2>&1 | head -12',
        'disk' => 'df -h 2>&1 | head -22',
        'top_cpu' => 'ps aux --sort=-%cpu 2>&1 | head -22',
        'top_mem' => 'ps aux --sort=-%mem 2>&1 | head -22',
        'listeners' => '(command -v ss >/dev/null 2>&1 && ss -ltnp 2>&1 || netstat -tlnp 2>&1) | head -50',
        'systemd_nginx' => 'systemctl status nginx --no-pager -l 2>&1 | head -40',
        'systemd_docker' => 'systemctl status docker --no-pager -l 2>&1 | head -35',
        'php_fpm_units' => 'for s in php8.4-fpm php8.3-fpm php8.2-fpm php-fpm8.4 php-fpm8.3 php-fpm8.2 php-fpm php8-fpm; do '
            . 'if systemctl cat "$s" >/dev/null 2>&1; then echo -n "$s: "; systemctl is-active "$s" 2>&1; fi; '
            . 'done; true',
        'nginx_syntax' => station_host_health_nginx_syntax_test_command(),
        'docker_ps' => 'docker ps -a --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" 2>&1 | head -45',
        'docker_stats' => 'docker stats --no-stream 2>&1 | head -40',
    ];

    $out = [];
    foreach ($blocks as $key => $cmd) {
        $timeout = 12;
        if ($key === 'docker_stats') {
            $timeout = 20;
        } elseif ($key === 'nginx_syntax') {
            $timeout = 25;
        }
        $out[$key] = station_run_shell_command($cmd, $timeout);
    }

    return $out;
}

/**
 * Station-side routing map (no shell) for /p/… and data paths.
 *
 * @return array<string, mixed>
 */
function station_host_health_routing_map(): array
{
    $admin = station_admin_settings();
    $rows = [];
    foreach (station_collect_dockerized_projects() as $p) {
        $slug = (string) ($p['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        $hostPort = (int) ($p['hostPort'] ?? 0);
        $appPort = (int) ($p['appPort'] ?? 80);
        $st = station_project_docker_status($slug);
        $rows[] = [
            'slug' => $slug,
            'publicPath' => '/p/' . $slug . '/',
            'hostPort' => $hostPort,
            'appPort' => $appPort,
            'composeState' => (string) ($st['state'] ?? 'unknown'),
            'loopbackRoot' => $hostPort > 0 ? 'http://127.0.0.1:' . $hostPort . '/' : '',
        ];
    }

    return [
        'infrastructure' => station_normalize_server_infrastructure((string) ($admin['serverInfrastructure'] ?? 'apache')),
        'nginxInclude' => station_nginx_include_path(),
        'nginxUpstreamHostMode' => (string) ($admin['nginxDockerUpstreamHostMode'] ?? 'preserve'),
        'nginxAutoReload' => !empty($admin['nginxAutoReload']),
        'nginxAuthRequestBase' => station_nginx_auth_request_base_path(),
        'webBasePath' => station_web_base_path(),
        'dataDir' => station_data_dir(),
        'projectsDir' => station_projects_dir(),
        'dockerProjects' => $rows,
    ];
}

/**
 * Run an owner-configured host command from admin settings (must be non-empty).
 */
function station_host_health_run_configured_command(string $settingsKey): array
{
    $admin = station_admin_settings();
    $cmd = trim((string) ($admin[$settingsKey] ?? ''));
    if ($cmd === '') {
        return ['ok' => false, 'code' => -1, 'output' => '', 'message' => 'No command configured for this action. Open this page and save a shell one-liner in the matching field.'];
    }

    return station_run_shell_command($cmd, 120);
}
