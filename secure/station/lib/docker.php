<?php

declare(strict_types=1);

require_once __DIR__ . '/projects.php';

function station_docker_safe_identifier(string $value, string $fallback): string
{
    $clean = strtolower(trim($value));
    $clean = preg_replace('/[^a-z0-9_.-]+/', '-', $clean) ?? '';
    $clean = trim($clean, '.-_');
    return $clean !== '' ? $clean : $fallback;
}

function station_normalize_docker_port(mixed $value, int $fallback): int
{
    $port = (int) $value;
    if ($port < 1 || $port > 65535) {
        return $fallback;
    }
    return $port;
}

function station_project_docker_host_port(string $slug): int
{
    return 41000 + ((int) (crc32(station_safe_name($slug)) % 20000));
}

function station_project_runtime_type(string $slug): string
{
    $path = station_project_path($slug);
    if (is_file($path . '/package.json')) {
        return 'node';
    }
    if (is_file($path . '/composer.json') || is_file($path . '/index.php')) {
        return 'php';
    }
    return 'static';
}

function station_project_node_container_port(string $slug): int
{
    $packagePath = station_project_path($slug) . '/package.json';
    $package = station_read_json($packagePath, []);
    $scripts = isset($package['scripts']) && is_array($package['scripts']) ? $package['scripts'] : [];
    $scriptText = strtolower(implode(' ', array_map('strval', array_values($scripts))));
    return str_contains($scriptText, 'vite') ? 5173 : 3000;
}

function station_project_docker_defaults(string $slug): array
{
    $safe = station_safe_name($slug);
    $runtime = station_project_runtime_type($safe);
    $containerPort = $runtime === 'node' ? station_project_node_container_port($safe) : 80;

    return [
        'enabled' => false,
        'autoStart' => true,
        'runtime' => $runtime,
        'imageName' => 'station-' . $safe,
        'containerName' => 'station-' . $safe,
        'hostPort' => station_project_docker_host_port($safe),
        'containerPort' => $containerPort,
        'dockerfile' => 'Dockerfile',
        'launchPath' => '/',
    ];
}

function station_project_docker_settings(string $slug, ?array $settings = null): array
{
    $defaults = station_project_docker_defaults($slug);
    $source = is_array($settings) ? $settings : station_project_settings($slug);
    $docker = isset($source['docker']) && is_array($source['docker']) ? $source['docker'] : [];
    $merged = array_merge($defaults, $docker);

    $merged['enabled'] = !empty($merged['enabled']);
    $merged['autoStart'] = !array_key_exists('autoStart', $merged) || !empty($merged['autoStart']);
    $merged['runtime'] = in_array((string) ($merged['runtime'] ?? ''), ['node', 'php', 'static'], true)
        ? (string) $merged['runtime']
        : $defaults['runtime'];
    $merged['imageName'] = station_docker_safe_identifier((string) ($merged['imageName'] ?? ''), (string) $defaults['imageName']);
    $merged['containerName'] = station_docker_safe_identifier((string) ($merged['containerName'] ?? ''), (string) $defaults['containerName']);
    $merged['hostPort'] = station_normalize_docker_port($merged['hostPort'] ?? null, (int) $defaults['hostPort']);
    $merged['containerPort'] = station_normalize_docker_port($merged['containerPort'] ?? null, (int) $defaults['containerPort']);

    $dockerfile = trim(str_replace('\\', '/', (string) ($merged['dockerfile'] ?? 'Dockerfile')));
    $merged['dockerfile'] = station_is_safe_relative_path($dockerfile) ? $dockerfile : 'Dockerfile';

    $launchPath = trim(str_replace('\\', '/', (string) ($merged['launchPath'] ?? '/')));
    if ($launchPath === '' || str_contains($launchPath, '..') || preg_match('/[[:cntrl:]]/', $launchPath)) {
        $launchPath = '/';
    }
    $merged['launchPath'] = '/' . ltrim($launchPath, '/');

    return $merged;
}

function station_exec_argv(array $argv, ?string $cwd = null, int $timeoutSeconds = 0): array
{
    if (!function_exists('exec')) {
        return ['ok' => false, 'code' => 127, 'output' => 'PHP exec() is disabled on this server.'];
    }

    $parts = [];
    foreach ($argv as $arg) {
        $parts[] = escapeshellarg((string) $arg);
    }

    $command = implode(' ', $parts);
    if ($timeoutSeconds > 0) {
        $command = 'timeout ' . (int) $timeoutSeconds . ' ' . $command;
    }
    if (is_string($cwd) && $cwd !== '') {
        $command = 'cd ' . escapeshellarg($cwd) . ' && ' . $command;
    }

    $lines = [];
    $code = 0;
    exec($command . ' 2>&1', $lines, $code);

    return [
        'ok' => $code === 0,
        'code' => $code,
        'output' => implode("\n", $lines),
    ];
}

function station_docker_available(): bool
{
    $result = station_exec_argv(['docker', 'version', '--format', '{{.Server.Version}}'], null, 10);
    return !empty($result['ok']);
}

function station_project_docker_status(string $slug, ?array $settings = null): array
{
    $docker = station_project_docker_settings($slug, $settings);
    if (!station_docker_available()) {
        return [
            'available' => false,
            'running' => false,
            'exists' => false,
            'label' => 'Docker unavailable',
            'detail' => 'The Docker CLI or daemon is not available to PHP.',
        ];
    }

    $inspect = station_exec_argv(['docker', 'inspect', '--format={{.State.Running}}', (string) $docker['containerName']], null, 15);
    if (empty($inspect['ok'])) {
        return [
            'available' => true,
            'running' => false,
            'exists' => false,
            'label' => 'Not started',
            'detail' => trim((string) $inspect['output']),
        ];
    }

    $running = trim((string) $inspect['output']) === 'true';
    return [
        'available' => true,
        'running' => $running,
        'exists' => true,
        'label' => $running ? 'Running' : 'Stopped',
        'detail' => '',
    ];
}

function station_project_docker_url(array $docker): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    if (substr_count($host, ':') === 1 && !str_starts_with($host, '[')) {
        $host = explode(':', $host, 2)[0];
    }

    return $scheme . '://' . $host . ':' . (int) $docker['hostPort'] . (string) $docker['launchPath'];
}

function station_project_dockerfile_contents(string $slug, array $docker): string
{
    $runtime = (string) $docker['runtime'];
    $containerPort = (int) $docker['containerPort'];

    if ($runtime === 'node') {
        return "FROM node:22-alpine\nWORKDIR /app\nCOPY package*.json ./\nRUN if [ -f package-lock.json ]; then npm ci; else npm install; fi\nCOPY . .\nENV HOST=0.0.0.0\nENV PORT={$containerPort}\nEXPOSE {$containerPort}\nCMD [\"sh\", \"-c\", \"if npm run | grep -q ' start'; then npm start; elif npm run | grep -q ' dev'; then npm run dev -- --host 0.0.0.0; elif [ -f server.js ]; then node server.js; else npx --yes serve -l \\${PORT:-{$containerPort}} .; fi\"]\n";
    }

    if ($runtime === 'php') {
        return "FROM php:8.3-apache\nCOPY . /var/www/html/\nRUN chown -R www-data:www-data /var/www/html\nEXPOSE {$containerPort}\n";
    }

    return "FROM nginx:1.27-alpine\nCOPY . /usr/share/nginx/html\nEXPOSE {$containerPort}\n";
}

function station_project_dockerignore_contents(): string
{
    return ".git\n.gitignore\nnode_modules\n.env\n.env.*\nDockerfile\n.dockerignore\nnpm-debug.log*\nyarn-debug.log*\nyarn-error.log*\n";
}

function station_generate_project_docker_assets(string $slug, array $settings, bool $overwrite = false): array
{
    $docker = station_project_docker_settings($slug, $settings);
    $dockerfile = (string) $docker['dockerfile'];
    $projectPath = station_project_path($slug);
    $dockerfilePath = $projectPath . '/' . $dockerfile;
    $dockerignorePath = $projectPath . '/.dockerignore';
    $written = [];

    if (!is_file($dockerfilePath) || $overwrite) {
        if (!station_write_project_file($slug, $dockerfile, station_project_dockerfile_contents($slug, $docker))) {
            return ['ok' => false, 'message' => 'Could not write Dockerfile.'];
        }
        $written[] = $dockerfile;
    }

    if (!is_file($dockerignorePath) || $overwrite) {
        if (!station_write_project_file($slug, '.dockerignore', station_project_dockerignore_contents())) {
            return ['ok' => false, 'message' => 'Could not write .dockerignore.'];
        }
        $written[] = '.dockerignore';
    }

    return [
        'ok' => true,
        'message' => $written ? 'Docker files generated: ' . implode(', ', $written) : 'Existing Docker files will be used.',
        'written' => $written,
    ];
}

function station_start_project_docker(string $slug, array $settings): array
{
    if (!station_docker_available()) {
        return ['ok' => false, 'message' => 'Docker is not available to PHP on this server.'];
    }

    $docker = station_project_docker_settings($slug, $settings);
    $assets = station_generate_project_docker_assets($slug, $settings, false);
    if (empty($assets['ok'])) {
        return $assets;
    }

    $projectPath = station_project_path($slug);
    $build = station_exec_argv(
        ['docker', 'build', '-t', (string) $docker['imageName'], '-f', (string) $docker['dockerfile'], '.'],
        $projectPath,
        300
    );
    if (empty($build['ok'])) {
        return ['ok' => false, 'message' => 'Docker build failed.', 'output' => (string) $build['output']];
    }

    station_exec_argv(['docker', 'rm', '-f', (string) $docker['containerName']], null, 60);

    $portMap = (int) $docker['hostPort'] . ':' . (int) $docker['containerPort'];
    $runArgs = [
        'docker',
        'run',
        '-d',
        '--name',
        (string) $docker['containerName'],
        '-p',
        $portMap,
        '-e',
        'PORT=' . (int) $docker['containerPort'],
    ];

    $envPath = $projectPath . '/.env.local';
    if (is_file($envPath)) {
        $runArgs[] = '--env-file';
        $runArgs[] = $envPath;
    }

    $runArgs[] = (string) $docker['imageName'];
    $run = station_exec_argv($runArgs, null, 120);
    if (empty($run['ok'])) {
        return ['ok' => false, 'message' => 'Docker container failed to start.', 'output' => (string) $run['output']];
    }

    station_log_event('project.docker.started', ['slug' => $slug, 'container' => $docker['containerName'], 'port' => $docker['hostPort']]);

    return [
        'ok' => true,
        'message' => 'Docker container started.',
        'url' => station_project_docker_url($docker),
        'output' => trim((string) $run['output']),
    ];
}

function station_stop_project_docker(string $slug, array $settings): array
{
    if (!station_docker_available()) {
        return ['ok' => false, 'message' => 'Docker is not available to PHP on this server.'];
    }

    $docker = station_project_docker_settings($slug, $settings);
    $stop = station_exec_argv(['docker', 'rm', '-f', (string) $docker['containerName']], null, 60);
    if (empty($stop['ok'])) {
        return ['ok' => false, 'message' => 'Could not stop the Docker container.', 'output' => (string) $stop['output']];
    }

    station_log_event('project.docker.stopped', ['slug' => $slug, 'container' => $docker['containerName']]);
    return ['ok' => true, 'message' => 'Docker container stopped.'];
}

function station_project_docker_cli_preview(string $slug, array $settings): string
{
    $docker = station_project_docker_settings($slug, $settings);
    $projectPath = station_project_path($slug);
    $portMap = (int) $docker['hostPort'] . ':' . (int) $docker['containerPort'];

    return 'cd ' . escapeshellarg($projectPath) . "\n"
        . 'docker build -t ' . escapeshellarg((string) $docker['imageName']) . ' -f ' . escapeshellarg((string) $docker['dockerfile']) . " .\n"
        . 'docker rm -f ' . escapeshellarg((string) $docker['containerName']) . " 2>/dev/null || true\n"
        . 'docker run -d --name ' . escapeshellarg((string) $docker['containerName'])
        . ' -p ' . escapeshellarg($portMap)
        . ' -e ' . escapeshellarg('PORT=' . (int) $docker['containerPort'])
        . ' ' . escapeshellarg((string) $docker['imageName']);
}
