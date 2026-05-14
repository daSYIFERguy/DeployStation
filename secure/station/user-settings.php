<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

station_require_login();

$username = station_current_username();
$user = station_current_user();
$canBuild = station_can_build($user);
$profile = station_user_profile($username);
$error = '';
$ok = station_flash_get('ok');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $profile['displayName'] = trim((string) ($_POST['display_name'] ?? $username));
    $profile['themeColor'] = station_normalize_theme_color((string) ($_POST['theme_color'] ?? station_effective_theme_color($username)));
    if ($canBuild) {
        $profile['onboardingCompleted'] = true;
        if (!isset($profile['integrations']) || !is_array($profile['integrations'])) {
            $profile['integrations'] = [];
        }
        $profile['integrations']['github'] = [
            'enabled' => isset($_POST['github_enabled']),
            'username' => trim((string) ($_POST['github_username'] ?? '')),
            'token' => trim((string) ($_POST['github_token'] ?? '')),
            'repo' => trim((string) ($_POST['github_repo'] ?? '')),
        ];
        unset(
            $profile['integrations']['vscode'],
            $profile['integrations']['chatgpt'],
            $profile['integrations']['codex']
        );
    }

    if (station_save_user_profile($username, $profile)) {
        station_log_event('user.settings.updated', ['username' => $username]);
        station_flash_set('ok', 'Settings saved.');
        header('Location: user-settings.php');
        exit;
    }
    $error = 'Could not save settings.';
}

$gh = isset($profile['integrations']['github']) && is_array($profile['integrations']['github'])
    ? $profile['integrations']['github']
    : [];
?>
<!doctype html>
<html lang="en">
<head>
    <?= station_pwa_head_html(
        'User Settings',
        $canBuild
            ? 'Account appearance and GitHub connection for imports and repo workflows.'
            : 'Manage your account display and theme.'
    ) ?>
</head>
<body class="station-body">
    <div class="dashboard-shell">
        <?= station_dashboard_nav_html('user-settings') ?>
        <main class="dashboard-main">
            <div class="station-shell">
                <header class="dashboard-topbar user-mission-top">
                    <div>
                        <p class="dashboard-kicker">Account</p>
                        <h1 class="dashboard-heading">User settings</h1>
                        <p class="dashboard-subheading"><?= $canBuild
                            ? 'Profile appearance and your GitHub connection — the single code integration for this station.'
                            : 'Display name and theme for your account.' ?></p>
                    </div>
                    <nav class="nav-pills">
                        <a href="station.php">Dashboard</a>
                        <?php if ($canBuild): ?>
                            <a href="onboarding.php">Welcome setup</a>
                            <a href="integration-help.php">GitHub &amp; API help</a>
                        <?php endif; ?>
                    </nav>
                </header>

                <?php if ($ok !== ''): ?><div class="alert ok"><?= station_h($ok) ?></div><?php endif; ?>
                <?php if ($error !== ''): ?><div class="alert error"><?= station_h($error) ?></div><?php endif; ?>

                <form method="post" class="user-mission-form">
                    <div class="mission-user-grid">
                        <div class="mission-user-card mission-user-card-profile">
                            <h2 class="mission-user-card-title">Profile</h2>
                            <p class="mission-user-card-desc">How you appear in the station and accent color for your session.</p>
                            <label class="mission-field">
                                <span class="mission-field-label">Display name</span>
                                <input type="text" name="display_name" value="<?= station_h((string) ($profile['displayName'] ?? $username)) ?>">
                            </label>
                            <label class="mission-field mission-field-row">
                                <span class="mission-field-label">Theme color</span>
                                <input type="color" name="theme_color" value="<?= station_h(station_normalize_theme_color((string) ($profile['themeColor'] ?? station_effective_theme_color($username)))) ?>">
                            </label>
                        </div>

                        <?php if ($canBuild): ?>
                        <div class="mission-user-card mission-user-card-github">
                            <div class="mission-user-card-head">
                                <h2 class="mission-user-card-title">GitHub</h2>
                                <span class="mission-badge mission-badge-gh">Code connection</span>
                            </div>
                            <p class="mission-user-card-desc">Personal access token and optional default repository for imports and tooling.</p>
                            <label class="mission-toggle">
                                <input type="checkbox" name="github_enabled" <?= !empty($gh['enabled']) ? 'checked' : '' ?>>
                                <span>Enable GitHub for this account</span>
                            </label>
                            <label class="mission-field">
                                <span class="mission-field-label">Username</span>
                                <input type="text" name="github_username" value="<?= station_h((string) ($gh['username'] ?? '')) ?>" placeholder="octocat" autocomplete="username">
                            </label>
                            <label class="mission-field">
                                <span class="mission-field-label">Token</span>
                                <input type="password" name="github_token" value="<?= station_h((string) ($gh['token'] ?? '')) ?>" placeholder="ghp_…" autocomplete="new-password">
                            </label>
                            <label class="mission-field">
                                <span class="mission-field-label">Default repo <span class="mission-optional">optional</span></span>
                                <input type="text" name="github_repo" value="<?= station_h((string) ($gh['repo'] ?? '')) ?>" placeholder="owner/name">
                            </label>
                            <p class="mission-help-link"><a href="integration-help.php#github">How tokens and scopes work</a></p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="mission-user-actions">
                        <button type="submit" class="btn-primary">Save settings</button>
                    </div>
                </form>
            </div>
        </main>
    </div>
    <?= station_dashboard_nav_script_html() ?>
    <?= station_clipboard_fab_html() ?>
    <?= station_pwa_register_html() ?>
</body>
</html>
