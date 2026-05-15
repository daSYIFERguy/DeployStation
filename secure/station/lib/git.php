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
function station_run_git_command(array $command, string $token = '', string $githubAuthMode = ''): array
{
    $askPassPath = '';
    $env = getenv();
    $env = is_array($env) ? $env : [];
    $env['PATH'] = (string) ($env['PATH'] ?? '/usr/local/bin:/usr/bin:/bin');
    $env['GIT_TERMINAL_PROMPT'] = '0';

    if ($token !== '') {
        require_once __DIR__ . '/github-sync.php';
        $gitUser = station_github_git_username($token, $githubAuthMode);
        $askPassPath = station_data_dir() . '/github-askpass-' . bin2hex(random_bytes(6)) . '.sh';
        $askPassScript = "#!/bin/sh\ncase \"\$1\" in\n*Username*) printf '%s\\n' " . escapeshellarg($gitUser) . " ;;\n*Password*) printf '%s\\n' \"\$GITHUB_TOKEN\" ;;\n*) printf '\\n' ;;\nesac\n";
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

/**
 * Parse `git status --porcelain`. Tracked edits are "dirty"; untracked files are separate
 * so local-only paths (.env.local) do not read as a dirty tree after a clean GitHub sync.
 *
 * @param list<string> $ignoredUntrackedBasenames Basenames ignored for untracked counts (station secrets)
 * @return array{
 *   dirty: bool,
 *   dirty_count: int,
 *   dirty_preview: string,
 *   untracked_count: int,
 *   untracked_preview: string,
 *   untracked_ignored_count: int,
 *   has_untracked: bool
 * }
 */
function station_git_worktree_summary(string $porcelain, array $ignoredUntrackedBasenames = ['.env', '.env.local', '.env.production', '.env.development']): array
{
    $modified = [];
    $untracked = [];
    $ignoredUntracked = 0;

    foreach (explode("\n", trim($porcelain)) as $line) {
        if ($line === '') {
            continue;
        }
        if (str_starts_with($line, '?? ')) {
            $path = substr($line, 3);
            $base = basename($path);
            if (in_array($base, $ignoredUntrackedBasenames, true)) {
                $ignoredUntracked++;
                continue;
            }
            $untracked[] = $path;
            continue;
        }
        if (str_starts_with($line, '!! ')) {
            continue;
        }
        $modified[] = $line;
    }

    return [
        'dirty' => $modified !== [],
        'dirty_count' => count($modified),
        'dirty_preview' => mb_substr(str_replace(["\r", "\n"], ' | ', implode("\n", $modified)), 0, 200),
        'untracked_count' => count($untracked),
        'untracked_preview' => mb_substr(str_replace(["\r", "\n"], ' | ', implode("\n", $untracked)), 0, 200),
        'untracked_ignored_count' => $ignoredUntracked,
        'has_untracked' => $untracked !== [],
    ];
}
