<?php

declare(strict_types=1);

/**
 * Resolve OpenAI API key: per-user override, then station-wide admin key.
 */
function station_openai_resolve_api_key(?string $username = null): string
{
    $username = $username ?? station_current_username();
    if ($username !== '') {
        $profile = station_user_profile($username);
        $userKey = trim((string) ($profile['integrations']['openai']['apiKey'] ?? ''));
        if ($userKey !== '') {
            return $userKey;
        }
    }
    $admin = station_admin_settings();

    return trim((string) ($admin['openaiApiKey'] ?? ''));
}

function station_openai_configured(?string $username = null): bool
{
    return station_openai_resolve_api_key($username) !== '';
}

/**
 * Which key App explorer / chat will use: user override, station admin default, or none.
 *
 * @return 'user'|'global'|'none'
 */
function station_openai_key_source(?string $username = null): string
{
    $username = $username ?? station_current_username();
    if ($username !== '') {
        $profile = station_user_profile($username);
        $userKey = trim((string) ($profile['integrations']['openai']['apiKey'] ?? ''));
        if ($userKey !== '') {
            return 'user';
        }
    }
    $admin = station_admin_settings();
    if (trim((string) ($admin['openaiApiKey'] ?? '')) !== '') {
        return 'global';
    }

    return 'none';
}

function station_openai_status_label(?string $username = null): string
{
    return match (station_openai_key_source($username)) {
        'user' => 'Your personal key (active)',
        'global' => 'Station global key (active)',
        default => 'No API key configured',
    };
}

/**
 * User settings / dashboard copy for OpenAI key state.
 *
 * @return array{
 *   active: string,
 *   activeLabel: string,
 *   userKeySaved: bool,
 *   globalConfigured: bool,
 *   configured: bool
 * }
 */
function station_openai_user_key_status(?string $username = null): array
{
    $username = $username ?? station_current_username();
    $profile = $username !== '' ? station_user_profile($username) : [];
    $oai = isset($profile['integrations']['openai']) && is_array($profile['integrations']['openai'])
        ? $profile['integrations']['openai']
        : [];
    $userKeySaved = trim((string) ($oai['apiKey'] ?? '')) !== '';
    $globalConfigured = trim((string) (station_admin_settings()['openaiApiKey'] ?? '')) !== '';
    $active = station_openai_key_source($username);

    return [
        'active' => $active,
        'activeLabel' => station_openai_status_label($username),
        'userKeySaved' => $userKeySaved,
        'globalConfigured' => $globalConfigured,
        'configured' => $active !== 'none',
    ];
}

/**
 * @param list<array{role: string, content: string}> $messages
 * @return array{ok: bool, text: string, message: string}
 */
function station_openai_chat(array $messages, ?string $username = null, int $maxTokens = 800): array
{
    $key = station_openai_resolve_api_key($username);
    if ($key === '') {
        return ['ok' => false, 'text' => '', 'message' => 'No OpenAI API key configured. Add one under User Settings or Admin → Integrations.'];
    }

    $admin = station_admin_settings();
    $model = trim((string) ($admin['openaiModel'] ?? ''));
    if ($model === '') {
        $model = 'gpt-4o-mini';
    }

    $payload = json_encode([
        'model' => $model,
        'max_tokens' => max(64, min(4096, $maxTokens)),
        'messages' => $messages,
    ], JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return ['ok' => false, 'text' => '', 'message' => 'Could not encode OpenAI request.'];
    }

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    if ($ch === false) {
        return ['ok' => false, 'text' => '', 'message' => 'curl_init failed.'];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === '' && $err !== '') {
        return ['ok' => false, 'text' => '', 'message' => 'OpenAI request failed: ' . $err];
    }

    $data = json_decode($body, true);
    if ($code < 200 || $code >= 300 || !is_array($data)) {
        $msg = is_array($data) && isset($data['error']['message'])
            ? (string) $data['error']['message']
            : mb_substr($body, 0, 300);

        return ['ok' => false, 'text' => '', 'message' => 'OpenAI HTTP ' . $code . ': ' . $msg];
    }

    $text = (string) ($data['choices'][0]['message']['content'] ?? '');

    return ['ok' => true, 'text' => trim($text), 'message' => ''];
}

/**
 * Build a short app explorer blurb for the launch splash (uses OpenAI when configured).
 *
 * @return array{ok: bool, description: string, steps: list<string>, source: string, message: string}
 */
function station_openai_project_explorer_copy(string $projectSlug, array $launchProfile): array
{
    $slug = station_safe_name($projectSlug);
    $path = station_project_path($slug);
    $contextLines = [
        'Project slug: ' . $slug,
        'Launch kind: ' . ($launchProfile['launchKind'] ?? 'unknown'),
        'Stack: ' . ($launchProfile['stack'] ?? 'other'),
        'Summary: ' . ($launchProfile['summary'] ?? ''),
    ];

    foreach (['README.md', 'package.json', 'composer.json'] as $f) {
        $p = $path . '/' . $f;
        if (is_file($p)) {
            $snippet = mb_substr((string) @file_get_contents($p), 0, 1200);
            $contextLines[] = '--- ' . $f . " ---\n" . $snippet;
        }
    }

    $settings = station_project_settings($slug);
    $gh = $settings['github'] ?? [];
    if (!empty($gh['repoOwner']) && !empty($gh['repoName'])) {
        $contextLines[] = 'GitHub: ' . $gh['repoOwner'] . '/' . $gh['repoName'];
    }

    $docker = $settings['docker'] ?? [];
    if (!empty($docker['containerized'])) {
        $contextLines[] = 'Docker: containerized, host port ' . (int) ($docker['hostPort'] ?? 0);
    }

    if (!station_openai_configured()) {
        return [
            'ok' => true,
            'description' => (string) ($launchProfile['summary'] ?? ''),
            'steps' => station_project_explorer_default_steps($launchProfile),
            'source' => 'local',
            'message' => '',
        ];
    }

    $system = 'You help operators understand deployment projects on a self-hosted "Deployment Station". '
        . 'Reply with JSON only: {"description":"2-3 sentences plain English","steps":["step 1","step 2",...]} '
        . 'Max 4 steps. No markdown fences.';
    $user = "Describe this project for a launch splash screen and how to run it:\n\n" . implode("\n", $contextLines);

    $r = station_openai_chat([
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $user],
    ]);

    if (empty($r['ok'])) {
        return [
            'ok' => true,
            'description' => (string) ($launchProfile['summary'] ?? ''),
            'steps' => station_project_explorer_default_steps($launchProfile),
            'source' => 'local',
            'message' => (string) ($r['message'] ?? ''),
        ];
    }

    $parsed = json_decode((string) $r['text'], true);
    if (is_array($parsed)) {
        $steps = isset($parsed['steps']) && is_array($parsed['steps'])
            ? array_values(array_filter(array_map('strval', $parsed['steps'])))
            : station_project_explorer_default_steps($launchProfile);

        return [
            'ok' => true,
            'description' => trim((string) ($parsed['description'] ?? $launchProfile['summary'] ?? '')),
            'steps' => $steps,
            'source' => 'openai',
            'message' => '',
        ];
    }

    return [
        'ok' => true,
        'description' => trim((string) $r['text']),
        'steps' => station_project_explorer_default_steps($launchProfile),
        'source' => 'openai',
        'message' => '',
    ];
}

/**
 * @return list<string>
 */
function station_project_explorer_default_steps(array $launchProfile): array
{
    $kind = (string) ($launchProfile['launchKind'] ?? 'unknown');
    if ($kind === 'chrome-extension') {
        return [
            'Download the project zip from this page.',
            'Open chrome://extensions and enable Developer mode.',
            'Click Load unpacked and select the extracted folder.',
        ];
    }
    if ($kind === 'web') {
        return [
            'Open Manage → Docker and enable containers if you need an isolated stack.',
            'Start containers from the dashboard, or rely on PHP/nginx static routing.',
            'Use Launch when status shows running (green indicator on the dashboard card).',
        ];
    }

    return [
        'Browse project files to confirm an entrypoint (index.html, package.json start script, etc.).',
        'Configure GitHub under Manage if you want push/pull sync.',
        'Enable Docker under Manage → Docker when the app should run in containers.',
    ];
}

/**
 * Structured workspace context for App Explorer chat and diagnostics.
 *
 * @return array<string, mixed>
 */
function station_project_explorer_context(string $projectSlug, ?array $launchProfile = null): array
{
    if (!function_exists('station_project_launch_config')) {
        require_once __DIR__ . '/project-launch.php';
    }
    if (!function_exists('station_project_public_web_path')) {
        require_once __DIR__ . '/projects.php';
    }

    $slug = station_safe_name($projectSlug);
    $path = station_project_path($slug);
    $launchProfile = $launchProfile ?? station_project_launch_profile($slug);
    $settings = station_project_settings($slug);
    $docker = isset($settings['docker']) && is_array($settings['docker']) ? $settings['docker'] : [];
    $gh = isset($settings['github']) && is_array($settings['github']) ? $settings['github'] : [];

    $fileIndex = [];
    if (is_dir($path)) {
        $entries = @scandir($path) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            $fileIndex[] = [
                'name' => $entry,
                'type' => is_dir($full) ? 'dir' : 'file',
            ];
            if (count($fileIndex) >= 40) {
                $fileIndex[] = ['name' => '…', 'type' => 'truncated'];
                break;
            }
        }
    }

    $snippets = [];
    foreach (['README.md', 'package.json', 'composer.json', 'docker-compose.yml', 'Dockerfile', '.env.example'] as $fname) {
        $fp = $path . '/' . $fname;
        if (is_file($fp)) {
            $snippets[$fname] = mb_substr((string) @file_get_contents($fp), 0, 2500);
        }
    }

    $envKeys = [];
    foreach ($settings['environment'] ?? [] as $k => $_v) {
        $envKeys[] = (string) $k;
    }

    $dockerRuntime = null;
    if (station_docker_enabled() && !empty($docker['containerized'])) {
        if (!function_exists('station_project_docker_status')) {
            require_once __DIR__ . '/docker.php';
        }
        $st = station_project_docker_status($slug);
        $dockerRuntime = [
            'state' => (string) ($st['state'] ?? 'unknown'),
            'serviceCount' => count($st['services'] ?? []),
            'outputPreview' => mb_substr(trim((string) ($st['output'] ?? '')), 0, 500),
        ];
    }

    $ghOwner = trim((string) ($gh['repoOwner'] ?? ''));
    $ghName = trim((string) ($gh['repoName'] ?? ''));
    $github = [
        'configured' => $ghOwner !== '' && $ghName !== '',
        'owner' => $ghOwner,
        'name' => $ghName,
        'branch' => trim((string) ($gh['defaultBranch'] ?? 'main')) ?: 'main',
        'visibility' => (string) ($gh['visibility'] ?? 'private'),
    ];
    if ($github['configured']) {
        $github['htmlUrl'] = 'https://github.com/' . rawurlencode($ghOwner) . '/' . rawurlencode($ghName);
        if ($github['visibility'] === 'public') {
            $github['publicCloneUrl'] = $github['htmlUrl'];
        }
    }

    $hasGit = is_dir($path . '/.git');

    return [
        'slug' => $slug,
        'launchProfile' => $launchProfile,
        'stack' => station_infer_docker_stack($slug),
        'notes' => trim((string) ($settings['notes'] ?? '')),
        'environmentVariableKeys' => $envKeys,
        'fileIndex' => $fileIndex,
        'snippets' => $snippets,
        'github' => $github,
        'hasLocalGit' => $hasGit,
        'docker' => [
            'enabled' => station_docker_enabled(),
            'containerized' => !empty($docker['containerized']),
            'hostPort' => (int) ($docker['hostPort'] ?? 0),
            'appPort' => (int) ($docker['appPort'] ?? 0),
            'services' => array_keys(array_filter((array) ($docker['services'] ?? []))),
        ],
        'dockerRuntime' => $dockerRuntime,
        'webEntry' => station_project_launch_config($settings),
        'station' => [
            'publicPath' => '/p/' . $slug . '/',
            'publicWebPath' => function_exists('station_project_public_web_path')
                ? station_project_public_web_path($slug)
                : '/p/' . $slug . '/',
            'manageUrl' => 'project-settings.php?project=' . rawurlencode($slug),
            'filesUrl' => 'viewer.php?project=' . rawurlencode($slug),
        ],
    ];
}

/**
 * @param array<string, mixed> $context
 */
function station_openai_explorer_chat_reply(
    string $projectSlug,
    string $userMessage,
    array $context,
    string $extraContext = '',
    ?string $username = null
): array {
    $userMessage = trim($userMessage);
    if ($userMessage === '') {
        return ['ok' => false, 'text' => '', 'message' => 'Message is empty.'];
    }

    if (!station_openai_configured($username)) {
        return [
            'ok' => false,
            'text' => '',
            'message' => 'No OpenAI API key. Add the station default under Admin → Integrations (or your own under User Settings).',
        ];
    }

    $contextJson = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($contextJson)) {
        $contextJson = '{}';
    }
    if (mb_strlen($contextJson) > 12000) {
        $contextJson = mb_substr($contextJson, 0, 12000) . "\n…(truncated)";
    }

    $system = 'You are DeployStation\'s deployment assistant on a self-hosted server. '
        . 'Help the operator understand, configure, and run the project (Docker, nginx /p/ routes, GitHub sync). '
        . 'Be specific to the JSON context. Use short paragraphs and bullet steps when helpful. '
        . 'If something is missing from context, say what to check in Manage or Files.';

    $userBlock = "Project context (JSON):\n" . $contextJson . "\n\nOperator question:\n" . $userMessage;
    if (trim($extraContext) !== '') {
        $userBlock .= "\n\nAdditional context from operator:\n" . mb_substr(trim($extraContext), 0, 4000);
    }

    return station_openai_chat([
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $userBlock],
    ], $username, 1200);
}
