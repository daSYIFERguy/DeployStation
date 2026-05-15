<?php

declare(strict_types=1);

require_once __DIR__ . '/openai.php';
require_once __DIR__ . '/docker.php';
require_once __DIR__ . '/station-assist-actions.php';

/**
 * Resolve project slug from query string or common DeployStation URLs.
 */
function station_assist_detect_project_slug(string $pageUrl = ''): string
{
    $fromQuery = station_safe_name((string) ($_GET['project'] ?? $_POST['project'] ?? ''));
    if ($fromQuery !== '' && station_project_exists($fromQuery)) {
        return $fromQuery;
    }

    $pageUrl = trim($pageUrl);
    if ($pageUrl === '') {
        return '';
    }

    $path = (string) (parse_url($pageUrl, PHP_URL_PATH) ?? '');
    if (preg_match('~[?&]project=([a-z0-9_-]+)~i', $pageUrl, $m)) {
        $slug = station_safe_name($m[1]);
        if ($slug !== '' && station_project_exists($slug)) {
            return $slug;
        }
    }
    if (preg_match('~/p/([a-z0-9_-]+)(/|$)~i', $path, $m)) {
        $slug = station_safe_name($m[1]);
        if ($slug !== '' && station_project_exists($slug)) {
            return $slug;
        }
    }
    if (preg_match('~/(?:launch|viewer|project-settings|docker-config)\.php~i', $path)
        && preg_match('~[?&]project=([a-z0-9_-]+)~i', $pageUrl, $m)) {
        $slug = station_safe_name($m[1]);
        if ($slug !== '' && station_project_exists($slug)) {
            return $slug;
        }
    }

    return '';
}

/**
 * @return array<string, mixed>
 */
function station_assist_page_context(string $pageUrl, string $pageTitle, string $projectSlug = ''): array
{
    $user = station_current_user();
    $username = station_current_username();
    $role = $user ? (string) ($user['role'] ?? 'user') : 'guest';
    $ui = station_ui_config();
    $slug = $projectSlug !== '' ? station_safe_name($projectSlug) : station_assist_detect_project_slug($pageUrl);

    $templateHints = [];
    if ($user && station_can_build($user)) {
        foreach (station_template_catalog() as $key => $label) {
            $templateHints[] = $key . ' (' . $label . ')';
        }
    }

    $ctx = [
        'station' => [
            'product' => 'DeployStation (Deployment Station)',
            'appName' => trim((string) ($ui['appName'] ?? 'Deployment Station')),
            'description' => 'Self-hosted control plane for PHP/nginx projects under /p/{slug}/, Docker stacks, GitHub sync, templates, and role-based access.',
            'operator' => [
                'username' => $username,
                'role' => $role,
                'canBuild' => station_can_build($user),
                'canUseAssistTools' => station_can_build($user),
            ],
            'urls' => [
                'dashboard' => station_station_url('station.php'),
                'userSettings' => station_station_url('user-settings.php'),
                'adminIntegrations' => station_station_url('admin-settings.php?tab=github'),
                'githubSync' => station_station_url('github-sync.php'),
            ],
            'features' => [
                'dockerEnabled' => station_docker_enabled(),
                'githubEnabled' => !empty(station_admin_settings()['githubEnabled']),
                'openai' => station_openai_user_key_status($username),
            ],
            'templates' => $templateHints,
        ],
        'page' => [
            'title' => mb_substr(trim($pageTitle), 0, 200),
            'url' => mb_substr(trim($pageUrl), 0, 500),
            'path' => (string) (parse_url($pageUrl, PHP_URL_PATH) ?? ''),
        ],
        'projectSlug' => $slug,
    ];

    if ($slug !== '' && station_project_exists($slug)) {
        if (!function_exists('station_project_launch_profile')) {
            require_once __DIR__ . '/project-launch.php';
        }
        $profile = station_project_launch_profile($slug);
        $ctx['project'] = [
            'slug' => $slug,
            'launchKind' => (string) ($profile['launchKind'] ?? ''),
            'summary' => (string) ($profile['summary'] ?? ''),
            'launchable' => !empty($profile['launchable']),
            'manageUrl' => station_station_url('project-settings.php?project=' . rawurlencode($slug)),
            'filesUrl' => station_station_url('viewer.php?project=' . rawurlencode($slug)),
            'launchUrl' => station_station_url('launch.php?project=' . rawurlencode($slug)),
            'publicPath' => '/p/' . $slug . '/',
        ];
    }

    return $ctx;
}

/**
 * @param array<string, mixed> $pageContext
 * @param array<string, mixed>|null $projectContext
 * @param list<array{role: string, content: string}> $conversationHistory
 * @return array{ok: bool, text: string, message: string, clientActions?: list<array<string, mixed>>}
 */
function station_assist_chat_reply(
    string $message,
    array $pageContext,
    ?array $projectContext,
    string $extraContext = '',
    bool $includeProjectContext = true,
    array $conversationHistory = []
): array {
    $message = trim($message);
    if ($message === '') {
        return ['ok' => false, 'text' => '', 'message' => 'Message is empty.'];
    }

    if (!station_openai_configured()) {
        return [
            'ok' => false,
            'text' => '',
            'message' => 'No OpenAI API key. Add the station default under Admin → Integrations or your own under User Settings.',
        ];
    }

    $user = station_current_user();
    $tools = station_assist_openai_tools($user);
    $clientActions = [];

    $blocks = [
        'You are DeployStation AI Assist — an expert on this self-hosted Deployment Station.',
        'You help the signed-in operator use the product: projects, /p/{slug}/ public URLs, nginx routing, Docker, GitHub sync, templates, users/roles, and workspace files.',
        'When the operator wants to build something new, ask for a project name if they have not given one, pick a sensible template_type, then call create_project (private GitHub repo + git push when GitHub is connected).',
        'Use navigate to send them to the right page after actions (viewer, project settings, github-sync, user settings).',
        'Use list_templates when you need to see available starter keys.',
        'Never invent secrets or tokens. If GitHub is not connected, explain they must connect under User Settings before create_project can push to GitHub.',
        'Answer concisely; use bullets for steps.',
    ];

    if ($tools === []) {
        $blocks[] = 'This operator cannot use station tools (viewer/builder access only). Give UI directions only.';
    }

    $pageJson = json_encode($pageContext, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($pageJson)) {
        $pageJson = '{}';
    }
    if (mb_strlen($pageJson) > 8000) {
        $pageJson = mb_substr($pageJson, 0, 8000) . "\n…(truncated)";
    }

    $userBlock = "DeployStation page context (JSON):\n" . $pageJson;

    if ($includeProjectContext && is_array($projectContext) && $projectContext !== []) {
        $projJson = json_encode($projectContext, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($projJson)) {
            if (mb_strlen($projJson) > 12000) {
                $projJson = mb_substr($projJson, 0, 12000) . "\n…(truncated)";
            }
            $userBlock .= "\n\nProject workspace context (JSON):\n" . $projJson;
        }
    }

    $userBlock .= "\n\nOperator message:\n" . $message;
    if (trim($extraContext) !== '') {
        $userBlock .= "\n\nAdditional notes:\n" . mb_substr(trim($extraContext), 0, 4000);
    }

    $messages = [
        ['role' => 'system', 'content' => implode("\n", $blocks)],
    ];

    foreach ($conversationHistory as $turn) {
        if (!is_array($turn)) {
            continue;
        }
        $role = (string) ($turn['role'] ?? '');
        $content = trim((string) ($turn['content'] ?? ''));
        if ($content === '' || !in_array($role, ['user', 'assistant'], true)) {
            continue;
        }
        $messages[] = ['role' => $role, 'content' => $content];
    }

    $messages[] = ['role' => 'user', 'content' => $userBlock];

    $maxRounds = $tools !== [] ? 8 : 1;
    for ($round = 0; $round < $maxRounds; $round++) {
        $completion = station_openai_chat_with_tools($messages, $tools, null, 1800);
        if (empty($completion['ok'])) {
            return [
                'ok' => false,
                'text' => '',
                'message' => (string) ($completion['message'] ?? 'OpenAI request failed.'),
                'clientActions' => $clientActions,
            ];
        }

        $toolCalls = $completion['tool_calls'] ?? [];
        $assistantMessage = $completion['assistantMessage'] ?? null;

        if ($toolCalls === [] || !is_array($toolCalls)) {
            return [
                'ok' => true,
                'text' => (string) ($completion['text'] ?? ''),
                'message' => 'OK',
                'clientActions' => $clientActions,
            ];
        }

        if (is_array($assistantMessage) && $assistantMessage !== []) {
            $messages[] = $assistantMessage;
        } else {
            $messages[] = [
                'role' => 'assistant',
                'content' => (string) ($completion['text'] ?? ''),
                'tool_calls' => $toolCalls,
            ];
        }

        foreach ($toolCalls as $toolCall) {
            if (!is_array($toolCall)) {
                continue;
            }
            $fn = is_array($toolCall['function'] ?? null) ? $toolCall['function'] : [];
            $toolName = (string) ($fn['name'] ?? '');
            $argsRaw = (string) ($fn['arguments'] ?? '{}');
            $args = json_decode($argsRaw, true);
            if (!is_array($args)) {
                $args = [];
            }

            $executed = station_assist_execute_tool($user, $toolName, $args);
            if (!empty($executed['clientActions']) && is_array($executed['clientActions'])) {
                foreach ($executed['clientActions'] as $action) {
                    if (is_array($action)) {
                        $clientActions[] = $action;
                    }
                }
            }

            $toolPayload = json_encode(
                $executed['result'] ?? ['ok' => !empty($executed['ok']), 'message' => $executed['message'] ?? ''],
                JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if (!is_string($toolPayload)) {
                $toolPayload = '{"ok":false}';
            }

            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => (string) ($toolCall['id'] ?? ''),
                'content' => $toolPayload,
            ];
        }
    }

    return [
        'ok' => true,
        'text' => 'I completed the requested actions. Check the station UI for results.',
        'message' => 'OK',
        'clientActions' => $clientActions,
    ];
}
