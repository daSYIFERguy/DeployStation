<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

station_require_login();

$project = station_safe_name((string) ($_GET['project'] ?? ''));
$appName = (string) (station_config()['appName'] ?? 'Deployment Station');
?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('Access Not Allowed', 'You are signed in, but this project is not available with your current permissions.') ?>
</head>
<body class="station-body">
  <main class="station-shell narrow">
    <div class="card form-grid">
      <p class="kicker">Access blocked</p>
      <h1>Not Allowed</h1>
      <p>
        You are signed in to <?= station_h($appName) ?>, but you do not have permission to open
        <?= $project !== '' ? '<strong>' . station_h($project) . '</strong>' : 'this project' ?>.
      </p>
      <p>Ask the owner to change the project access level or grant you the correct role.</p>
      <div class="nav-pills">
        <a href="station.php">Back to Dashboard</a>
        <a href="user-settings.php">User Settings</a>
      </div>
    </div>
  </main>
  <?= station_pwa_register_html() ?>
</body>
</html>