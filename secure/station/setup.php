<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

station_ensure_data_dir();
if (station_is_setup_complete()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appName = trim((string) ($_POST['app_name'] ?? 'Micro Deployment Station'));
  $owner = 'root';
    $password = (string) ($_POST['owner_password'] ?? '');
    $confirm = (string) ($_POST['owner_password_confirm'] ?? '');

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

        if (station_save_config($cfg) && station_save_projects_meta(['projects' => []])) {
            station_flash_set('ok', 'Setup complete. Please sign in.');
            header('Location: index.php');
            exit;
        }
        $error = 'Failed to write config files. Check write permissions.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>First Run Setup</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body class="station-body">
  <main class="station-shell narrow">
    <h1>First Run Setup</h1>
    <p>Create the owner account and static admin password for this deployment station.</p>
    <?php if ($error !== ''): ?>
      <div class="alert error"><?= station_h($error) ?></div>
    <?php endif; ?>
    <form method="post" class="card form-grid">
      <label>Station Name
        <input type="text" name="app_name" value="Micro Deployment Station" required>
      </label>
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
</body>
</html>
