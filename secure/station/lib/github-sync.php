<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/git.php';
require_once __DIR__ . '/projects.php';
require_once __DIR__ . '/docker.php';

/**
 * @return array{ok: bool, code: int, body: string, message: string}
 */
function station_github_api(string $method, string $pathQuery, string $token, ?array $jsonBody = null): array
{
    $token = trim($token);
    if ($token === '') {
        return ['ok' => false, 'code' => 0, 'body' => '', 'message' => 'Missing GitHub token.'];
    }
    if (!str_starts_with($pathQuery, '/')) {
        $pathQuery = '/' . $pathQuery;
    }
    $url = 'https://api.github.com' . $pathQuery;

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'code' => 0, 'body' => '', 'message' => 'PHP curl extension is required for GitHub API calls.'];
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'code' => 0, 'body' => '', 'message' => 'Could not initialize curl.'];
    }

    $headers = [
        'Accept: application/vnd.github+json',
        'User-Agent: DeploymentStation-GitHubSync/1',
        'Authorization: Bearer ' . $token,
        'X-GitHub-Api-Version: 2022-11-28',
    ];

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
    ];
    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody, JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            curl_close($ch);

            return ['ok' => false, 'code' => 0, 'body' => '', 'message' => 'Invalid JSON body.'];
        }
        $opts[CURLOPT_POSTFIELDS] = $payload;
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
    }

    curl_setopt_array($ch, $opts);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === '' && $err !== '') {
        return ['ok' => false, 'code' => $code, 'body' => '', 'message' => $err];
    }

    $ok = $code >= 200 && $code < 300;

    return ['ok' => $ok, 'code' => $code, 'body' => $body, 'message' => $ok ? 'OK' : ('HTTP ' . $code)];
}

function station_github_api_error_detail(array $apiResult): string
{
    $body = trim((string) ($apiResult['body'] ?? ''));
    if ($body === '') {
        return '';
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return mb_substr($body, 0, 240);
    }
    $msg = trim((string) ($data['message'] ?? ''));
    $doc = trim((string) ($data['documentation_url'] ?? ''));

    return $msg !== '' ? $msg . ($doc !== '' ? ' (' . $doc . ')' : '') : mb_substr($body, 0, 240);
}

/**
 * Git HTTPS username embedded in remote URLs and GIT_ASKPASS.
 */
function station_github_git_auth_mode(string $token, string $authMode = ''): string
{
    $authMode = strtolower(trim($authMode));
    if ($authMode === 'oauth' || $authMode === 'pat') {
        return $authMode;
    }

    return str_starts_with(trim($token), 'gho_') ? 'oauth' : 'pat';
}

function station_github_git_username(string $token, string $authMode = ''): string
{
    if (station_github_git_auth_mode($token, $authMode) === 'oauth') {
        return 'oauth2';
    }

    return 'x-access-token';
}

function station_github_https_remote(string $owner, string $name, string $token, string $authMode = ''): string
{
    $user = station_github_git_username($token, $authMode);

    return 'https://' . $user . ':' . rawurlencode(trim($token)) . '@github.com/' . $owner . '/' . $name . '.git';
}

/**
 * @return array{ok: bool, message: string, html_url?: string}
 */
function station_github_create_private_repo(string $token, string $owner, string $name, string $description = ''): array
{
    $name = preg_replace('/[^A-Za-z0-9._-]/', '-', $name) ?? $name;
    $name = trim((string) $name, '-.');
    if ($name === '') {
        return ['ok' => false, 'message' => 'Invalid repository name.'];
    }

    $user = station_github_api('GET', '/user', $token);
    if (empty($user['ok'])) {
        return ['ok' => false, 'message' => 'GitHub token rejected: ' . ($user['message'] ?? '')];
    }
    $login = '';
    $me = json_decode((string) ($user['body'] ?? ''), true);
    if (is_array($me)) {
        $login = (string) ($me['login'] ?? '');
    }
    if ($login === '') {
        return ['ok' => false, 'message' => 'Could not read GitHub username from token.'];
    }

    $path = $login === $owner ? '/user/repos' : '/orgs/' . rawurlencode($owner) . '/repos';
    $res = station_github_api('POST', $path, $token, [
        'name' => $name,
        'private' => true,
        'description' => $description !== '' ? $description : 'Private project mirror from Deployment Station',
        'auto_init' => false,
    ]);

    if (!empty($res['ok'])) {
        $data = json_decode((string) ($res['body'] ?? ''), true);
        $html = is_array($data) ? (string) ($data['html_url'] ?? '') : '';

        return ['ok' => true, 'message' => 'Repository created on GitHub.', 'html_url' => $html];
    }

    $detail = mb_substr((string) ($res['body'] ?? ''), 0, 400);

    return ['ok' => false, 'message' => 'Create repo failed (' . ($res['code'] ?? 0) . '): ' . $detail];
}

/**
 * @return array<string, mixed>
 */
function station_github_project_sync_row(string $slug, string $token, string $authMode = ''): array
{
    $authMode = station_github_git_auth_mode($token, $authMode);
    $path = station_project_path($slug);
    $settings = station_project_settings($slug);
    $gh = isset($settings['github']) && is_array($settings['github']) ? $settings['github'] : [];
    [$owner, $name] = station_github_normalize_repo_owner_name(
        (string) ($gh['repoOwner'] ?? ''),
        (string) ($gh['repoName'] ?? '')
    );
    $branch = trim((string) ($gh['defaultBranch'] ?? 'main')) ?: 'main';
    $row = [
        'slug' => $slug,
        'repoOwner' => $owner,
        'repoName' => $name,
        'branch' => $branch,
        'has_git' => is_dir($path . '/.git'),
        'dirty' => false,
        'dirty_count' => 0,
        'dirty_preview' => '',
        'untracked_count' => 0,
        'untracked_preview' => '',
        'untracked_ignored_count' => 0,
        'has_untracked' => false,
        'ahead' => 0,
        'behind' => 0,
        'remote_url' => '',
        'error' => '',
        'compare_url' => '',
        'prs_url' => '',
    ];

    if ($owner === '' || $name === '') {
        $row['error'] = 'Set GitHub owner and repository under Project → GitHub.';

        return $row;
    }

    $row['compare_url'] = 'https://github.com/' . rawurlencode($owner) . '/' . rawurlencode($name) . '/compare';
    $row['prs_url'] = 'https://github.com/' . rawurlencode($owner) . '/' . rawurlencode($name) . '/pulls';

    if (!$row['has_git']) {
        $row['error'] = 'No git repository in project folder yet. Use “Init & link” or import from GitHub.';
        if (trim($token) !== '') {
            $prs = station_github_fetch_open_prs_summary($token, $owner, $name);
            $row['open_pr_count'] = (int) ($prs['count'] ?? 0);
            $row['open_prs'] = $prs['items'] ?? [];
            $row['open_prs_truncated'] = !empty($prs['truncated']);
            if (!empty($prs['api_error'])) {
                $row['pr_api_error'] = (string) $prs['api_error'];
            }
        }

        return $row;
    }

    $st = station_run_git_command(['git', '-C', $path, 'status', '--porcelain'], $token, $authMode);
    if (!empty($st['ok'])) {
        $summary = station_git_worktree_summary(trim((string) ($st['output'] ?? '')));
        $row['dirty'] = !empty($summary['dirty']);
        $row['dirty_count'] = (int) $summary['dirty_count'];
        $row['dirty_preview'] = (string) $summary['dirty_preview'];
        $row['untracked_count'] = (int) $summary['untracked_count'];
        $row['untracked_preview'] = (string) $summary['untracked_preview'];
        $row['untracked_ignored_count'] = (int) $summary['untracked_ignored_count'];
        $row['has_untracked'] = !empty($summary['has_untracked']);
    }

    $ru = station_run_git_command(['git', '-C', $path, 'remote', 'get-url', 'origin'], $token, $authMode);
    $row['remote_url'] = !empty($ru['ok']) ? trim((string) ($ru['output'] ?? '')) : '';

    $fb = station_run_git_command(['git', '-C', $path, 'fetch', 'origin'], $token, $authMode);
    if (empty($fb['ok'])) {
        $fetchOut = (string) ($fb['output'] ?? '');
        if (stripos($fetchOut, 'Repository not found') !== false) {
            $row['error'] = 'GitHub repository ' . $owner . '/' . $name . ' was not found. Create it on GitHub or use “Create private repo & push”, then fix owner/name if needed.';
        } else {
            $row['error'] = 'git fetch failed: ' . mb_substr($fetchOut, 0, 240);
        }

        return $row;
    }

    $ab = station_run_git_command(
        ['git', '-C', $path, 'rev-list', '--left-right', '--count', 'origin/' . $branch . '...HEAD'],
        $token,
        $authMode
    );
    if (!empty($ab['ok'])) {
        $parts = preg_split('/\s+/', trim((string) ($ab['output'] ?? '')));
        if (is_array($parts) && count($parts) === 2) {
            $row['behind'] = (int) $parts[0];
            $row['ahead'] = (int) $parts[1];
        }
    }

    if ($row['error'] === '' && trim($token) !== '') {
        $prs = station_github_fetch_open_prs_summary($token, $owner, $name);
        $row['open_pr_count'] = (int) ($prs['count'] ?? 0);
        $row['open_prs'] = $prs['items'] ?? [];
        $row['open_prs_truncated'] = !empty($prs['truncated']);
        if (!empty($prs['api_error'])) {
            $row['pr_api_error'] = (string) $prs['api_error'];
        }
    }

    return $row;
}

/**
 * @return array{0: string, 1: string}
 */
function station_github_normalize_repo_owner_name(string $owner, string $name): array
{
    $owner = trim($owner);
    $name = trim($name);
    if (str_contains($name, 'github.com/')) {
        if (preg_match('#github\.com/([^/]+)/([^/?#]+)#i', $name, $m)) {
            $owner = $owner !== '' ? $owner : (string) $m[1];
            $name = (string) $m[2];
        }
    }
    if ($owner === '' && str_contains($name, '/')) {
        [$ownerPart, $namePart] = array_pad(explode('/', $name, 2), 2, '');
        $owner = trim($ownerPart);
        $name = trim($namePart);
    }
    $name = preg_replace('/\.git$/i', '', $name) ?? $name;

    return [$owner, $name];
}

function station_github_repo_api_accessible(string $token, string $owner, string $repo): ?bool
{
    $r = station_github_api('GET', '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo), $token);
    if (!empty($r['ok'])) {
        return true;
    }
    if ((int) ($r['code'] ?? 0) === 404) {
        return false;
    }

    return null;
}

/**
 * @return array{count: int, items: list<array{title: string, url: string, number: int}>, truncated?: bool, api_error?: string}
 */
function station_github_fetch_open_prs_summary(string $token, string $owner, string $repo): array
{
    [$owner, $repo] = station_github_normalize_repo_owner_name($owner, $repo);
    if ($owner === '' || $repo === '' || trim($token) === '') {
        return ['count' => 0, 'items' => [], 'truncated' => false];
    }

    $repoAccess = station_github_repo_api_accessible($token, $owner, $repo);
    if ($repoAccess === false) {
        return [
            'count' => 0,
            'items' => [],
            'truncated' => false,
            'api_error' => 'Repository ' . $owner . '/' . $repo . ' was not found, or your GitHub token cannot access it. Fix owner/name under Project → GitHub or create the repo first.',
        ];
    }

    $path = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/pulls?state=open&per_page=30&sort=updated&direction=desc';
    $r = station_github_api('GET', $path, $token);
    if (empty($r['ok'])) {
        $code = (int) ($r['code'] ?? 0);
        $hint = $code === 404
            ? 'Pull requests could not be loaded (repo missing or token lacks access).'
            : ('Pull requests unavailable (HTTP ' . $code . ').');

        return [
            'count' => 0,
            'items' => [],
            'truncated' => false,
            'api_error' => $hint,
        ];
    }
    $data = json_decode((string) ($r['body'] ?? ''), true);
    if (!is_array($data)) {
        return ['count' => 0, 'items' => [], 'truncated' => false, 'api_error' => 'PR list: invalid JSON'];
    }
    $items = [];
    foreach ($data as $pr) {
        if (!is_array($pr)) {
            continue;
        }
        $num = (int) ($pr['number'] ?? 0);
        $title = (string) ($pr['title'] ?? '');
        $url = (string) ($pr['html_url'] ?? '');
        if ($num <= 0 || $url === '') {
            continue;
        }
        $items[] = ['title' => $title, 'url' => $url, 'number' => $num];
    }

    $n = count($items);
    $truncated = is_array($data) && count($data) >= 30;

    return ['count' => $n, 'items' => $items, 'truncated' => $truncated];
}

/**
 * List repositories visible to the token (user + collaborator + org), up to a few pages.
 *
 * @return array{ok: bool, repos: list<array{full_name: string, private: bool, default_branch: string, html_url: string, description: string}>, message?: string}
 */
function station_github_list_user_repositories(string $token, int $maxPages = 3): array
{
    $token = trim($token);
    if ($token === '') {
        return ['ok' => false, 'repos' => [], 'message' => 'Missing token.'];
    }
    $maxPages = max(1, min(10, $maxPages));
    $all = [];
    for ($page = 1; $page <= $maxPages; $page++) {
        $path = '/user/repos?' . http_build_query([
            'per_page' => 100,
            'page' => $page,
            'sort' => 'updated',
            'affiliation' => 'owner,collaborator,organization_member',
        ]);
        $r = station_github_api('GET', $path, $token);
        if (empty($r['ok'])) {
            return [
                'ok' => false,
                'repos' => $all,
                'message' => ($r['message'] ?? 'GitHub API error') . ' ' . mb_substr((string) ($r['body'] ?? ''), 0, 200),
            ];
        }
        $batch = json_decode((string) ($r['body'] ?? ''), true);
        if (!is_array($batch) || $batch === []) {
            break;
        }
        foreach ($batch as $row) {
            if (!is_array($row)) {
                continue;
            }
            $fn = trim((string) ($row['full_name'] ?? ''));
            if ($fn === '') {
                continue;
            }
            $all[] = [
                'full_name' => $fn,
                'private' => !empty($row['private']),
                'default_branch' => (string) ($row['default_branch'] ?? 'main'),
                'html_url' => (string) ($row['html_url'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
            ];
        }
        if (count($batch) < 100) {
            break;
        }
    }

    return ['ok' => true, 'repos' => $all];
}

/**
 * @return array{ok: bool, message: string}
 */
function station_github_project_git_pull(string $slug, string $token, string $authMode = ''): array
{
    $authMode = station_github_git_auth_mode($token, $authMode);
    $path = station_project_path($slug);
    $settings = station_project_settings($slug);
    $gh = isset($settings['github']) && is_array($settings['github']) ? $settings['github'] : [];
    $branch = trim((string) ($gh['defaultBranch'] ?? 'main')) ?: 'main';
    $pull = station_run_git_command(['git', '-C', $path, 'pull', '--ff-only', 'origin', $branch], $token, $authMode);
    if (empty($pull['ok'])) {
        return ['ok' => false, 'message' => mb_substr((string) ($pull['output'] ?? 'pull failed'), 0, 800)];
    }

    return ['ok' => true, 'message' => 'Pulled latest from origin/' . $branch . '.'];
}

/**
 * @return array{ok: bool, message: string}
 */
function station_github_project_git_push(string $slug, string $token, string $authMode = ''): array
{
    $authMode = station_github_git_auth_mode($token, $authMode);
    $path = station_project_path($slug);
    $settings = station_project_settings($slug);
    $gh = isset($settings['github']) && is_array($settings['github']) ? $settings['github'] : [];
    $branch = trim((string) ($gh['defaultBranch'] ?? 'main')) ?: 'main';
    $push = station_run_git_command(['git', '-C', $path, 'push', '-u', 'origin', 'HEAD:' . $branch], $token, $authMode);
    if (empty($push['ok'])) {
        return ['ok' => false, 'message' => mb_substr((string) ($push['output'] ?? 'push failed'), 0, 800)];
    }

    return ['ok' => true, 'message' => 'Pushed to origin/' . $branch . '.'];
}

/**
 * @return array{ok: bool, message: string}
 */
function station_github_project_init_and_link(string $slug, string $token, string $authMode = ''): array
{
    $authMode = station_github_git_auth_mode($token, $authMode);
    $path = station_project_path($slug);
    $settings = station_project_settings($slug);
    $gh = isset($settings['github']) && is_array($settings['github']) ? $settings['github'] : [];
    $owner = trim((string) ($gh['repoOwner'] ?? ''));
    $name = trim((string) ($gh['repoName'] ?? ''));
    $branch = trim((string) ($gh['defaultBranch'] ?? 'main')) ?: 'main';
    if ($owner === '' || $name === '') {
        return ['ok' => false, 'message' => 'Configure repo owner and name first.'];
    }

    if (!is_dir($path)) {
        return ['ok' => false, 'message' => 'Project path missing.'];
    }

    if (!is_dir($path . '/.git')) {
        $init = station_run_git_command(['git', '-C', $path, 'init'], $token, $authMode);
        if (empty($init['ok'])) {
            return ['ok' => false, 'message' => 'git init failed: ' . ($init['output'] ?? '')];
        }
        station_run_git_command(['git', '-C', $path, 'checkout', '-B', $branch], $token, $authMode);
    }

    $remote = station_github_https_remote($owner, $name, $token, $authMode);
    $hasOrigin = station_run_git_command(['git', '-C', $path, 'remote'], $token, $authMode);
    $hasO = !empty($hasOrigin['ok']) && str_contains((string) ($hasOrigin['output'] ?? ''), 'origin');
    if ($hasO) {
        station_run_git_command(['git', '-C', $path, 'remote', 'set-url', 'origin', $remote], $token, $authMode);
    } else {
        station_run_git_command(['git', '-C', $path, 'remote', 'add', 'origin', $remote], $token, $authMode);
    }

    station_run_git_command(['git', '-C', $path, 'add', '-A'], $token, $authMode);
    $st = station_run_git_command(['git', '-C', $path, 'status', '--porcelain'], $token, $authMode);
    if (!empty($st['ok']) && trim((string) ($st['output'] ?? '')) !== '') {
        station_run_git_command(['git', '-C', $path, 'commit', '-m', 'Initial commit from Deployment Station'], $token, $authMode);
    }

    $push = station_run_git_command(['git', '-C', $path, 'push', '-u', 'origin', 'HEAD:' . $branch], $token, $authMode);
    if (empty($push['ok'])) {
        return ['ok' => false, 'message' => 'Linked remote but push failed (create the empty repo on GitHub first, or fix permissions): ' . mb_substr((string) ($push['output'] ?? ''), 0, 600)];
    }

    return ['ok' => true, 'message' => 'Initialized git, linked origin, and pushed ' . $branch . '.'];
}

/**
 * @return array{ok: bool, message: string}
 */
function station_github_project_redeploy_after_pull(string $slug): array
{
    if (!station_docker_enabled()) {
        return ['ok' => true, 'message' => 'Docker disabled — skipped container refresh.'];
    }
    $ps = station_project_settings($slug);
    $docker = isset($ps['docker']) && is_array($ps['docker']) ? $ps['docker'] : [];
    if (empty($docker['containerized'])) {
        return ['ok' => true, 'message' => 'Project is not containerized — skipped.'];
    }
    $pull = station_run_project_docker_compose($slug, ['pull'], 600);
    if (empty($pull['ok'])) {
        return ['ok' => false, 'message' => 'docker compose pull: ' . mb_substr((string) ($pull['output'] ?? ''), 0, 500)];
    }
    $up = station_run_project_docker_compose($slug, ['up', '-d'], 600);
    if (empty($up['ok'])) {
        return ['ok' => false, 'message' => 'docker compose up: ' . mb_substr((string) ($up['output'] ?? ''), 0, 500)];
    }

    return ['ok' => true, 'message' => 'Containers updated (pull + up -d).'];
}

function station_github_user_may_sync_project(array $user, string $slug): bool
{
    if (!station_user_may_access_project($user, $slug)) {
        return false;
    }
    if (station_is_owner($user) || station_is_admin($user)) {
        return true;
    }
    $who = station_safe_name((string) ($user['username'] ?? ''));
    foreach (station_list_projects() as $p) {
        if ((string) ($p['slug'] ?? '') !== $slug) {
            continue;
        }

        return $who === station_safe_name((string) ($p['owner'] ?? ''));
    }

    return false;
}
