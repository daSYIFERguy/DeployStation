<?php

declare(strict_types=1);

/** @var array{output?: string, truncated?: bool} $globalPsLines */
/** @var list<array{name: string, image: string, status: string, ports: string, running: bool}> $globalContainers */
/** @var bool $engineOk */

if ($globalContainers === []) {
    echo '<p class="setting-description">' . station_h($globalPsLines['output'] ?? 'No containers or Docker is unreachable.') . '</p>';

    return;
}
?>
<div style="overflow-x: auto;">
  <table class="docker-host-table" style="width:100%; border-collapse:collapse; font-size: 13px;">
    <thead>
      <tr style="text-align:left; border-bottom:1px solid var(--border,#e2e8f0);">
        <th style="padding:8px 6px;">Name</th>
        <th style="padding:8px 6px;">Image</th>
        <th style="padding:8px 6px;">Status</th>
        <th style="padding:8px 6px;">Ports</th>
        <th style="padding:8px 6px;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($globalContainers as $cRow): ?>
        <?php
          $cName = (string) ($cRow['name'] ?? '');
          $cRunning = !empty($cRow['running']);
          $confirmMsg = 'Remove container ' . $cName . '?'
              . ($cRunning ? ' It is running and will be stopped first.' : '')
              . ' This cannot be undone.';
        ?>
        <tr style="border-bottom:1px solid var(--border,#f1f5f9);">
          <td style="padding:8px 6px;"><code><?= station_h($cName) ?></code></td>
          <td style="padding:8px 6px;"><code style="word-break:break-all;"><?= station_h((string) ($cRow['image'] ?? '')) ?></code></td>
          <td style="padding:8px 6px;"><?= station_h((string) ($cRow['status'] ?? '')) ?></td>
          <td style="padding:8px 6px;"><code style="font-size:11px;"><?= station_h((string) ($cRow['ports'] ?? '—')) ?></code></td>
          <td style="padding:8px 6px;">
            <form method="post" action="admin-settings.php?tab=docker" class="docker-host-remove-form" style="margin:0;" onsubmit="return confirm(<?= json_encode($confirmMsg, JSON_THROW_ON_ERROR) ?>);">
              <input type="hidden" name="action" value="admin_docker_remove_container">
              <input type="hidden" name="container_name" value="<?= station_h($cName) ?>">
              <label style="display:flex; align-items:center; gap:6px; font-size:11px; margin-bottom:6px;">
                <input type="checkbox" name="confirm_remove_container" value="1" required>
                Confirm
              </label>
              <button type="submit" class="secondary-btn" style="font-size:12px; padding:6px 10px;" <?= !$engineOk ? 'disabled' : '' ?>>Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if (!empty($globalPsLines['truncated'])): ?>
  <p class="setting-description" style="margin-top:8px;"><strong>Table truncated</strong> for display.</p>
<?php endif; ?>
<div style="margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--border,#e2e8f0);">
  <p class="setting-description" style="margin-bottom: 10px;"><strong>Prune all stopped containers</strong> runs <code>docker container prune -f</code> on this host (every exited container, not only Station).</p>
  <form method="post" action="admin-settings.php?tab=docker" style="display:flex; flex-wrap:wrap; gap:12px; align-items:center;" onsubmit="return confirm('Delete ALL stopped containers on this host?');">
    <input type="hidden" name="action" value="admin_docker_prune_stopped">
    <label style="display:flex; align-items:center; gap:8px; font-size:13px;">
      <input type="checkbox" name="confirm_prune_stopped" value="1" required>
      I want to prune all stopped containers
    </label>
    <button type="submit" class="secondary-btn" <?= !$engineOk ? 'disabled' : '' ?>>Prune stopped containers</button>
  </form>
</div>
