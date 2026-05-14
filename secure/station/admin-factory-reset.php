<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/factory-reset.php';

station_require_owner();

$armed = isset($_SESSION['factory_reset_armed']) && is_array($_SESSION['factory_reset_armed'])
    ? $_SESSION['factory_reset_armed']
    : null;
$armedAge = is_array($armed) && isset($armed['at']) ? (time() - (int) $armed['at']) : 999999;
if ($armed !== null && $armedAge > 600) {
    unset($_SESSION['factory_reset_armed']);
    $armed = null;
}

$step = (int) ($_GET['step'] ?? 1);
if ($step !== 1 && $step !== 2) {
    $step = 1;
}
if ($step === 2 && $armed === null) {
    station_flash_set('error', 'Start again from step 1 — confirmation session expired or missing.');
    header('Location: admin-factory-reset.php?step=1');
    exit;
}

$error = '';
$ok = station_flash_get('ok');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['factory_step'] ?? '') === '1') {
    $phrase = trim((string) ($_POST['confirm_phrase'] ?? ''));
    if ($phrase !== 'DELETE ALL STATION DATA') {
        $error = 'Confirmation phrase must be exactly: DELETE ALL STATION DATA';
    } elseif (empty($_POST['ack_station_data']) || empty($_POST['ack_project_files']) || empty($_POST['ack_docker'])) {
        $error = 'All three acknowledgement checkboxes are required.';
    } else {
        $_SESSION['factory_reset_armed'] = [
            'at' => time(),
            'opts' => [
                'docker_remove_volumes' => !empty($_POST['opt_docker_volumes']),
                'docker_prune_all_images' => !empty($_POST['opt_prune_images']),
            ],
        ];
        header('Location: admin-factory-reset.php?step=2');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['factory_step'] ?? '') === '2') {
    if ($armed === null || !isset($armed['opts']) || !is_array($armed['opts'])) {
        station_flash_set('error', 'Session expired. Start over from step 1.');
        header('Location: admin-factory-reset.php?step=1');
        exit;
    }
    if (empty($_POST['ack_final_execute'])) {
        $error = 'Check the final box to execute the factory reset.';
    } else {
        $opts = $armed['opts'];
        unset($_SESSION['factory_reset_armed']);
        $result = station_factory_reset_run([
            'docker_remove_volumes' => !empty($opts['docker_remove_volumes']),
            'docker_prune_all_images' => !empty($opts['docker_prune_all_images']),
        ]);
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        session_start();
        station_flash_set('ok', (string) ($result['message'] ?? 'Reset complete.'));
        header('Location: setup.php');
        exit;
    }
}

$optsPreview = is_array($armed) && isset($armed['opts']) && is_array($armed['opts']) ? $armed['opts'] : [];
?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('Factory reset', 'Irreversibly wipe Station data and project files.') ?>
</head>
<body class="station-body">
  <div class="dashboard-shell">
    <?= station_dashboard_nav_html('settings') ?>
    <main class="dashboard-main">
      <header class="dashboard-topbar">
        <div>
          <p class="dashboard-kicker">Owner only</p>
          <h1 class="dashboard-heading">Factory reset</h1>
          <p class="dashboard-subheading">This removes Station configuration, activity logs, credentials, nginx includes, and every project directory under <code><?= station_h(station_projects_dir()) ?></code>. Docker compose stacks are stopped first; optional flags remove volumes or prune images on the whole host.</p>
        </div>
        <nav class="nav-pills">
          <a href="admin-settings.php">← Admin settings</a>
        </nav>
      </header>

      <?php if ($ok !== ''): ?><div class="alert ok"><?= station_h($ok) ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="alert error"><?= station_h($error) ?></div><?php endif; ?>

      <?php if ($step === 1): ?>
        <div class="settings-panel" style="max-width: 720px;">
          <h2 class="settings-panel-heading" style="font-size:18px;">Step 1 — Review and arm</h2>
          <ul class="setting-description" style="line-height:1.5;">
            <li>Runs <code>docker compose down --remove-orphans</code> for each project folder that has a compose file (Docker must be reachable).</li>
            <li>Deletes every child directory under the projects root (except the reserved <code>station</code> name).</li>
            <li>Deletes everything under the Station data directory, then recreates an empty skeleton.</li>
            <li>Optional: <strong>Remove named volumes</strong> (<code>down -v</code>) — database data inside compose volumes is deleted.</li>
            <li>Optional: <strong>Prune all unused images</strong> on this host (<code>docker image prune -af</code>) — affects non-Station images too.</li>
          </ul>
          <form method="post" class="settings-form-group" style="margin-top:20px;">
            <input type="hidden" name="factory_step" value="1">
            <label class="feature-toggle" style="margin-bottom:12px;">
              <input type="checkbox" name="ack_station_data" value="1">
              <span>I understand all Station app data (users, settings, logs, secrets on disk) will be permanently deleted.</span>
            </label>
            <label class="feature-toggle" style="margin-bottom:12px;">
              <input type="checkbox" name="ack_project_files" value="1">
              <span>I understand every project directory under the projects root will be deleted from this server.</span>
            </label>
            <label class="feature-toggle" style="margin-bottom:12px;">
              <input type="checkbox" name="ack_docker" value="1">
              <span>I understand Docker stacks for those projects will be stopped and removed from the engine.</span>
            </label>
            <label class="feature-toggle" style="margin-bottom:12px;">
              <input type="checkbox" name="opt_docker_volumes" value="1">
              <span>Also run <code>docker compose down -v</code> (delete compose-managed volumes — destructive for databases).</span>
            </label>
            <label class="feature-toggle" style="margin-bottom:16px;">
              <input type="checkbox" name="opt_prune_images" value="1">
              <span>Also run <code>docker image prune -af</code> on this host (removes unused images system-wide).</span>
            </label>
            <label class="setting-label">Type the phrase <code>DELETE ALL STATION DATA</code></label>
            <input type="text" name="confirm_phrase" autocomplete="off" style="max-width:420px;" placeholder="DELETE ALL STATION DATA" required>
            <div style="margin-top:20px;">
              <button type="submit" class="secondary-btn">Continue to final confirmation</button>
            </div>
          </form>
        </div>
      <?php else: ?>
        <div class="settings-panel" style="max-width: 720px;">
          <h2 class="settings-panel-heading" style="font-size:18px;">Step 2 — Execute</h2>
          <p class="setting-description">You are about to run the reset with these options:</p>
          <ul>
            <li>Compose volumes removed: <strong><?= !empty($optsPreview['docker_remove_volumes']) ? 'yes' : 'no' ?></strong></li>
            <li>Docker image prune (-af): <strong><?= !empty($optsPreview['docker_prune_all_images']) ? 'yes' : 'no' ?></strong></li>
          </ul>
          <form method="post" style="margin-top:20px;">
            <input type="hidden" name="factory_step" value="2">
            <label class="feature-toggle" style="margin-bottom:16px;">
              <input type="checkbox" name="ack_final_execute" value="1" required>
              <span>I am certain — run the factory reset now and send me to the setup wizard.</span>
            </label>
            <button type="submit" style="background:#b91c1c;color:#fff;border:none;padding:10px 18px;border-radius:8px;cursor:pointer;">Execute factory reset</button>
          </form>
          <p style="margin-top:16px;"><a href="admin-factory-reset.php?step=1">← Start over</a></p>
        </div>
      <?php endif; ?>
    </main>
  </div>
  <?= station_pwa_register_html() ?>
</body>
</html>
