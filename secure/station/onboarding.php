<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

station_require_login();
$username = station_current_username();
$profile = station_user_profile($username);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['skip'])) {
        $profile['onboardingCompleted'] = true;
        station_save_user_profile($username, $profile);
        station_log_event('user.onboarding.skipped', ['username' => $username]);
        header('Location: station.php');
        exit;
    }

    $profile['onboardingCompleted'] = true;
    station_save_user_profile($username, $profile);
    station_log_event('user.onboarding.completed', ['username' => $username]);
    header('Location: user-settings.php');
    exit;
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Welcome Setup</title><link rel="stylesheet" href="assets/style.css"></head><body class="station-body"><main class="station-shell narrow"><header class="card"><p class="kicker">Welcome</p><h1>Set Up Your Workspace</h1><p>This quick flow helps connect GitHub, VS Code, ChatGPT, and Codex. You can skip and configure later.</p></header><section class="grid-two"><article class="card feature-card"><h2>GitHub</h2><p>Connect repos, tokens, and workflow references.</p></article><article class="card feature-card"><h2>VS Code</h2><p>Save sync endpoints and developer notes.</p></article><article class="card feature-card"><h2>ChatGPT</h2><p>Enable AI-assisted planning and content flows.</p></article><article class="card feature-card"><h2>Codex</h2><p>Enable coding-focused AI features.</p></article></section><form method="post" class="card action-row"><button type="submit" name="continue" value="1">Continue to API Setup</button><button type="submit" name="skip" value="1" class="secondary-btn">Skip for now</button></form></main></body></html>
