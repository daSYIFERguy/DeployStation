<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

station_ensure_data_dir();
if (!station_is_setup_complete()) {
    header('Location: setup.php');
    exit;
}

$settings = station_admin_settings();
$uiConfig = station_ui_config();
$serverInfrastructure = station_normalize_server_infrastructure((string) ($settings['serverInfrastructure'] ?? 'apache'));
?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('Setup Complete', 'Review your server routing instructions and continue to sign in.') ?>
</head>
<body class="station-body">
  <main class="station-shell narrow">
    <header class="topbar card">
      <div class="topbar-brand">
        <p class="kicker"><?= station_h($uiConfig['heading']) ?></p>
        <h1>Setup Complete</h1>
      </div>
      <nav class="nav-pills">
        <a href="index.php">Continue to Sign In</a>
      </nav>
    </header>

    <?= station_flash_banners_html() ?>

    <section class="card form-grid">
      <h2>Server Infrastructure</h2>
      <p>You selected <strong><?= station_h($serverInfrastructure === 'nginx' ? 'Nginx' : 'Apache / LiteSpeed') ?></strong> during setup.</p>

      <?php if ($serverInfrastructure === 'nginx'): ?>
      <p>Add this routing block to your Nginx server config before signing off on production routing:</p>
      <label>Nginx Routing Snippet
        <textarea rows="12" readonly><?= station_h(station_nginx_project_route_snippet()) ?></textarea>
      </label>
      <?php else: ?>
      <p>Apache / LiteSpeed can use the Station-generated rewrite files under each project plus the secure root entry file.</p>
      <p>Place the secure root redirect example at <strong>/secure/index.php</strong> if you have not already done that.</p>
      <?php endif; ?>

      <div class="nav-pills">
        <a href="index.php">Go to Login</a>
        <?php if ($serverInfrastructure === 'nginx'): ?><a href="nginx-project-auth.php">Owner Nginx Helper</a><?php endif; ?>
      </div>
    </section>
  </main>
  <?= station_pwa_register_html() ?>
</body>
</html>