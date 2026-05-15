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
require_once __DIR__ . '/openai.php';
require_once __DIR__ . '/project-launch.php';

/**
 * System instructions for new-project conversations (discovery → confirm → create).
 */
function station_assist_project_creation_rules(): string
{
    $dockerOn = station_docker_enabled() ? 'yes' : 'no (Docker disabled station-wide — do not offer containers)';

    return <<<RULES
NEW PROJECT WORKFLOW (mandatory — never skip):

You must NOT call create_project in the same turn as your first reply about a new app idea. Follow these phases:

**Phase 1 — Clarifying questions** (one message, short bullets). Gather only what is missing; infer the rest and say what you assumed.
Always cover when relevant:
- **Name** — display name for the project
- **MVP brief** — who it is for, core features for v1, anything explicitly out of scope
- **Template / stack** — e.g. static-html, react-vite, next-app, node-express, php-nginx, python-flask, python-fastapi, pwa (use list_templates if unsure)
- **Docker** — "Run on Docker on this station?" (station Docker enabled: {$dockerOn}; default yes when enabled unless they want host-only)
- **Web entrypoint** — "Serve from project root (index.html) or a subfolder?" (e.g. `public/`, `dist/` after build — only set subfolder if that path exists in the template or they accept fixing it later in Manage → General)
- **GitHub** — private repo + push initial code? (default yes when their GitHub is connected)

Add **type-specific** questions when helpful:
- Static / SPA / Next: production build command, env vars, public vs authenticated
- Node API: port, database, auth for v1
- PHP: docroot subfolder vs root
- Python: SQLite vs Postgres, migrations on first run
- PWA / extension: install target, permissions

**Phase 2 — Proposed setup** (next message after they answer). Summarize in a compact block:
name, template_type, use_docker, web_entrypoint_dir/file (or root), push_to_github, and 2–4 line MVP summary.

**Phase 3 — Explicit confirmation**. Ask: "Would you like me to create this project now?" (or similar). Wait for a clear yes (yes / go ahead / create it / do it now). If they change their mind or add requirements, update the summary and ask again.

**Phase 4 — create_project** only after Phase 3. Call create_project with:
- operator_confirmed: true
- project_name, project_description (full MVP brief including deployment choices)
- template_type, use_docker, web_entrypoint_dir, web_entrypoint_file, push_to_github
Do not pass github_owner unless they named an org.

If they only wanted advice or are still exploring, do not call create_project.
RULES;
}

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
                'description' => 'Create a DeployStation project ONLY after the operator confirmed "yes" to your proposed setup summary. Deploys template, MVP docs, GitHub metadata, optional private repo + push. Never call without operator_confirmed true.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'operator_confirmed' => [
                            'type' => 'boolean',
                            'description' => 'Must be true only after the operator explicitly agreed to create the project now (following your summary).',
                        ],
                        'project_name' => [
                            'type' => 'string',
                            'description' => 'Display name for the project (used to derive the slug folder name).',
                        ],
                        'template_type' => [
                            'type' => 'string',
                            'description' => 'Template key, e.g. static-html, node-express, react-vite, next-app, php-nginx, python-flask, pwa. Call list_templates if unsure.',
                        ],
                        'use_docker' => [
                            'type' => 'boolean',
                            'description' => 'When true (default), enable Docker for this project on the station. False = host-only / no containers.',
                        ],
                        'web_entrypoint_dir' => [
                            'type' => 'string',
                            'description' => 'Relative folder for web entrypoint, empty string = project root. e.g. public or dist',
                        ],
                        'web_entrypoint_file' => [
                            'type' => 'string',
                            'description' => 'Entry filename, usually index.html or index.php. Default index.html.',
                        ],
                        'github_repo_name' => [
                            'type' => 'string',
                            'description' => 'Optional GitHub repo name (defaults from project name / slug). Letters, numbers, dots, dashes, underscores.',
                        ],
                        'github_owner' => [
                            'type' => 'string',
                            'description' => 'Optional GitHub owner or org. Omit to use the operator\'s connected GitHub username.',
                        ],
                        'push_to_github' => [
                            'type' => 'boolean',
                            'description' => 'When true (default), create private repo and push after scaffolding. Set false only if operator declined GitHub.',
                        ],
                        'project_description' => [
                            'type' => 'string',
                            'description' => 'Full MVP brief for the SWE handoff: users, features, constraints, stack, plus deployment choices (Docker, entrypoint, GitHub).',
                        ],
                    ],
                    'required' => ['operator_confirmed', 'project_name', 'project_description'],
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

    if (empty($arguments['operator_confirmed'])) {
        return [
            'ok' => false,
            'result' => [
                'error' => 'operator_confirmed must be true',
                'hint' => 'Summarize the proposed setup, ask "Would you like me to create this project now?", and wait for explicit approval before calling create_project.',
            ],
            'clientActions' => [],
        ];
    }

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
    $useDocker = !array_key_exists('use_docker', $arguments) || !empty($arguments['use_docker']);
    if (!station_docker_enabled()) {
        $useDocker = false;
    }

    $webEntryDir = trim(str_replace('\\', '/', (string) ($arguments['web_entrypoint_dir'] ?? '')), '/');
    $webEntryFile = trim((string) ($arguments['web_entrypoint_file'] ?? '')) ?: 'index.html';

    $repoName = trim((string) ($arguments['github_repo_name'] ?? ''));
    $repoOwner = trim((string) ($arguments['github_owner'] ?? ''));
    $description = trim((string) ($arguments['project_description'] ?? ''));
    if ($description === '') {
        return [
            'ok' => false,
            'result' => [
                'error' => 'project_description is required',
                'hint' => 'Include the MVP brief and deployment choices in project_description before creating.',
            ],
            'clientActions' => [],
        ];
    }

    $description = station_assist_enrich_description_with_deployment_choices(
        $description,
        $useDocker,
        $webEntryDir,
        $webEntryFile,
        $pushToGithub,
        $templateType
    );

    $created = station_assist_create_starter_project(
        $username,
        $projectName,
        $templateType,
        $pushToGithub,
        $repoOwner,
        $repoName,
        $description,
        $useDocker,
        $webEntryDir,
        $webEntryFile
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
        $clientActions[] = ['type' => 'navigate', 'url' => (string) $created['viewerUrl'], 'hard' => true];
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
function station_assist_enrich_description_with_deployment_choices(
    string $description,
    bool $useDocker,
    string $webEntryDir,
    string $webEntryFile,
    bool $pushToGithub,
    string $templateType
): string {
    $entryLabel = $webEntryDir === ''
        ? 'project root (' . $webEntryFile . ')'
        : $webEntryDir . '/' . $webEntryFile;

    $block = "## Deployment choices (operator confirmed)\n\n"
        . '- Template: `' . $templateType . "`\n"
        . '- Docker on DeployStation: ' . ($useDocker ? 'yes' : 'no') . "\n"
        . '- Web entrypoint: ' . $entryLabel . "\n"
        . '- GitHub private repo + push: ' . ($pushToGithub ? 'yes' : 'no') . "\n";

    if (str_contains($description, 'Deployment choices')) {
        return $description;
    }

    return rtrim($description) . "\n\n" . $block;
}

function station_assist_apply_web_entrypoint(string $slug, string $dir, string $file): bool
{
    $slug = station_safe_name($slug);
    if ($slug === '') {
        return false;
    }

    $dir = trim(str_replace('\\', '/', $dir), '/');
    $file = trim($file) ?: 'index.html';

    if ($dir === '' && $file === 'index.html') {
        return true;
    }

    $check = station_project_validate_web_entry_input($slug, $dir, $file);
    if (empty($check['ok'])) {
        return false;
    }

    $settings = station_project_settings($slug);
    $settings['launch'] = [
        'webEntryManual' => true,
        'webEntryDir' => (string) ($check['dir'] ?? $dir),
        'webEntryFile' => (string) ($check['file'] ?? $file),
    ];

    return station_save_project_settings($slug, $settings);
}

function station_assist_create_starter_project(
    string $username,
    string $projectName,
    string $templateType,
    bool $pushToGithub,
    string $repoOwner,
    string $repoName,
    string $description,
    bool $useDocker = true,
    string $webEntryDir = '',
    string $webEntryFile = 'index.html'
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

    $githubTargets = station_assist_resolve_github_targets(
        $username,
        $slug,
        $projectName,
        $repoOwner,
        $repoName
    );
    $githubMetaSaved = false;
    if (!empty($githubTargets['repoOwner']) && !empty($githubTargets['repoName'])) {
        station_assist_apply_project_github_metadata(
            $slug,
            (string) $githubTargets['repoOwner'],
            (string) $githubTargets['repoName'],
            $description,
            $projectName
        );
        $githubMetaSaved = true;
        $repoOwner = (string) $githubTargets['repoOwner'];
        $repoName = (string) $githubTargets['repoName'];
    }

    $docs = station_assist_write_project_documentation(
        $slug,
        $projectName,
        $templateType,
        $description,
        $username
    );

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
                $projectName,
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
        'documentation' => $docs,
        'githubTargets' => $githubTargets,
        'useDocker' => $useDocker,
        'webEntrypointApplied' => $entrypointApplied,
        'message' => 'Project "' . $projectName . '" created as ' . $slug . '.',
    ];
}

function station_assist_sanitize_github_repo_name(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9._-]+/', '-', $name) ?? $name;
    $name = trim((string) $name, '-.');
    while (str_contains($name, '--')) {
        $name = str_replace('--', '-', $name);
    }

    return $name;
}

/**
 * @return array{
 *   repoOwner: string,
 *   repoName: string,
 *   repoUrl: string,
 *   canPush: bool,
 *   pushError: string,
 *   githubLogin: string
 * }
 */
function station_assist_resolve_github_targets(
    string $username,
    string $slug,
    string $projectName,
    string $repoOwnerArg,
    string $repoNameArg
): array {
    $admin = station_admin_settings();
    $empty = [
        'repoOwner' => '',
        'repoName' => '',
        'repoUrl' => '',
        'canPush' => false,
        'pushError' => '',
        'githubLogin' => '',
    ];

    if (empty($admin['githubEnabled'])) {
        return array_merge($empty, ['pushError' => 'GitHub is disabled on this station.']);
    }

    $gh = station_github_user_integration($username);
    $githubLogin = trim((string) ($gh['username'] ?? ''));
    if ($githubLogin === '' && $gh['token'] !== '') {
        $me = station_github_api('GET', '/user', $gh['token']);
        if (!empty($me['ok'])) {
            $data = json_decode((string) ($me['body'] ?? ''), true);
            if (is_array($data)) {
                $githubLogin = trim((string) ($data['login'] ?? ''));
            }
        }
    }

    $repoOwner = trim($repoOwnerArg);
    if ($repoOwner === '') {
        $repoOwner = $githubLogin;
    }

    $repoName = station_assist_sanitize_github_repo_name($repoNameArg);
    if ($repoName === '') {
        $repoName = station_assist_sanitize_github_repo_name($projectName);
    }
    if ($repoName === '') {
        $repoName = station_assist_sanitize_github_repo_name($slug);
    }

    if ($repoOwner === '' || !preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/', $repoOwner)) {
        return array_merge($empty, [
            'pushError' => 'Could not determine GitHub owner. Connect GitHub under User Settings.',
            'githubLogin' => $githubLogin,
        ]);
    }
    if ($repoName === '' || !preg_match('/^[A-Za-z0-9._-]{1,100}$/', $repoName)) {
        return array_merge($empty, [
            'repoOwner' => $repoOwner,
            'pushError' => 'Invalid repository name derived from project.',
            'githubLogin' => $githubLogin,
        ]);
    }

    $repoUrl = 'https://github.com/' . $repoOwner . '/' . $repoName;
    $canPush = $gh['enabled'] && $gh['token'] !== '' && station_git_available();
    $pushError = '';
    if (!$gh['enabled'] || $gh['token'] === '') {
        $pushError = 'GitHub is not connected for your account. Open User Settings → GitHub.';
    } elseif (!station_git_available()) {
        $pushError = 'Git is not installed on this server.';
    }

    return [
        'repoOwner' => $repoOwner,
        'repoName' => $repoName,
        'repoUrl' => $repoUrl,
        'canPush' => $canPush,
        'pushError' => $pushError,
        'githubLogin' => $githubLogin !== '' ? $githubLogin : $repoOwner,
    ];
}

function station_assist_apply_project_github_metadata(
    string $slug,
    string $repoOwner,
    string $repoName,
    string $description,
    string $projectName
): void {
    $slug = station_safe_name($slug);
    if ($slug === '' || $repoOwner === '' || $repoName === '') {
        return;
    }

    $settings = station_project_settings($slug);
    $repoUrl = 'https://github.com/' . $repoOwner . '/' . $repoName;

    $settings['github'] = array_merge((array) ($settings['github'] ?? []), [
        'repoOwner' => $repoOwner,
        'repoName' => $repoName,
        'repoUrl' => $repoUrl,
        'visibility' => 'private',
        'defaultBranch' => trim((string) (($settings['github'] ?? [])['defaultBranch'] ?? '')) ?: 'main',
        'releaseWorkflow' => !empty(($settings['github'] ?? [])['releaseWorkflow']),
    ]);

    $notes = trim((string) ($settings['notes'] ?? ''));
    if ($notes === '') {
        $settings['notes'] = "MVP brief — {$projectName}\n\n" . trim($description);
    }

    station_save_project_settings($slug, $settings);
}

/**
 * @return array{plans: string, readme: string, readmeSource: string}
 */
function station_assist_write_project_documentation(
    string $slug,
    string $projectName,
    string $templateType,
    string $description,
    string $username
): array {
    $slug = station_safe_name($slug);
    $description = trim($description);
    if ($description === '') {
        $description = 'Starter project created with DeployStation AI Assist.';
    }

    $plansMd = station_assist_generate_mvp_plans_md($projectName, $templateType, $description, $username);
    if ($plansMd === '') {
        $plansMd = station_assist_build_project_plans_md($projectName, $templateType, $description);
    }
    station_write_project_file($slug, 'PROJECT_PLANS.md', $plansMd);

    $readmePath = station_project_path($slug) . '/README.md';
    $existingReadme = is_file($readmePath) ? (string) (@file_get_contents($readmePath) ?: '') : '';

    $readmeSource = 'template';
    $readme = station_assist_generate_readme_content(
        $projectName,
        $templateType,
        $description,
        $existingReadme,
        $username
    );
    if ($readme !== '') {
        station_write_project_file($slug, 'README.md', $readme);
        $readmeSource = station_openai_configured($username) ? 'openai' : 'template+plans';
    }

    return [
        'plans' => 'PROJECT_PLANS.md',
        'readme' => 'README.md',
        'readmeSource' => $readmeSource,
    ];
}

function station_assist_build_project_plans_md(string $projectName, string $templateType, string $description): string
{
    $when = gmdate('Y-m-d');
    $lines = [
        '# Project plans: ' . $projectName,
        '',
        '_Generated by DeployStation AI Assist on ' . $when . '._',
        '',
        '## Template',
        '',
        '- Starter: `' . $templateType . '`',
        '',
        '## Product summary',
        '',
        trim($description),
        '',
        '## Vision & requirements',
        '',
    ];

    foreach (preg_split('/\r\n|\r|\n/', $description) ?: [] as $line) {
        $line = rtrim((string) $line);
        if ($line === '') {
            $lines[] = '';
            continue;
        }
        if (preg_match('/^[-*#>\d]/', $line)) {
            $lines[] = $line;
        } else {
            $lines[] = '- ' . $line;
        }
    }

    $lines[] = '';
    $lines[] = '## MVP scope (must ship)';
    $lines[] = '';
    $lines[] = '- [ ] Core user flows described above';
    $lines[] = '- [ ] Runnable locally and via Docker on DeployStation';
    $lines[] = '';
    $lines[] = '## Out of scope (v1)';
    $lines[] = '';
    $lines[] = '- [ ] Production hardening, billing, and multi-tenant admin (unless specified above)';
    $lines[] = '';
    $lines[] = '## Next steps';
    $lines[] = '';
    $lines[] = '- [ ] Review generated source under this project folder';
    $lines[] = '- [ ] Copy `.env.example` to `.env` and configure secrets';
    $lines[] = '- [ ] Enable Docker under **Manage → Docker** and run **Start** on the dashboard';
    $lines[] = '- [ ] Iterate in **Files** or push from your IDE via **GitHub sync**';
    $lines[] = '';

    return implode("\n", $lines);
}

function station_assist_generate_mvp_plans_md(
    string $projectName,
    string $templateType,
    string $description,
    string $username
): string {
    if (!station_openai_configured($username)) {
        return '';
    }

    $prompt = <<<PROMPT
Write PROJECT_PLANS.md — an MVP handoff for a software engineer building "{$projectName}".

Starter template on disk: {$templateType}
Product brief from the product owner:
{$description}

Include these sections with concrete bullets (not placeholders). Honor any "## Deployment choices" section in the brief.
## Product summary
## Users & jobs to be done
## MVP scope (must ship in v1)
## Out of scope (v1)
## User stories (As a … I want … so that …)
## Technical approach (how the template fits, key modules, data, APIs, Docker vs host-only, web entrypoint)
## Milestones (ordered checklist a SWE can execute)
## Definition of done

Return ONLY markdown. No code fences. Be specific to the brief.
PROMPT;

    $completion = station_openai_chat([
        ['role' => 'system', 'content' => 'You write actionable MVP specs for full-stack engineers.'],
        ['role' => 'user', 'content' => $prompt],
    ], $username, 2000);

    if (empty($completion['ok'])) {
        return '';
    }

    return station_assist_strip_markdown_fence(trim((string) ($completion['text'] ?? '')));
}

function station_assist_generate_readme_content(
    string $projectName,
    string $templateType,
    string $description,
    string $existingReadme,
    string $username
): string {
    if (!station_openai_configured($username)) {
        return station_assist_merge_plans_into_readme($projectName, $description, $existingReadme);
    }

    $templateSnippet = $existingReadme !== '' ? mb_substr($existingReadme, 0, 3500) : '(no template readme yet)';
    $prompt = <<<PROMPT
Write README.md for a new repository handed to a software engineer to build an MVP.

Project name: {$projectName}
Template/starter already scaffolded: {$templateType}
Product brief:
{$description}

The repo includes template source, DEPLOYSTATION.md, and PROJECT_PLANS.md (full SWE spec). Preserve accurate install/run/Docker commands from the template excerpt below.

Required sections:
- Title + one-paragraph product summary
- ## MVP overview (3–6 bullets of what v1 delivers)
- ## Getting started (clone, env, install, run locally, Docker)
- ## Project documentation (link to PROJECT_PLANS.md for full spec)
- ## DeployStation (optional hosting: /p/slug/, GitHub sync)

Tone: professional README for a real repo. Return ONLY markdown, no fences, under 140 lines.
PROMPT;

    $completion = station_openai_chat([
        ['role' => 'system', 'content' => 'You write concise, accurate README files for developer handoffs.'],
        ['role' => 'user', 'content' => $prompt . "\n\n---\nTemplate README excerpt:\n" . $templateSnippet],
    ], $username, 1800);

    if (empty($completion['ok']) || trim((string) ($completion['text'] ?? '')) === '') {
        return station_assist_merge_plans_into_readme($projectName, $description, $existingReadme);
    }

    $text = station_assist_strip_markdown_fence(trim((string) ($completion['text'] ?? '')));

    if (!str_contains($text, 'PROJECT_PLANS')) {
        $text .= "\n\n## Project plans\n\nSee [PROJECT_PLANS.md](./PROJECT_PLANS.md) for the full roadmap.\n";
    }

    if (!str_contains($text, 'DeployStation') && !str_contains($text, 'DEPLOYSTATION')) {
        $text .= "\n\nSee `DEPLOYSTATION.md` for DeployStation and Docker hosting notes.\n";
    }

    return $text . "\n";
}

function station_assist_strip_markdown_fence(string $text): string
{
    if ($text === '') {
        return '';
    }
    if (str_starts_with($text, '```')) {
        $text = preg_replace('/^```(?:markdown|md)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```\s*$/', '', $text) ?? $text;
        $text = trim($text);
    }

    return $text;
}

function station_assist_merge_plans_into_readme(string $projectName, string $description, string $existingReadme): string
{
    $base = $existingReadme !== '' ? rtrim($existingReadme) : '# ' . $projectName . "\n\n";
    $block = "\n\n## Project plans\n\n" . trim($description) . "\n\nSee [PROJECT_PLANS.md](./PROJECT_PLANS.md) for the full roadmap.\n";

    if (str_contains($base, '## Project plans')) {
        return $base . "\n";
    }

    return $base . $block;
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

    $resolved = station_assist_resolve_github_targets($username, $slug, $projectName, $repoOwner, $repoName);
    $repoOwner = (string) ($resolved['repoOwner'] ?? $repoOwner);
    $repoName = (string) ($resolved['repoName'] ?? $repoName);

    if ($repoOwner === '' || $repoName === '') {
        return ['ok' => false, 'error' => (string) ($resolved['pushError'] ?? 'Could not resolve GitHub owner or repository name.')];
    }

    station_assist_apply_project_github_metadata($slug, $repoOwner, $repoName, $description, $projectName);

    $repoDescription = mb_substr(trim($description), 0, 350);

    $cr = station_github_create_private_repo($token, $repoOwner, $repoName, $repoDescription);
    if (empty($cr['ok'])) {
        return [
            'ok' => false,
            'error' => (string) ($cr['message'] ?? 'Could not create GitHub repository.'),
            'repo' => $repoOwner . '/' . $repoName,
            'metadataSaved' => true,
        ];
    }

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
