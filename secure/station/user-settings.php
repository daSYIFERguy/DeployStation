<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

station_require_login();

$username = station_current_username();
$profile = station_user_profile($username);
$error = '';
$ok = station_flash_get('ok');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $profile['displayName'] = trim((string) ($_POST['display_name'] ?? $username));
    $profile['onboardingCompleted'] = true;
    $profile['integrations']['github'] = [
        'enabled' => isset($_POST['github_enabled']),
        'username' => trim((string) ($_POST['github_username'] ?? '')),
        'token' => trim((string) ($_POST['github_token'] ?? '')),
        'repo' => trim((string) ($_POST['github_repo'] ?? ''))
    ];
    $profile['integrations']['vscode'] = [
        'enabled' => isset($_POST['vscode_enabled']),
        'syncUrl' => trim((string) ($_POST['vscode_sync_url'] ?? '')),
        'notes' => trim((string) ($_POST['vscode_notes'] ?? ''))
    ];
    $profile['integrations']['chatgpt'] = [
        'enabled' => isset($_POST['chatgpt_enabled']),
        'apiKey' => trim((string) ($_POST['chatgpt_api_key'] ?? '')),
        'workspace' => trim((string) ($_POST['chatgpt_workspace'] ?? ''))
    ];
    $profile['integrations']['codex'] = [
        'enabled' => isset($_POST['codex_enabled']),
        'apiKey' => trim((string) ($_POST['codex_api_key'] ?? '')),
        'workspace' => trim((string) ($_POST['codex_workspace'] ?? ''))
    ];

    if (station_save_user_profile($username, $profile)) {
        station_log_event('user.settings.updated', ['username' => $username]);
        station_flash_set('ok', 'Settings saved.');
        header('Location: user-settings.php');
        exit;
    }
    $error = 'Could not save settings.';
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>User Settings</title><link rel="stylesheet" href="assets/style.css"></head><body class="station-body"><main class="station-shell"><header class="topbar card"><div><p class="kicker">Account Setup</p><h1>User Settings</h1><p>Configure integrations and API keys for GitHub, VS Code, ChatGPT, and Codex.</p></div><nav class="nav-pills"><a href="station.php">Dashboard</a><a href="onboarding.php">Onboarding</a><a href="integration-help.php">API Help</a></nav></header><?php if ($ok !== ''): ?><div class="alert ok"><?= station_h($ok) ?></div><?php endif; ?><?php if ($error !== ''): ?><div class="alert error"><?= station_h($error) ?></div><?php endif; ?><form method="post" class="card form-grid"><label>Display Name<input type="text" name="display_name" value="<?= station_h((string) ($profile['displayName'] ?? $username)) ?>"></label><section class="grid-two"><div class="card feature-card"><h2>GitHub</h2><label><input type="checkbox" name="github_enabled" <?= !empty($profile['integrations']['github']['enabled']) ? 'checked' : '' ?>> Enable GitHub</label><label>Username<input type="text" name="github_username" value="<?= station_h((string) ($profile['integrations']['github']['username'] ?? '')) ?>"></label><label>Token<input type="password" name="github_token" value="<?= station_h((string) ($profile['integrations']['github']['token'] ?? '')) ?>"></label><label>Default Repo<input type="text" name="github_repo" value="<?= station_h((string) ($profile['integrations']['github']['repo'] ?? '')) ?>"></label><a class="mini-link" href="integration-help.php#github">How to set this up</a></div><div class="card feature-card"><h2>VS Code</h2><label><input type="checkbox" name="vscode_enabled" <?= !empty($profile['integrations']['vscode']['enabled']) ? 'checked' : '' ?>> Enable VS Code</label><label>Sync URL<input type="text" name="vscode_sync_url" value="<?= station_h((string) ($profile['integrations']['vscode']['syncUrl'] ?? '')) ?>"></label><label>Notes<input type="text" name="vscode_notes" value="<?= station_h((string) ($profile['integrations']['vscode']['notes'] ?? '')) ?>"></label><a class="mini-link" href="integration-help.php#vscode">How to set this up</a></div><div class="card feature-card"><h2>ChatGPT</h2><label><input type="checkbox" name="chatgpt_enabled" <?= !empty($profile['integrations']['chatgpt']['enabled']) ? 'checked' : '' ?>> Enable ChatGPT</label><label>API Key<input type="password" name="chatgpt_api_key" value="<?= station_h((string) ($profile['integrations']['chatgpt']['apiKey'] ?? '')) ?>"></label><label>Workspace<input type="text" name="chatgpt_workspace" value="<?= station_h((string) ($profile['integrations']['chatgpt']['workspace'] ?? '')) ?>"></label><a class="mini-link" href="integration-help.php#chatgpt">How to set this up</a></div><div class="card feature-card"><h2>Codex</h2><label><input type="checkbox" name="codex_enabled" <?= !empty($profile['integrations']['codex']['enabled']) ? 'checked' : '' ?>> Enable Codex</label><label>API Key<input type="password" name="codex_api_key" value="<?= station_h((string) ($profile['integrations']['codex']['apiKey'] ?? '')) ?>"></label><label>Workspace<input type="text" name="codex_workspace" value="<?= station_h((string) ($profile['integrations']['codex']['workspace'] ?? '')) ?>"></label><a class="mini-link" href="integration-help.php#codex">How to set this up</a></div></section><button type="submit">Save Settings</button></form></main></body></html>
