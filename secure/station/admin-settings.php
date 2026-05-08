<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

station_require_owner();
$settings = station_admin_settings();
$uiConfig = station_ui_config();
$error = '';
$ok = station_flash_get('ok');
$accessModes = station_allowed_project_access_modes();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings['defaultProjectVisibility'] = ((string) ($_POST['defaultProjectVisibility'] ?? 'private')) === 'public' ? 'public' : 'private';
    $defaultMode = (string) ($_POST['defaultProjectAccessMode'] ?? 'admin');
    $settings['defaultProjectAccessMode'] = isset($accessModes[$defaultMode]) ? $defaultMode : 'admin';
    $settings['onboardingRequired']  = isset($_POST['onboardingRequired']);
    $settings['allowPublicProjects'] = isset($_POST['allowPublicProjects']);
    $settings['githubEnabled']       = isset($_POST['githubEnabled']);
    $settings['vscodeEnabled']       = isset($_POST['vscodeEnabled']);
    $settings['chatgptEnabled']      = isset($_POST['chatgptEnabled']);
    $settings['codexEnabled']        = isset($_POST['codexEnabled']);
    $settings['stationHeading']      = trim((string) ($_POST['stationHeading'] ?? 'Deployment Station')) ?: 'Deployment Station';
    $settings['stationSubheading']   = trim((string) ($_POST['stationSubheading'] ?? ''));
    $settings['faviconUrl']          = trim((string) ($_POST['faviconUrl'] ?? ''));
    $settings['appIconUrl']          = trim((string) ($_POST['appIconUrl'] ?? ''));
    $settings['appIcon192Url']       = trim((string) ($_POST['appIcon192Url'] ?? ''));
    $settings['appIcon512Url']       = trim((string) ($_POST['appIcon512Url'] ?? ''));
    $settings['appMaskableIconUrl']  = trim((string) ($_POST['appMaskableIconUrl'] ?? ''));
    $settings['themeColor']          = station_normalize_theme_color((string) ($_POST['themeColor'] ?? '#2f7de2'));

    if (station_save_admin_settings($settings)) {
        station_log_event('admin.settings.updated', []);
        station_flash_set('ok', 'Settings saved.');
        header('Location: admin-settings.php');
        exit;
    }
    $error = 'Could not save settings.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('Admin Settings', 'Configure branding, icons, defaults, and integrations for the deployment station.') ?>
</head>
<body class="station-body">
  <main class="station-shell narrow">
    <header class="topbar card">
      <div class="topbar-brand">
        <p class="kicker"><?= station_h($uiConfig['heading']) ?></p>
        <h1>Admin Settings</h1>
      </div>
      <nav class="nav-pills">
        <a href="station.php">Dashboard</a>
        <a href="users.php">Users</a>
      </nav>
    </header>

    <?php if ($ok !== ''): ?><div class="alert ok"><?= station_h($ok) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert error"><?= station_h($error) ?></div><?php endif; ?>

    <form method="post" class="card form-grid">
      <h2>Branding</h2>
      <label>Station Heading (kicker text)
        <input type="text" name="stationHeading" value="<?= station_h($settings['stationHeading'] ?? 'Deployment Station') ?>" placeholder="Deployment Station">
      </label>
      <label>Sub-heading / tagline (optional — leave blank to show signed-in user)
        <input type="text" name="stationSubheading" value="<?= station_h($settings['stationSubheading'] ?? '') ?>" placeholder="Your projects, deployed.">
      </label>
      <label>Favicon URL (optional — paste a .ico or .png URL)
        <input type="url" name="faviconUrl" value="<?= station_h($settings['faviconUrl'] ?? '') ?>" placeholder="https://example.com/favicon.ico">
      </label>
      <label>PWA Icon URL (fallback for install prompts)
        <input type="url" name="appIconUrl" value="<?= station_h($settings['appIconUrl'] ?? '') ?>" placeholder="https://example.com/icon.png">
      </label>
      <label>PWA Icon 192x192 URL
        <input type="url" name="appIcon192Url" value="<?= station_h($settings['appIcon192Url'] ?? '') ?>" placeholder="https://example.com/icon-192.png">
      </label>
      <label>PWA Icon 512x512 URL
        <input type="url" name="appIcon512Url" value="<?= station_h($settings['appIcon512Url'] ?? '') ?>" placeholder="https://example.com/icon-512.png">
      </label>
      <label>Maskable Icon URL (optional)
        <input type="url" name="appMaskableIconUrl" value="<?= station_h($settings['appMaskableIconUrl'] ?? '') ?>" placeholder="https://example.com/icon-maskable-512.png">
      </label>
      <label>Theme Color
        <input type="color" name="themeColor" value="<?= station_h(station_normalize_theme_color((string) ($settings['themeColor'] ?? '#2f7de2'))) ?>">
      </label>

      <h2>Project Defaults</h2>
      <label>Default Project Visibility
        <select name="defaultProjectVisibility">
          <option value="private" <?= ($settings['defaultProjectVisibility'] ?? 'private') === 'private' ? 'selected' : '' ?>>Private</option>
          <option value="public"  <?= ($settings['defaultProjectVisibility'] ?? 'private') === 'public'  ? 'selected' : '' ?>>Public</option>
        </select>
      </label>
      <label>Default Project Access
        <select name="defaultProjectAccessMode">
          <?php foreach ($accessModes as $value => $label): ?>
            <option value="<?= station_h($value) ?>" <?= ($settings['defaultProjectAccessMode'] ?? 'admin') === $value ? 'selected' : '' ?>><?= station_h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><input type="checkbox" name="allowPublicProjects" <?= !empty($settings['allowPublicProjects']) ? 'checked' : '' ?>> Allow projects to be marked public</label>

      <h2>Onboarding</h2>
      <label><input type="checkbox" name="onboardingRequired" <?= !empty($settings['onboardingRequired']) ? 'checked' : '' ?>> Require onboarding on first login</label>

      <h2>Integration Availability</h2>
      <div class="grid-two">
        <label><input type="checkbox" name="githubEnabled"  <?= !empty($settings['githubEnabled'])  ? 'checked' : '' ?>> GitHub features</label>
        <label><input type="checkbox" name="vscodeEnabled"  <?= !empty($settings['vscodeEnabled'])  ? 'checked' : '' ?>> VS Code features</label>
        <label><input type="checkbox" name="chatgptEnabled" <?= !empty($settings['chatgptEnabled']) ? 'checked' : '' ?>> ChatGPT features</label>
        <label><input type="checkbox" name="codexEnabled"   <?= !empty($settings['codexEnabled'])   ? 'checked' : '' ?>> Codex features</label>
      </div>

      <button type="submit">Save Settings</button>
    </form>
  </main>
  <?= station_pwa_register_html() ?>
</body>
</html>
