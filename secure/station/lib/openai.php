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
