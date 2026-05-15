<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/access-requests.php';

station_require_owner();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id = (string) ($_POST['id'] ?? '');

    if ($action === 'dismiss' && $id !== '') {
        $r = station_access_request_update_status($id, 'dismissed');
        station_flash_set(!empty($r['ok']) ? 'ok' : 'error', (string) ($r['message'] ?? 'Update failed.'));
    } elseif ($action === 'approve' && $id !== '') {
        $r = station_access_request_update_status($id, 'approved');
        station_flash_set(!empty($r['ok']) ? 'ok' : 'error', (string) ($r['message'] ?? 'Update failed.'));
    }

    header('Location: access-requests.php');
    exit;
}

station_access_request_mark_all_seen();

$requests = station_access_requests_load();
usort($requests, static function (array $a, array $b): int {
    return strcmp((string) ($b['requestedAt'] ?? ''), (string) ($a['requestedAt'] ?? ''));
});

$pendingCount = station_access_requests_pending_count();
$uiConfig = station_ui_config();
$appName = trim((string) ($uiConfig['appName'] ?? 'Deployment Station'));
?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('Access requests', 'Review pending access requests for ' . $appName, 'assets/style.css?v=20260519f') ?>
</head>
<body class="station-body">
  <div class="dashboard-shell">
    <?= station_dashboard_nav_html('access_requests') ?>

    <main class="dashboard-main">
      <header class="dashboard-topbar">
        <div>
          <p class="dashboard-kicker">Owner</p>
          <h1 class="dashboard-heading">Access requests</h1>
          <p class="dashboard-subheading">
            People who requested access via GitHub appear here. Create accounts manually in
            <a href="users.php">Users</a> and email credentials — approving here only marks the request reviewed.
          </p>
        </div>
        <?php if ($pendingCount > 0): ?>
          <span class="chip chip-on"><?= (int) $pendingCount ?> pending</span>
        <?php endif; ?>
      </header>

      <?= station_flash_banners_html() ?>

      <section class="admin-panel">
        <?php if ($requests === []): ?>
          <p class="setting-description">No access requests yet.</p>
        <?php else: ?>
          <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
              <thead>
                <tr style="text-align:left;border-bottom:1px solid var(--line);">
                  <th style="padding:10px 8px;">When</th>
                  <th style="padding:10px 8px;">Name</th>
                  <th style="padding:10px 8px;">Email</th>
                  <th style="padding:10px 8px;">GitHub</th>
                  <th style="padding:10px 8px;">Status</th>
                  <th style="padding:10px 8px;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($requests as $req): ?>
                  <?php
                    $status = (string) ($req['status'] ?? 'pending');
                    $id = (string) ($req['id'] ?? '');
                    $gh = (string) ($req['githubLogin'] ?? '');
                  ?>
                  <tr style="border-bottom:1px solid var(--line);">
                    <td style="padding:10px 8px;white-space:nowrap;font-size:12px;color:var(--muted);">
                      <?= station_h((string) ($req['requestedAt'] ?? '')) ?>
                    </td>
                    <td style="padding:10px 8px;"><?= station_h((string) ($req['name'] ?? '')) ?></td>
                    <td style="padding:10px 8px;">
                      <a href="mailto:<?= station_h((string) ($req['email'] ?? '')) ?>"><?= station_h((string) ($req['email'] ?? '')) ?></a>
                    </td>
                    <td style="padding:10px 8px;">
                      <?php if ($gh !== ''): ?>
                        <a href="https://github.com/<?= station_h($gh) ?>" target="_blank" rel="noreferrer">@<?= station_h($gh) ?></a>
                      <?php endif; ?>
                    </td>
                    <td style="padding:10px 8px;">
                      <span class="chip <?= $status === 'pending' ? 'chip-off' : 'chip-on' ?>"><?= station_h($status) ?></span>
                    </td>
                    <td style="padding:10px 8px;">
                      <?php if ($status === 'pending' && $id !== ''): ?>
                        <form method="post" style="display:inline-flex;gap:8px;flex-wrap:wrap;">
                          <input type="hidden" name="id" value="<?= station_h($id) ?>">
                          <button type="submit" name="action" value="approve" class="secondary-btn">Mark approved</button>
                          <button type="submit" name="action" value="dismiss" class="danger-btn">Dismiss</button>
                        </form>
                      <?php else: ?>
                        —
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    </main>
  </div>
  <?= station_dashboard_nav_script_html() ?>
  <?= station_pwa_register_html() ?>
</body>
</html>
