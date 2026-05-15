<?php

declare(strict_types=1);

require_once __DIR__ . '/openai.php';
require_once __DIR__ . '/docker.php';

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

    $ctx = [
        'station' => [
            'product' => 'DeployStation (Deployment Station)',
            'appName' => trim((string) ($ui['appName'] ?? 'Deployment Station')),
            'description' => 'Self-hosted control plane for PHP/nginx projects under /p/{slug}/, Docker stacks, GitHub sync, templates, and role-based access.',
            'operator' => [
                'username' => $username,
                'role' => $role,
                'canBuild' => station_can_build($user),
            ],
            'urls' => [
                'dashboard' => station_station_url('station.php'),
                'userSettings' => station_station_url('user-settings.php'),
                'adminIntegrations' => station_station_url('admin-settings.php?tab=github'),
            ],
            'features' => [
                'dockerEnabled' => station_docker_enabled(),
                'githubEnabled' => !empty(station_admin_settings()['githubEnabled']),
                'openai' => station_openai_user_key_status($username),
            ],
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
 */
function station_assist_chat_reply(
    string $message,
    array $pageContext,
    ?array $projectContext,
    string $extraContext = '',
    bool $includeProjectContext = true
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

    $blocks = [
        'You are DeployStation AI Assist — an expert on this self-hosted Deployment Station.',
        'You help the signed-in operator use the product: projects, /p/{slug}/ public URLs, nginx routing, Docker under Manage → Docker, GitHub sync, templates, users/roles, clipboard, and workspace files.',
        'Answer for the current page and project when context is provided. Be concise; use bullets for steps.',
        'If you lack data, say what to open in the UI (Manage, Files, Admin → Integrations) rather than guessing secrets.',
    ];

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

    $userBlock .= "\n\nOperator question:\n" . $message;
    if (trim($extraContext) !== '') {
        $userBlock .= "\n\nAdditional notes:\n" . mb_substr(trim($extraContext), 0, 4000);
    }

    return station_openai_chat([
        ['role' => 'system', 'content' => implode(' ', $blocks)],
        ['role' => 'user', 'content' => $userBlock],
    ], null, 1400);
}
