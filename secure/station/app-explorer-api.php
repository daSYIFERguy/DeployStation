<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/project-launch.php';
require_once __DIR__ . '/lib/openai.php';

header('Content-Type: application/json; charset=utf-8');

$slug = station_safe_name((string) ($_GET['project'] ?? ''));
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
$copy = station_openai_project_explorer_copy($slug, $profile);

echo json_encode([
    'ok' => true,
    'profile' => $profile,
    'description' => $copy['description'],
    'steps' => $copy['steps'],
    'source' => $copy['source'],
    'openaiMessage' => $copy['message'],
    'openaiConfigured' => station_openai_configured(),
], JSON_UNESCAPED_SLASHES);
