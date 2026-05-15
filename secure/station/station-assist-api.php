<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/project-launch.php';
require_once __DIR__ . '/lib/station-assist.php';

header('Content-Type: application/json; charset=utf-8');

station_require_login();

$user = station_current_user();
$username = station_current_username();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    @set_time_limit(120);

    $raw = (string) file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    $message = trim((string) ($body['message'] ?? ''));
    $pageUrl = trim((string) ($body['pageUrl'] ?? ''));
    $pageTitle = trim((string) ($body['pageTitle'] ?? ''));
    $projectSlug = station_safe_name((string) ($body['project'] ?? ''));
    $extra = trim((string) ($body['extraContext'] ?? ''));
    $includePage = !array_key_exists('includePageContext', $body) || !empty($body['includePageContext']);
    $includeProject = !array_key_exists('includeProjectContext', $body) || !empty($body['includeProjectContext']);

    if ($projectSlug === '') {
        $projectSlug = station_assist_detect_project_slug($pageUrl);
    }

    $pageContext = $includePage
        ? station_assist_page_context($pageUrl, $pageTitle, $projectSlug)
        : ['note' => 'Page context omitted for this message.'];

    $projectContext = null;
    if ($includeProject && $projectSlug !== '' && station_project_exists($projectSlug)) {
        if (!station_user_may_access_project($user, $projectSlug)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Access denied for this project.'], JSON_UNESCAPED_SLASHES);
            exit;
        }
        try {
            $profile = station_project_launch_profile($projectSlug);
            $projectContext = station_project_explorer_context($projectSlug, $profile);
        } catch (Throwable $e) {
            $projectContext = [
                'slug' => $projectSlug,
                'error' => 'Could not load full project context: ' . $e->getMessage(),
            ];
        }
    }

    if (!isset($_SESSION['station_assist_chat']) || !is_array($_SESSION['station_assist_chat'])) {
        $_SESSION['station_assist_chat'] = [];
    }
    $sessionKey = $username !== '' ? $username : 'default';
    $history = $_SESSION['station_assist_chat'][$sessionKey] ?? [];
    if (!is_array($history)) {
        $history = [];
    }

    $reply = station_assist_chat_reply($message, $pageContext, $projectContext, $extra, $includeProject, $history);
    $clientActions = isset($reply['clientActions']) && is_array($reply['clientActions']) ? $reply['clientActions'] : [];

    if (!empty($reply['ok'])) {
        $history = $_SESSION['station_assist_chat'][$sessionKey] ?? [];
        if (!is_array($history)) {
            $history = [];
        }
        $history[] = ['role' => 'user', 'content' => $message];
        $history[] = ['role' => 'assistant', 'content' => (string) $reply['text']];
        $_SESSION['station_assist_chat'][$sessionKey] = array_slice($history, -24);
    }

    $encoded = json_encode([
        'ok' => !empty($reply['ok']),
        'reply' => (string) ($reply['text'] ?? ''),
        'message' => (string) ($reply['message'] ?? ''),
        'openaiConfigured' => station_openai_configured(),
        'projectSlug' => $projectSlug,
        'clientActions' => $clientActions,
    ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

    if ($encoded === false) {
        echo '{"ok":false,"message":"Could not encode response.","reply":""}';
        exit;
    }
    echo $encoded;
    exit;
}

$pageUrl = trim((string) ($_GET['pageUrl'] ?? ''));
$pageTitle = trim((string) ($_GET['pageTitle'] ?? ''));
$projectSlug = station_assist_detect_project_slug($pageUrl);
if ($projectSlug === '') {
    $projectSlug = station_safe_name((string) ($_GET['project'] ?? ''));
}

$pageContext = station_assist_page_context($pageUrl, $pageTitle, $projectSlug);

$sessionKey = $username !== '' ? $username : 'default';
$chatHistory = $_SESSION['station_assist_chat'][$sessionKey] ?? [];
if (!is_array($chatHistory)) {
    $chatHistory = [];
}

echo json_encode([
    'ok' => true,
    'openaiConfigured' => station_openai_configured(),
    'openaiLabel' => station_openai_status_label(),
    'pageContext' => $pageContext,
    'projectSlug' => $projectSlug,
    'apiBase' => station_station_url(''),
    'chatHistory' => $chatHistory,
], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
