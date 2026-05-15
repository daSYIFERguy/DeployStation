<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $accessRequests */
/** @var int $accessPendingCount */

$accessRequests = $accessRequests ?? [];
$accessPendingCount = (int) ($accessPendingCount ?? 0);
?>
<section class="admin-panel users-requests-panel">
  <p class="setting-description" style="margin-top:0;">
    People who signed in with GitHub to request access appear here. Create accounts manually in the
    <strong>Users</strong> tab and share credentials when you approve them.
  </p>
  <?php if ($accessPendingCount > 0): ?>
    <p style="margin:12px 0 0;"><span class="chip chip-off"><?= (int) $accessPendingCount ?> pending</span></p>
  <?php endif; ?>

  <?php if ($accessRequests === []): ?>
    <p class="setting-description" style="margin-top:16px;">No access requests yet.</p>
  <?php else: ?>
    <div class="users-requests-table-wrap" style="overflow-x:auto;margin-top:16px;">
      <table class="users-requests-table" style="width:100%;border-collapse:collapse;font-size:14px;">
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
          <?php foreach ($accessRequests as $req): ?>
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
                    <input type="hidden" name="action" value="access_request">
                    <input type="hidden" name="id" value="<?= station_h($id) ?>">
                    <button type="submit" name="access_subaction" value="approve" class="secondary-btn">Mark approved</button>
                    <button type="submit" name="access_subaction" value="dismiss" class="danger-btn">Dismiss</button>
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
