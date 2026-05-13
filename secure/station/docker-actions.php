<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/docker.php';

@set_time_limit(0);
@ignore_user_abort(true);

station_require_builder();

$wantsJson = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
    || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
    || (string) ($_REQUEST['format'] ?? '') === 'json';

function station_docker_action_respond(bool $ok, string $message, string $redirect, array $extra = [], bool $asJson = false): void
{
    if ($asJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_SLASHES);
        exit;
    }
    station_flash_set($ok ? 'ok' : 'error', $message);
    header('Location: ' . $redirect);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$project = station_safe_name((string) ($_REQUEST['project'] ?? ''));
$action = (string) ($_REQUEST['action'] ?? '');
$returnTo = (string) ($_REQUEST['return'] ?? 'station.php');
if (!preg_match('/^[a-z0-9\-_.?=&%]+\.php(?:\?[a-z0-9\-_.?=&%]*)?$/i', $returnTo)) {
    $returnTo = 'station.php';
}

$readActions = ['status', 'logs'];
$writeActions = [
    'start' => ['up', '-d'],
    'stop' => ['stop'],
    'restart' => ['restart'],
    'down' => ['down'],
    'rebuild' => ['up', '-d', '--build', '--force-recreate'],
];

$isReadAction = in_array($action, $readActions, true);
$isWriteAction = isset($writeActions[$action]);

if (!$isReadAction && !$isWriteAction) {
    station_docker_action_respond(false, 'Unknown Docker action.', $returnTo, [], $wantsJson);
}

if ($isWriteAction && $method !== 'POST') {
    station_docker_action_respond(false, 'Docker actions must use POST.', $returnTo, [], $wantsJson);
}

if ($project === '' || !station_project_exists($project)) {
    station_docker_action_respond(false, 'Project not found.', $returnTo, [], $wantsJson);
}

$user = station_current_user();
if (!station_can_access_project($user, station_project_access_mode($project))) {
    station_docker_action_respond(false, 'You do not have access to manage that project.', $returnTo, [], $wantsJson);
}

if (!station_docker_enabled()) {
    station_docker_action_respond(false, 'Docker deployment is disabled in Admin Settings.', $returnTo, [], $wantsJson);
}

$projectSettings = station_project_settings($project);
$dockerConfig = isset($projectSettings['docker']) && is_array($projectSettings['docker']) ? $projectSettings['docker'] : [];
$projectPath = station_project_path($project);
$composePath = $projectPath . '/docker-compose.yml';

if ($action === 'status') {
    $status = station_project_docker_status($project);
    station_docker_action_respond(true, 'Status fetched.', $returnTo, ['status' => $status], true);
}

if ($action === 'logs') {
    $logContent = station_read_project_docker_log($project);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'log' => $logContent], JSON_UNESCAPED_SLASHES);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo $logContent;
    exit;
}

// Confirm docker is actually available before attempting anything.
$engine = station_docker_engine_available();
if (empty($engine['ok'])) {
    $message = 'Docker engine is not reachable. ' . trim((string) ($engine['output'] ?? ''));
    station_docker_action_respond(false, $message, $returnTo, [], $wantsJson);
}

// Always (re)generate the compose file & Dockerfile so the on-disk artifacts match the saved config.
if ($dockerConfig === [] && !is_file($composePath)) {
    station_docker_action_respond(
        false,
        'Configure Docker for this project first.',
        'docker-config.php?project=' . urlencode($project),
        [],
        $wantsJson
    );
}

if (!empty($dockerConfig)) {
    if (!isset($dockerConfig['hostPort']) || (int) $dockerConfig['hostPort'] <= 0) {
        $dockerConfig['hostPort'] = station_allocate_project_host_port($project);
        $projectSettings['docker'] = $dockerConfig;
        station_save_project_settings($project, $projectSettings);
    }

    $dockerfilePath = $projectPath . '/Dockerfile';
    $refreshCompose = in_array($action, ['start', 'rebuild', 'restart'], true) || !is_file($composePath);
    if ($refreshCompose) {
        $composeContent = station_generate_docker_compose($dockerConfig);
        if (@file_put_contents($composePath, $composeContent, LOCK_EX) === false) {
            station_docker_action_respond(false, 'Could not write docker-compose.yml for ' . $project . '.', $returnTo, [], $wantsJson);
        }
    }

    $mustRefreshDockerfile = $action === 'rebuild' || !is_file($dockerfilePath);
    if ($mustRefreshDockerfile) {
        station_ensure_project_dockerfile($project, $action === 'rebuild');
    }
}

$timeout = $action === 'start' || $action === 'rebuild' ? 900 : 300;
$result = station_run_project_docker_compose($project, $writeActions[$action], $timeout);
station_log_event('project.docker.' . $action, [
    'project' => $project,
    'exit' => $result['code'] ?? null,
]);

if (!empty($result['ok'])) {
    $extra = [];
    if (!empty($dockerConfig['hostPort'])) {
        $extra['hostPort'] = (int) $dockerConfig['hostPort'];
    }
    $includeResult = station_write_nginx_projects_conf();
    if (empty($includeResult['ok'])) {
        station_log_event('nginx.include.failed', [
            'project' => $project,
            'phase' => 'docker.' . $action,
            'message' => (string) ($includeResult['message'] ?? ''),
        ]);
    }
    station_docker_action_respond(
        true,
        ucfirst($action) . ' completed for ' . $project . '.',
        $returnTo,
        $extra,
        $wantsJson
    );
}

$output = trim((string) ($result['output'] ?? ''));
$snippet = $output !== '' ? mb_substr($output, -800) : '';
$message = 'Docker ' . $action . ' failed for ' . $project . '. View full log from the Docker panel.';
if ($snippet !== '') {
    $message .= ' Last output: ' . $snippet;
}
station_docker_action_respond(false, $message, $returnTo, ['log' => $output], $wantsJson);
