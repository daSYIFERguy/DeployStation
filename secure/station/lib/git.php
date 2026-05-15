<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function station_git_available(): bool
{
    $result = station_run_git_command(['git', '--version']);

    return !empty($result['ok']);
}

function station_detect_current_git_branch(string $projectPath): string
{
    $result = station_run_git_command(['git', '-C', $projectPath, 'branch', '--show-current']);

    return !empty($result['ok']) ? trim((string) ($result['output'] ?? '')) : '';
}

/**
 * @param list<string> $command
 * @return array{ok: bool, code: int, output: string}
 */
function station_run_git_command(array $command, string $token = ''): array
{
    $askPassPath = '';
    $env = getenv();
    $env = is_array($env) ? $env : [];
    $env['PATH'] = (string) ($env['PATH'] ?? '/usr/local/bin:/usr/bin:/bin');
    $env['GIT_TERMINAL_PROMPT'] = '0';

    if ($token !== '') {
        $askPassPath = station_data_dir() . '/github-askpass-' . bin2hex(random_bytes(6)) . '.sh';
        $askPassScript = <<<'BASH'
#!/bin/sh
case "$1" in
*Username*) printf '%s\n' 'x-access-token' ;;
*Password*) printf '%s\n' "$GITHUB_TOKEN" ;;
*) printf '\n' ;;
esac
BASH;
        if (@file_put_contents($askPassPath, $askPassScript, LOCK_EX) === false || !@chmod($askPassPath, 0700)) {
            return ['ok' => false, 'code' => -1, 'output' => 'Could not prepare GitHub credentials.'];
        }
        $env['GIT_ASKPASS'] = $askPassPath;
        $env['GITHUB_TOKEN'] = $token;
    }

    $commandString = implode(' ', array_map('escapeshellarg', $command));
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($commandString, $descriptors, $pipes, null, $env);
    if (!is_resource($process)) {
        if ($askPassPath !== '') {
            @unlink($askPassPath);
        }

        return ['ok' => false, 'code' => -1, 'output' => 'Could not start git.'];
    }

    $stdout = isset($pipes[1]) ? (string) stream_get_contents($pipes[1]) : '';
    $stderr = isset($pipes[2]) ? (string) stream_get_contents($pipes[2]) : '';
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    $code = proc_close($process);
    if ($askPassPath !== '') {
        @unlink($askPassPath);
    }

    return [
        'ok' => $code === 0,
        'code' => $code,
        'output' => trim($stdout . "\n" . $stderr),
    ];
}
