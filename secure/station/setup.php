<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

station_ensure_data_dir();
if (station_is_setup_complete()) {
    header('Location: index.php');
    exit;
}

$error = '';
$appNameInput = 'Micro Deployment Station';
$stationHeadingInput = 'Deployment Station';
$stationSubheadingInput = '';
$faviconUrlInput = '';
$appIconUrlInput = '';
$appIcon192UrlInput = '';
$appIcon512UrlInput = '';
$appMaskableIconUrlInput = '';
$themeColorInput = '#2f7de2';
$serverInfrastructureInput = 'apache';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appName = trim((string) ($_POST['app_name'] ?? 'Micro Deployment Station'));
    $stationHeading = trim((string) ($_POST['station_heading'] ?? 'Deployment Station'));
    $stationSubheading = trim((string) ($_POST['station_subheading'] ?? ''));
    $faviconUrl = trim((string) ($_POST['faviconUrl'] ?? ''));
    $appIconUrl = trim((string) ($_POST['appIconUrl'] ?? ''));
    $appIcon192Url = trim((string) ($_POST['appIcon192Url'] ?? ''));
    $appIcon512Url = trim((string) ($_POST['appIcon512Url'] ?? ''));
    $appMaskableIconUrl = trim((string) ($_POST['appMaskableIconUrl'] ?? ''));
    $themeColor = station_normalize_theme_color((string) ($_POST['theme_color'] ?? '#2f7de2'));
    $serverInfrastructure = station_normalize_server_infrastructure((string) ($_POST['server_infrastructure'] ?? 'apache'));
    $owner = 'root';
    $password = (string) ($_POST['owner_password'] ?? '');
    $confirm = (string) ($_POST['owner_password_confirm'] ?? '');

    $appNameInput = $appName !== '' ? $appName : 'Micro Deployment Station';
    $stationHeadingInput = $stationHeading !== '' ? $stationHeading : 'Deployment Station';
    $stationSubheadingInput = $stationSubheading;
    $faviconUrlInput = $faviconUrl;
    $appIconUrlInput = $appIconUrl;
    $appIcon192UrlInput = $appIcon192Url;
    $appIcon512UrlInput = $appIcon512Url;
    $appMaskableIconUrlInput = $appMaskableIconUrl;
    $themeColorInput = $themeColor;
    $serverInfrastructureInput = $serverInfrastructure;

    if ($owner === '') {
        $error = 'Owner username is required.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $cfg = [
            'appName' => $appName !== '' ? $appName : 'Micro Deployment Station',
            'createdAt' => gmdate('c'),
            'users' => [
                [
                    'username' => $owner,
                    'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => 'owner',
                    'active' => true,
                    'createdAt' => gmdate('c'),
                    'lastLoginAt' => '',
                    'createdBy' => 'setup'
                ]
            ]
        ];

        $settings = station_admin_settings();
    $settings['stationHeading'] = $stationHeading !== '' ? $stationHeading : 'Deployment Station';
    $settings['stationSubheading'] = $stationSubheading;
    $settings['faviconUrl'] = $faviconUrl;
    $settings['appIconUrl'] = $appIconUrl;
    $settings['appIcon192Url'] = $appIcon192Url;
    $settings['appIcon512Url'] = $appIcon512Url;
    $settings['appMaskableIconUrl'] = $appMaskableIconUrl;
    $settings['themeColor'] = $themeColor;
    $settings['serverInfrastructure'] = $serverInfrastructure;

    $iconResult = station_apply_brand_icon_inputs($settings, $_POST, $_FILES);
    if (empty($iconResult['ok'])) {
      $error = (string) ($iconResult['message'] ?? 'Could not save uploaded icons.');
    } else {
      $settings = (array) ($iconResult['settings'] ?? $settings);
    }

    if ($error === '' && ($settings['appIconUrl'] ?? '') === '' && ($settings['faviconUrl'] ?? '') !== '') {
      $settings['appIconUrl'] = (string) $settings['faviconUrl'];
        }

        if ($error === '' && station_save_config($cfg) && station_save_projects_meta(['projects' => []]) && station_save_admin_settings($settings)) {
          station_flash_set('ok', 'Setup complete. Review your server routing and then sign in.');
          header('Location: setup-finish.php');
            exit;
        }
    if ($error === '') {
      $error = 'Failed to write config files. Check write permissions.';
    }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('First Run Setup', 'Create the owner account and static admin password for this deployment station.') ?>
</head>
<body class="station-body">
  <main class="station-shell narrow">
    <h1>First Run Setup</h1>
    <p>Set the initial station branding and create the root account for this deployment station.</p>
    <?php if ($error !== ''): ?>
      <div class="alert error"><?= station_h($error) ?></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="card form-grid">
      <h2>Station Branding</h2>
      <label>System Name
        <input type="text" name="app_name" value="<?= station_h($appNameInput) ?>" required>
      </label>
      <label>Station Heading
        <input type="text" name="station_heading" value="<?= station_h($stationHeadingInput) ?>" required>
      </label>
      <label>Station Subheading
        <input type="text" name="station_subheading" value="<?= station_h($stationSubheadingInput) ?>" placeholder="Your projects, deployed.">
      </label>
      <label>Default Theme Color
        <input type="color" name="theme_color" value="<?= station_h($themeColorInput) ?>">
      </label>
      <label>Production Web Server
        <select name="server_infrastructure">
          <option value="apache" <?= $serverInfrastructureInput === 'apache' ? 'selected' : '' ?>>Apache / LiteSpeed</option>
          <option value="nginx" <?= $serverInfrastructureInput === 'nginx' ? 'selected' : '' ?>>Nginx</option>
        </select>
      </label>
      <label>Favicon URL
        <input type="url" name="faviconUrl" value="<?= station_h($faviconUrlInput) ?>" placeholder="https://example.com/favicon.ico">
      </label>
      <label>Upload Favicon
        <input type="file" name="faviconFile" accept=".ico,.png,.jpg,.jpeg,.webp,image/x-icon,image/png,image/jpeg,image/webp">
      </label>
      <label>PWA Fallback Icon URL
        <input type="url" name="appIconUrl" value="<?= station_h($appIconUrlInput) ?>" placeholder="https://example.com/icon.png">
      </label>
      <label>Upload PWA Fallback Icon
        <input type="file" name="appIconFile" accept=".ico,.png,.jpg,.jpeg,.webp,image/x-icon,image/png,image/jpeg,image/webp">
      </label>
      <label>PWA Icon 192x192 URL
        <input type="url" name="appIcon192Url" value="<?= station_h($appIcon192UrlInput) ?>" placeholder="https://example.com/icon-192.png">
      </label>
      <label>Upload 192x192 Icon
        <input type="file" name="appIcon192File" accept=".ico,.png,.jpg,.jpeg,.webp,image/x-icon,image/png,image/jpeg,image/webp">
      </label>
      <label>PWA Icon 512x512 URL
        <input type="url" name="appIcon512Url" value="<?= station_h($appIcon512UrlInput) ?>" placeholder="https://example.com/icon-512.png">
      </label>
      <label>Upload 512x512 Icon
        <input type="file" name="appIcon512File" accept=".ico,.png,.jpg,.jpeg,.webp,image/x-icon,image/png,image/jpeg,image/webp">
      </label>
      <label>Maskable Icon URL
        <input type="url" name="appMaskableIconUrl" value="<?= station_h($appMaskableIconUrlInput) ?>" placeholder="https://example.com/icon-maskable-512.png">
      </label>
      <label>Upload Maskable Icon
        <input type="file" name="appMaskableIconFile" accept=".ico,.png,.jpg,.jpeg,.webp,image/x-icon,image/png,image/jpeg,image/webp">
      </label>

      <h2>Root Account</h2>
      <label>Owner Username
        <input type="text" value="root" readonly>
      </label>
      <label>Owner Password
        <input type="password" name="owner_password" required>
      </label>
      <label>Confirm Password
        <input type="password" name="owner_password_confirm" required>
      </label>
      <button type="submit">Complete Setup</button>
    </form>
  </main>
  <?= station_pwa_register_html() ?>
</body>
</html>
