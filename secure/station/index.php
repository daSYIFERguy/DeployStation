<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

station_ensure_data_dir();
if (!station_is_setup_complete()) {
    header('Location: setup.php');
    exit;
}

if (station_current_user()) {
    header('Location: station.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = station_safe_name((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } elseif (!station_login($username, $password)) {
        $error = 'Invalid credentials.';
    } else {
      if (station_user_needs_onboarding($username)) {
        header('Location: onboarding.php');
        exit;
      }
        header('Location: station.php');
        exit;
    }
}

$appName = (string) (station_config()['appName'] ?? 'Micro Deployment Station');
?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html($appName . ' Login', 'Sign in to access the secured upload and deployment center.') ?>
</head>
<body class="station-body">
  <main class="station-shell narrow">
    <h1><?= station_h($appName) ?></h1>
    <p>Sign in to access the secured upload and deployment center.</p>
    <?= station_flash_banners_html() ?>
    <?php if ($error !== ''): ?>
      <div class="alert error"><?= station_h($error) ?></div>
    <?php endif; ?>
    <form method="post" class="card form-grid">
      <label>Username
        <input type="text" name="username" required>
      </label>
      <label>Password
        <input type="password" name="password" required>
      </label>
      <button type="submit">Sign In</button>
    </form>
  </main>
  <?= station_pwa_register_html() ?>
</body>
</html>
