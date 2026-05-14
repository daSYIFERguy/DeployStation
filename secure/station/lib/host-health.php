<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/docker.php';

/**
 * Command used for nginx config tests from Host health (PHP user, often www-data).
 *
 * Default: plain `nginx -t` against the system main config. Do **not** add `-g "pid …"`
 * here: most installs already declare `pid` in nginx.conf, and a second `pid` causes
 * [emerg] duplicate directive / test failure.
 *
 * Optional admin override: set `hostNginxTestCommand` to e.g. `sudo -n nginx -t 2>&1` for
 * root-equivalent checks (sudoers), or a wrapper script if the PHP user cannot read pid paths.
 *
 * For validating **only** the Station-generated `projects.conf`, use
 * `station_host_health_run_nginx_projects_include_syntax_test()` (isolated `nginx -t -c`).
 */
function station_host_health_nginx_syntax_test_command(): string
{
    $admin = station_admin_settings();

    return station_admin_resolved_shell_command($admin, 'hostNginxTestCommand');
}

/**
 * Run `nginx -t` against a throwaway main config that includes only `projects.conf`.
 * Does not read `/etc/nginx/nginx.conf`, so it still works when the system main file is
 * broken (e.g. duplicate `pid`) while you verify Station’s generated snippet parses.
 *
 * @return array{ok: bool, code: int, output: string, message: string}
 */
function station_host_health_run_nginx_projects_include_syntax_test(): array
{
    $includePath = station_nginx_include_path();
    if (!is_file($includePath) || !is_readable($includePath)) {
        return [
            'ok' => true,
            'code' => 0,
            'output' => 'No readable projects.conf yet at: ' . $includePath . "\n"
                . '(Normal until the first dockerized project writes the include.)',
            'message' => 'Skipped — no include file.',
        ];
    }

    $resolved = realpath($includePath);
    $includePath = $resolved !== false ? str_replace('\\', '/', $resolved) : str_replace('\\', '/', $includePath);

    $suffix = bin2hex(random_bytes(8));
    $dir = rtrim(sys_get_temp_dir(), '/');
    $wrapper = $dir . '/station-ngx-wrap-' . $suffix . '.conf';
    $pidFile = $dir . '/station-ngx-pid-' . $suffix . '.pid';

    $body = 'pid ' . $pidFile . ";\n"
        . "events { worker_connections 64; }\n"
        . "http {\n"
        . "    include /etc/nginx/mime.types;\n"
        . "    default_type application/octet-stream;\n"
        . "    server {\n"
        . "        listen 127.0.0.1:28999;\n"
        . "        server_name _;\n"
        . '        include ' . $includePath . ";\n"
        . "    }\n"
        . "}\n";

    if (@file_put_contents($wrapper, $body) === false) {
        return [
            'ok' => false,
            'code' => -1,
            'output' => '',
            'message' => 'Could not write temporary nginx wrapper config under ' . $dir . '.',
        ];
    }

    $cmd = 'nginx -t -c ' . escapeshellarg($wrapper) . ' 2>&1';
    $result = station_run_shell_command($cmd, 25);
    @unlink($wrapper);
    @unlink($pidFile);

    return $result;
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

    $out['nginx_projects_include'] = station_host_health_run_nginx_projects_include_syntax_test();

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

/**
 * Browser-visible origin for curl examples (e.g. https://syifer.dev), or empty if unknown.
 */
function station_host_health_infer_public_origin(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return '';
    }

    return $scheme . '://' . $host;
}

/**
 * TCP + quick HTTP/1.1 GET to the published host port (same target as nginx proxy_pass).
 * Uses Host: 127.0.0.1:port — some apps differ vs public Host; compare with the public curl line in Host health.
 *
 * @return array{tcp: bool, tcpError: string, httpCode: int, httpError: string, bodySnippet: string}
 */
function station_host_health_probe_loopback_http(int $port, string $path = '/'): array
{
    $out = [
        'tcp' => false,
        'tcpError' => '',
        'httpCode' => 0,
        'httpError' => '',
        'bodySnippet' => '',
    ];
    if ($port <= 0 || $port > 65535) {
        $out['tcpError'] = 'invalid port';

        return $out;
    }

    $path = $path === '' ? '/' : (str_starts_with($path, '/') ? $path : '/' . $path);
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 3.0);
    if (!is_resource($fp)) {
        $out['tcpError'] = $errstr !== '' ? $errstr . ' (' . $errno . ')' : 'connection refused or timeout';

        return $out;
    }
    $out['tcp'] = true;
    stream_set_timeout($fp, 6);
    $req = 'GET ' . $path . " HTTP/1.1\r\n"
        . 'Host: 127.0.0.1:' . $port . "\r\n"
        . "User-Agent: DeploymentStation-HealthProbe/1\r\n"
        . "Connection: close\r\n\r\n";
    fwrite($fp, $req);

    $response = '';
    while (!feof($fp)) {
        $chunk = fread($fp, 16384);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $response .= $chunk;
        $meta = stream_get_meta_data($fp);
        if (!empty($meta['timed_out'])) {
            $out['httpError'] = 'read timeout';
            break;
        }
        if (strlen($response) > 524288) {
            break;
        }
    }
    fclose($fp);

    if ($response === '') {
        $out['httpError'] = 'empty response (non-HTTP service or immediate close)';

        return $out;
    }

    if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})\b/m', $response, $m)) {
        $out['httpCode'] = (int) $m[1];
    }

    $pos = strpos($response, "\r\n\r\n");
    $body = $pos !== false ? substr($response, $pos + 4) : '';

    if ($out['httpCode'] >= 500) {
        $out['httpError'] = 'application inside the container returned 5xx';
    } elseif ($out['httpCode'] === 0) {
        $out['httpError'] = 'not valid HTTP on this port (TLS? wrong process?)';
    }

    if ($body !== '') {
        $out['bodySnippet'] = mb_substr(trim(preg_replace('/\s+/', ' ', $body)), 0, 320);
    }

    return $out;
}

/**
 * Per-docker-project loopback probes + copy-paste shell snippets for SSH debugging.
 *
 * @return list<array<string, mixed>>
 */
function station_host_health_docker_upstream_matrix(): array
{
    $origin = station_host_health_infer_public_origin();
    $rows = [];

    foreach (station_collect_dockerized_projects() as $p) {
        $slug = (string) ($p['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        $hostPort = (int) ($p['hostPort'] ?? 0);
        $probe = station_host_health_probe_loopback_http($hostPort, '/');
        $projDir = station_projects_dir() . '/' . $slug;
        $curlLoop = 'curl -sS -o /dev/null -w "HTTP %{http_code}\n" --max-time 8 http://127.0.0.1:' . $hostPort . '/';
        $curlLoopVerbose = 'curl -sv --max-time 8 -H "Host: 127.0.0.1:' . $hostPort . '" http://127.0.0.1:' . $hostPort . '/ 2>&1 | tail -n 40';
        $curlPublic = $origin !== ''
            ? 'curl -sS -o /dev/null -w "HTTP %{http_code}\n" --max-time 15 ' . escapeshellarg($origin . '/p/' . rawurlencode($slug) . '/')
            : '';
        $ss = '(command -v ss >/dev/null 2>&1 && ss -ltnp | grep -F ":'
            . $hostPort . ' ") || (command -v netstat >/dev/null 2>&1 && netstat -tlnp 2>/dev/null | grep -F ":'
            . $hostPort . ' ") || true';
        $logs = 'cd ' . escapeshellarg($projDir) . ' && docker compose logs --tail=60 2>&1';

        $rows[] = [
            'slug' => $slug,
            'hostPort' => $hostPort,
            'appPort' => (int) ($p['appPort'] ?? 80),
            'projectDir' => $projDir,
            'probe' => $probe,
            'shellCurlLoop' => $curlLoop,
            'shellCurlLoopVerbose' => $curlLoopVerbose,
            'shellCurlPublic' => $curlPublic,
            'shellSs' => $ss,
            'shellComposeLogs' => $logs,
        ];
    }

    return $rows;
}
