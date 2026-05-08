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
$ok = station_flash_get('ok');
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
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= station_h($appName) ?> Login</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body class="station-body">
  <main class="station-shell narrow">
    <h1><?= station_h($appName) ?></h1>
    <p>Sign in to access the secured upload and deployment center.</p>
    <?php if ($ok !== ''): ?>
      <div class="alert ok"><?= station_h($ok) ?></div>
    <?php endif; ?>
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
</body>
</html>
