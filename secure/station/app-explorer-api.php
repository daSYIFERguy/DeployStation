<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/project-launch.php';
require_once __DIR__ . '/lib/openai.php';

header('Content-Type: application/json; charset=utf-8');

station_require_login();

$slug = station_safe_name((string) ($_GET['project'] ?? $_POST['project'] ?? ''));
if ($slug === '' || !station_project_exists($slug)) {
    echo json_encode(['ok' => false, 'message' => 'Project not found.']);
    exit;
}

$user = station_current_user();
if (!station_user_may_access_project($user, $slug)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Access denied.']);
    exit;
}

$profile = station_project_launch_profile($slug);
try {
    $context = station_project_explorer_context($slug, $profile);
} catch (Throwable $e) {
    $context = [
        'slug' => $slug,
        'error' => 'Context build failed: ' . $e->getMessage(),
        'launchProfile' => $profile,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    @set_time_limit(120);

    $raw = (string) file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    $message = trim((string) ($body['message'] ?? ''));
    $extra = trim((string) ($body['extraContext'] ?? ''));
    $includeContext = !array_key_exists('includeContext', $body) || !empty($body['includeContext']);
    if (!$includeContext) {
        $context = [
            'slug' => $slug,
            'note' => 'Full workspace JSON omitted for this message. Enable "Include full workspace JSON" in App Explorer for file, Docker, and GitHub details.',
        ];
    }

    if (!isset($_SESSION['station_explorer_chat']) || !is_array($_SESSION['station_explorer_chat'])) {
        $_SESSION['station_explorer_chat'] = [];
    }
    if (!isset($_SESSION['station_explorer_chat'][$slug]) || !is_array($_SESSION['station_explorer_chat'][$slug])) {
        $_SESSION['station_explorer_chat'][$slug] = [];
    }

    $history = $_SESSION['station_explorer_chat'][$slug];
    try {
        $reply = station_openai_explorer_chat_reply($slug, $message, $context, $extra);
    } catch (Throwable $e) {
        $reply = ['ok' => false, 'text' => '', 'message' => 'Server error: ' . $e->getMessage()];
    }

    if (!empty($reply['ok'])) {
        $history[] = ['role' => 'user', 'content' => $message];
        $history[] = ['role' => 'assistant', 'content' => (string) $reply['text']];
        $_SESSION['station_explorer_chat'][$slug] = array_slice($history, -24);
    }

    $encoded = json_encode([
        'ok' => !empty($reply['ok']),
        'reply' => (string) ($reply['text'] ?? ''),
        'message' => (string) ($reply['message'] ?? ''),
        'openaiConfigured' => station_openai_configured(),
    ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

    if ($encoded === false) {
        echo '{"ok":false,"message":"Could not encode response.","reply":""}';
        exit;
    }
    echo $encoded;
    exit;
}

$copy = station_openai_project_explorer_copy($slug, $profile);

echo json_encode([
    'ok' => true,
    'profile' => $profile,
    'context' => $context,
    'description' => $copy['description'],
    'steps' => $copy['steps'],
    'source' => $copy['source'],
    'openaiMessage' => $copy['message'],
    'openaiConfigured' => station_openai_configured(),
], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
