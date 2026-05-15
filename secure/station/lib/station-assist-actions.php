<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/projects.php';
require_once __DIR__ . '/templates.php';
require_once __DIR__ . '/docker.php';
require_once __DIR__ . '/github-auth.php';
require_once __DIR__ . '/github-sync.php';
require_once __DIR__ . '/git.php';

/**
 * OpenAI tool definitions available to AI Assist (builder+ only).
 *
 * @return list<array<string, mixed>>
 */
function station_assist_openai_tools(?array $user): array
{
    if (!$user || !station_can_build($user)) {
        return [];
    }

    $tools = [
        [
            'type' => 'function',
            'function' => [
                'name' => 'navigate',
                'description' => 'Send the operator to a DeployStation page (dashboard, project settings, files, GitHub sync, user settings, etc.). Use a path like station.php or project-settings.php?project=slug.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => [
                            'type' => 'string',
                            'description' => 'Relative URL under the station app, e.g. station.php, github-sync.php, viewer.php?project=my-app',
                        ],
                    ],
                    'required' => ['path'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'list_templates',
                'description' => 'List starter templates the operator can use when creating a project (keys, labels, descriptions).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'create_project',
                'description' => 'Create a new DeployStation project from a starter template, register it on the station, and optionally create a private GitHub repo, init git, and push. Requires a human-readable project name. Ask for the name first if the operator did not provide one.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'project_name' => [
                            'type' => 'string',
                            'description' => 'Display name for the project (used to derive the slug folder name).',
                        ],
                        'template_type' => [
                            'type' => 'string',
                            'description' => 'Template key, e.g. static-html, node-express, react-vite, next-app, php-nginx, python-flask, pwa. Call list_templates if unsure.',
                        ],
                        'github_repo_name' => [
                            'type' => 'string',
                            'description' => 'Optional GitHub repository name (defaults to project slug). Letters, numbers, dots, dashes, underscores.',
                        ],
                        'github_owner' => [
                            'type' => 'string',
                            'description' => 'Optional GitHub owner (user or org). Defaults to the connected GitHub account.',
                        ],
                        'push_to_github' => [
                            'type' => 'boolean',
                            'description' => 'When true (default), create a private GitHub repo and push the template if GitHub is connected.',
                        ],
                        'project_description' => [
                            'type' => 'string',
                            'description' => 'Short description used for the GitHub repository.',
                        ],
                    ],
                    'required' => ['project_name'],
                ],
            ],
        ],
    ];

    return $tools;
}

/**
 * @return array{ok: bool, result: array<string, mixed>, clientActions: list<array<string, mixed>>, message?: string}
 */
function station_assist_execute_tool(?array $user, string $toolName, array $arguments): array
{
    $toolName = trim($toolName);
    $arguments = is_array($arguments) ? $arguments : [];

    if (!$user || !station_can_build($user)) {
        return [
            'ok' => false,
            'result' => ['error' => 'Builder access required.'],
            'clientActions' => [],
            'message' => 'Forbidden',
        ];
    }

    return match ($toolName) {
        'navigate' => station_assist_tool_navigate($arguments),
        'list_templates' => station_assist_tool_list_templates(),
        'create_project' => station_assist_tool_create_project($user, $arguments),
        default => [
            'ok' => false,
            'result' => ['error' => 'Unknown tool: ' . $toolName],
            'clientActions' => [],
        ],
    };
}

/**
 * @return array{ok: bool, result: array<string, mixed>, clientActions: list<array<string, mixed>>}
 */
function station_assist_tool_navigate(array $arguments): array
{
    $path = trim((string) ($arguments['path'] ?? ''));
    if ($path === '') {
        return [
            'ok' => false,
            'result' => ['error' => 'path is required'],
            'clientActions' => [],
        ];
    }

    if (preg_match('~^https?://~i', $path)) {
        $parsed = parse_url($path);
        $path = (string) ($parsed['path'] ?? '') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
    }

    $path = ltrim($path, '/');
    if (str_contains($path, '..') || str_contains($path, "\0")) {
        return [
            'ok' => false,
            'result' => ['error' => 'Invalid path'],
            'clientActions' => [],
        ];
    }

    $url = station_station_url($path);
    if ($url === '') {
        return [
            'ok' => false,
            'result' => ['error' => 'Could not build navigation URL'],
            'clientActions' => [],
        ];
    }

    return [
        'ok' => true,
        'result' => ['navigated' => true, 'url' => $url],
        'clientActions' => [
            ['type' => 'navigate', 'url' => $url],
        ],
    ];
}

/**
 * @return array{ok: bool, result: array<string, mixed>, clientActions: list<array<string, mixed>>}
 */
function station_assist_tool_list_templates(): array
{
    $items = [];
    foreach (station_template_definitions() as $key => $def) {
        $items[] = [
            'key' => $key,
            'label' => (string) ($def['label'] ?? $key),
            'description' => (string) ($def['description'] ?? ''),
            'stack' => (string) ($def['stack'] ?? ''),
        ];
    }

    return [
        'ok' => true,
        'result' => ['templates' => $items],
        'clientActions' => [],
    ];
}

/**
 * @return array{ok: bool, result: array<string, mixed>, clientActions: list<array<string, mixed>>}
 */
function station_assist_tool_create_project(array $user, array $arguments): array
{
    $username = (string) ($user['username'] ?? station_current_username());
    $projectName = trim((string) ($arguments['project_name'] ?? ''));
    if ($projectName === '') {
        return [
            'ok' => false,
            'result' => [
                'error' => 'project_name is required',
                'hint' => 'Ask the operator what they want to name the project before calling create_project again.',
            ],
            'clientActions' => [],
        ];
    }

    $templateType = trim((string) ($arguments['template_type'] ?? ''));
    if ($templateType === '') {
        $templateType = station_assist_guess_template_type($arguments, $projectName);
    }

    $pushToGithub = !array_key_exists('push_to_github', $arguments) || !empty($arguments['push_to_github']);
    $repoName = trim((string) ($arguments['github_repo_name'] ?? ''));
    $repoOwner = trim((string) ($arguments['github_owner'] ?? ''));
    $description = trim((string) ($arguments['project_description'] ?? ''));
    if ($description === '') {
        $description = 'Created by DeployStation AI Assist: ' . $projectName;
    }

    $created = station_assist_create_starter_project(
        $username,
        $projectName,
        $templateType,
        $pushToGithub,
        $repoOwner,
        $repoName,
        $description
    );

    if (empty($created['ok'])) {
        return [
            'ok' => false,
            'result' => $created,
            'clientActions' => [],
        ];
    }

    $clientActions = [];
    if (!empty($created['viewerUrl'])) {
        $clientActions[] = ['type' => 'navigate', 'url' => (string) $created['viewerUrl']];
    }

    return [
        'ok' => true,
        'result' => $created,
        'clientActions' => $clientActions,
    ];
}

function station_assist_remove_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($it as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

function station_assist_guess_template_type(array $arguments, string $projectName): string
{
    $hint = strtolower(trim((string) ($arguments['project_description'] ?? '') . ' ' . $projectName));
    if (preg_match('/\b(react|jsx|tsx|vite)\b/', $hint)) {
        return 'react-vite';
    }
    if (preg_match('/\b(next\.?js|nextjs)\b/', $hint)) {
        return 'next-app';
    }
    if (preg_match('/\b(express|node|api server)\b/', $hint)) {
        return 'node-express';
    }
    if (preg_match('/\b(flask)\b/', $hint)) {
        return 'python-flask';
    }
    if (preg_match('/\b(fastapi)\b/', $hint)) {
        return 'python-fastapi';
    }
    if (preg_match('/\b(php|laravel|wordpress)\b/', $hint)) {
        return 'php-nginx';
    }
    if (preg_match('/\b(pwa|progressive)\b/', $hint)) {
        return 'pwa';
    }

    return 'static-html';
}

/**
 * @return array<string, mixed>
 */
function station_assist_create_starter_project(
    string $username,
    string $projectName,
    string $templateType,
    bool $pushToGithub,
    string $repoOwner,
    string $repoName,
    string $description
): array {
    $actor = station_current_user();
    if ($username !== station_current_username() || !station_can_build($actor)) {
        return ['ok' => false, 'error' => 'Builder access required.'];
    }

    if (station_template_definition($templateType) === null) {
        return ['ok' => false, 'error' => 'Unknown template: ' . $templateType];
    }

    $slug = station_unique_slug($projectName);
    $projectPath = station_project_path($slug);
    if (is_dir($projectPath)) {
        return ['ok' => false, 'error' => 'Project folder already exists: ' . $slug];
    }
    if (!mkdir($projectPath, 0755, true) && !is_dir($projectPath)) {
        return ['ok' => false, 'error' => 'Could not create project folder.'];
    }

    $deploy = station_deploy_template_to_project_path($projectPath, $templateType, $projectName);
    if (empty($deploy['ok'])) {
        station_assist_remove_dir($projectPath);

        return ['ok' => false, 'error' => (string) ($deploy['message'] ?? 'Template deploy failed')];
    }

    $templateBootstrap = isset($deploy['template']) && is_array($deploy['template']) ? $deploy['template'] : null;

    station_write_project_access_router($slug, $projectPath);

    $adminSettings = station_admin_settings();
    $defaultVisibility = (string) ($adminSettings['defaultProjectVisibility'] ?? 'private');
    $defaultAccessMode = (string) ($adminSettings['defaultProjectAccessMode'] ?? 'admin');

    $project = [
        'slug' => $slug,
        'owner' => $username,
        'sourceType' => 'create_template',
        'templateType' => $templateType,
        'visibility' => !empty($adminSettings['allowPublicProjects']) ? $defaultVisibility : 'private',
        'accessMode' => !empty($adminSettings['allowPublicProjects']) ? $defaultAccessMode : 'admin',
        'createdAt' => gmdate('c'),
        'updatedAt' => gmdate('c'),
    ];
    station_upsert_project_meta($project);

    if (is_array($templateBootstrap)) {
        if (empty($templateBootstrap['hasDockerfile'])) {
            station_ensure_project_dockerfile($slug);
        }

        $projectSettings = station_project_settings($slug);
        $existingDocker = isset($projectSettings['docker']) && is_array($projectSettings['docker'])
            ? $projectSettings['docker']
            : [];
        $appPort = (int) ($templateBootstrap['appPort'] ?? 80);
        if ($appPort < 1 || $appPort > 65535) {
            $appPort = 80;
        }
        $recommended = isset($templateBootstrap['recommendedServices']) && is_array($templateBootstrap['recommendedServices'])
            ? array_values($templateBootstrap['recommendedServices'])
            : [];
        $servicesEnabled = isset($existingDocker['services']) && is_array($existingDocker['services'])
            ? $existingDocker['services']
            : [];
        foreach ($recommended as $svc) {
            if (is_string($svc) && $svc !== '' && !isset($servicesEnabled[$svc])) {
                $servicesEnabled[$svc] = true;
            }
        }

        $projectSettings['docker'] = array_merge($existingDocker, [
            'containerized' => true,
            'appPort' => $appPort,
            'stack' => (string) ($templateBootstrap['stack'] ?? 'other'),
            'recommendedServices' => $recommended,
            'services' => $servicesEnabled,
        ]);
        station_save_project_settings($slug, $projectSettings);
    } else {
        station_try_bootstrap_native_docker_from_workspace($slug);
    }

    $githubResult = ['skipped' => true, 'reason' => 'push_to_github false'];
    if ($pushToGithub) {
        $githubResult = station_assist_link_new_project_github(
            $username,
            $slug,
            $repoOwner,
            $repoName,
            $description
        );
    }

    if (station_normalize_server_infrastructure((string) ($adminSettings['serverInfrastructure'] ?? 'apache')) === 'nginx') {
        station_write_nginx_projects_conf();
    }

    station_log_event('project.assist.created', [
        'slug' => $slug,
        'templateType' => $templateType,
        'owner' => $username,
        'github' => $githubResult,
    ]);

    $viewerUrl = station_station_url('viewer.php?project=' . rawurlencode($slug));
    $settingsUrl = station_station_url('project-settings.php?project=' . rawurlencode($slug));

    return [
        'ok' => true,
        'slug' => $slug,
        'projectName' => $projectName,
        'templateType' => $templateType,
        'viewerUrl' => $viewerUrl,
        'settingsUrl' => $settingsUrl,
        'publicPath' => '/p/' . $slug . '/',
        'github' => $githubResult,
        'message' => 'Project "' . $projectName . '" created as ' . $slug . '.',
    ];
}

/**
 * @return array<string, mixed>
 */
function station_assist_link_new_project_github(
    string $username,
    string $slug,
    string $repoOwner,
    string $repoName,
    string $description
): array {
    $admin = station_admin_settings();
    if (empty($admin['githubEnabled'])) {
        return ['ok' => false, 'error' => 'GitHub is disabled on this station.'];
    }

    $gh = station_github_user_integration($username);
    if (!$gh['enabled'] || $gh['token'] === '') {
        return [
            'ok' => false,
            'error' => 'GitHub is not connected for your account. Open User Settings → GitHub and connect with OAuth or a token.',
        ];
    }

    $token = $gh['token'];
    $authMode = $gh['authMode'];

    if ($repoName === '') {
        $repoName = $slug;
    }
    $repoName = preg_replace('/[^A-Za-z0-9._-]/', '-', $repoName) ?? $repoName;
    $repoName = trim((string) $repoName, '-.');
    if ($repoName === '') {
        $repoName = $slug;
    }

    if ($repoOwner === '') {
        $repoOwner = $gh['username'];
    }
    if ($repoOwner === '') {
        $me = station_github_api('GET', '/user', $token);
        if (!empty($me['ok'])) {
            $data = json_decode((string) ($me['body'] ?? ''), true);
            if (is_array($data)) {
                $repoOwner = trim((string) ($data['login'] ?? ''));
            }
        }
    }

    if ($repoOwner === '' || !preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/', $repoOwner)) {
        return ['ok' => false, 'error' => 'Could not determine a valid GitHub owner.'];
    }
    if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/', $repoName)) {
        return ['ok' => false, 'error' => 'Invalid GitHub repository name.'];
    }

    if (!station_git_available()) {
        return ['ok' => false, 'error' => 'Git is not installed on this server.'];
    }

    $cr = station_github_create_private_repo($token, $repoOwner, $repoName, $description);
    if (empty($cr['ok'])) {
        return ['ok' => false, 'error' => (string) ($cr['message'] ?? 'Could not create GitHub repository.')];
    }

    $settings = station_project_settings($slug);
    $settings['github'] = array_merge((array) ($settings['github'] ?? []), [
        'repoOwner' => $repoOwner,
        'repoName' => $repoName,
        'defaultBranch' => 'main',
        'repoVisibility' => 'private',
    ]);
    station_save_project_settings($slug, $settings);

    $link = station_github_project_init_and_link($slug, $token, $authMode);
    if (empty($link['ok'])) {
        return [
            'ok' => false,
            'error' => 'Repository created on GitHub but git link/push failed: ' . ($link['message'] ?? ''),
            'repo' => $repoOwner . '/' . $repoName,
            'html_url' => (string) ($cr['html_url'] ?? ''),
        ];
    }

    return [
        'ok' => true,
        'repo' => $repoOwner . '/' . $repoName,
        'html_url' => (string) ($cr['html_url'] ?? ''),
        'push' => (string) ($link['message'] ?? 'Pushed to GitHub.'),
    ];
}
