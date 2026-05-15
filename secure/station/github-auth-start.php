<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/github-auth.php';

station_ensure_data_dir();
if (!station_is_setup_complete()) {
    header('Location: setup.php');
    exit;
}

if (station_current_user()) {
    header('Location: station.php');
    exit;
}

$purpose = (string) ($_GET['purpose'] ?? $_POST['purpose'] ?? 'login');
$purpose = in_array($purpose, ['login', 'request_access'], true) ? $purpose : 'login';

if ($purpose === 'request_access' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        station_flash_set('error', 'Enter your name and a valid email before continuing with GitHub.');
        header('Location: index.php#request-access');
        exit;
    }
    $_SESSION['github_request_access_name'] = $name;
    $_SESSION['github_request_access_email'] = $email;
}

$begin = station_github_oauth_begin($purpose, 'index.php');
if (empty($begin['ok']) || empty($begin['redirect'])) {
    station_flash_set('error', (string) ($begin['message'] ?? 'Could not start GitHub.'));
    header('Location: index.php');
    exit;
}

header('Location: ' . (string) $begin['redirect'], true, 302);
exit;
