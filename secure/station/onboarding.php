<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

station_require_login();
$user = station_current_user();
if (!station_can_build($user)) {
    header('Location: station.php');
    exit;
}

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
<html lang="en">
<head>
    <?= station_pwa_head_html('Welcome Setup', 'Connect GitHub as your code source for Deployment Station.') ?>
</head>
<body class="station-body">
    <main class="station-shell narrow">
        <header class="card">
            <p class="kicker">Welcome</p>
            <h1>Set up your workspace</h1>
            <p>GitHub is the primary code connection for this station. Add your token and default repo in the next step, or skip and do it later.</p>
        </header>
        <section class="mission-user-card mission-user-card-github" style="margin-top: 16px;">
            <h2 class="mission-user-card-title">GitHub</h2>
            <p class="mission-user-card-desc">Imports, clones, and project metadata will build on this connection as we expand push/pull workflows.</p>
        </section>
        <form method="post" class="card action-row" style="margin-top: 16px;">
            <button type="submit" name="continue" value="1">Open user settings</button>
            <button type="submit" name="skip" value="1" class="secondary-btn">Skip for now</button>
        </form>
    </main>
    <?= station_dashboard_page_footer_html() ?>
</body>
</html>
