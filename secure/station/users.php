<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

station_require_login();

$currentUser = station_current_user();
$isOwner = station_is_owner($currentUser);
$isAdmin = station_is_admin($currentUser);

if (!$isAdmin) {
    station_flash_set('error', 'Admin access required.');
    header('Location: station.php');
    exit;
}

$usersTab = 'users';
if ($isOwner) {
    require_once __DIR__ . '/lib/access-requests.php';
    if (($_GET['tab'] ?? '') === 'requests') {
        $usersTab = 'requests';
        station_access_request_mark_all_seen();
        station_access_requests_clear_owner_alert_session();
    }
}

$cfg = station_config();
$users = isset($cfg['users']) && is_array($cfg['users']) ? $cfg['users'] : [];
$error = '';
$roleOptionsAssignable = [
    'admin' => 'Admin — manage users and projects',
    'builder' => 'Builder — create and deploy projects',
    'viewer' => 'Viewer — read-only',
];
$roleOptionsOwner = [
    'owner' => 'Owner — full station control (Settings, host health, factory reset)',
] + $roleOptionsAssignable;

/**
 * @param array<string, string> $allowed
 */
function station_users_normalize_role(string $role, array $allowed, string $fallback = 'viewer'): string
{
    return isset($allowed[$role]) ? $role : $fallback;
}

function station_users_is_protected_owner_account(array $user): bool
{
    return (string) ($user['role'] ?? '') === 'owner';
}

$editUsername = station_safe_name((string) ($_GET['edit'] ?? ''));
$editingUser = null;
if ($editUsername !== '') {
    foreach ($users as $candidate) {
        if (is_array($candidate) && (string) ($candidate['username'] ?? '') === $editUsername) {
            $editingUser = $candidate;
            break;
        }
    }
}

function station_can_manage_target(bool $isOwner, string $targetUsername): bool
{
    if ($targetUsername === '') {
        return false;
    }
    if ($targetUsername === 'root' && !$isOwner) {
        return false;
    }
    return true;
}

  function station_can_delete_target(bool $isOwner, string $currentUsername, string $targetUsername): bool
  {
    if (!station_can_manage_target($isOwner, $targetUsername)) {
      return false;
    }
    if ($targetUsername === 'root') {
      return false;
    }
    if ($targetUsername === $currentUsername) {
      return false;
    }
    return true;
  }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $currentUsername = (string) ($currentUser['username'] ?? '');

    if ($action === 'access_request' && $isOwner) {
        $id = (string) ($_POST['id'] ?? '');
        $sub = (string) ($_POST['access_subaction'] ?? '');
        if ($id !== '' && $sub === 'dismiss') {
            $r = station_access_request_update_status($id, 'dismissed');
            station_flash_set(!empty($r['ok']) ? 'ok' : 'error', (string) ($r['message'] ?? 'Update failed.'));
        } elseif ($id !== '' && $sub === 'approve') {
            $r = station_access_request_update_status($id, 'approved');
            station_flash_set(!empty($r['ok']) ? 'ok' : 'error', (string) ($r['message'] ?? 'Update failed.'));
        }
        header('Location: users.php?tab=requests');
        exit;
    }

    if ($action === 'add_user') {
        $username = station_safe_name((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = station_users_normalize_role((string) ($_POST['role'] ?? 'viewer'), $roleOptionsAssignable, 'viewer');
        if ($role === 'owner') {
            $role = 'admin';
        }

        if ($username === '' || strlen($password) < 8) {
            $error = 'Username required and password must be at least 8 chars.';
        } else {
            foreach ($users as $u) {
                if ((string) ($u['username'] ?? '') === $username) {
                    $error = 'User already exists.';
                    break;
                }
            }

            if ($error === '') {
                $users[] = [
                    'username' => $username,
                    'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => $role,
                    'active' => true,
                    'createdAt' => gmdate('c'),
                    'lastLoginAt' => '',
                    'createdBy' => (string) ($_SESSION['station_user'] ?? 'admin')
                ];
                $cfg['users'] = $users;
                station_save_config($cfg);
                station_log_event('user.added', ['username' => $username]);
                station_flash_set('ok', 'User added: ' . $username);
                header('Location: users.php');
                exit;
            }
        }
    }

    if ($action === 'update_user') {
        $target = station_safe_name((string) ($_POST['target_username'] ?? ''));
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $postedRole = (string) ($_POST['role'] ?? '');

        if (!station_can_manage_target($isOwner, $target)) {
            $error = 'You are not allowed to edit that user.';
        } else {
            $updated = false;
            foreach ($users as $idx => $u) {
                if ((string) ($u['username'] ?? '') !== $target) {
                    continue;
                }

                $existingRole = (string) ($u['role'] ?? 'viewer');
                if (station_users_is_protected_owner_account($u)) {
                    $users[$idx]['role'] = 'owner';
                } elseif ($isOwner) {
                    $users[$idx]['role'] = station_users_normalize_role($postedRole, $roleOptionsOwner, $existingRole);
                } else {
                    $users[$idx]['role'] = station_users_normalize_role($postedRole, $roleOptionsAssignable, $existingRole);
                }
                $newRole = (string) ($users[$idx]['role'] ?? $existingRole);
                if ($newPassword !== '') {
                    if (strlen($newPassword) < 8) {
                        $error = 'New password must be at least 8 chars.';
                        break;
                    }
                    $users[$idx]['passwordHash'] = password_hash($newPassword, PASSWORD_DEFAULT);
                    station_log_event('user.password.reset', ['username' => $target]);
                }
                $users[$idx]['updatedAt'] = gmdate('c');
                $updated = true;
                break;
            }

            if ($error === '' && $updated) {
                $cfg['users'] = $users;
                station_save_config($cfg);
                station_log_event('user.updated', ['username' => $target, 'role' => $newRole]);
                station_flash_set('ok', 'Updated user: ' . $target);
                header('Location: users.php?edit=' . rawurlencode($target));
                exit;
            }

            if ($error === '' && !$updated) {
                $error = 'User not found.';
            }
        }
    }

        if ($action === 'delete_user') {
          $target = station_safe_name((string) ($_POST['target_username'] ?? ''));

          if (!station_can_delete_target($isOwner, $currentUsername, $target)) {
            $error = 'You are not allowed to delete that user.';
          } else {
            $updatedUsers = [];
            $deleted = false;
            foreach ($users as $user) {
              if (!is_array($user)) {
                $updatedUsers[] = $user;
                continue;
              }

              if ((string) ($user['username'] ?? '') === $target) {
                $deleted = true;
                continue;
              }

              $updatedUsers[] = $user;
            }

            if ($deleted) {
              $cfg['users'] = $updatedUsers;
              station_save_config($cfg);
              station_log_event('user.deleted', ['username' => $target]);
              station_flash_set('ok', 'Deleted user: ' . $target);
              header('Location: users.php');
              exit;
            }

            $error = 'User not found.';
          }
        }
}

$accessRequests = [];
$accessPendingCount = 0;
if ($isOwner) {
    $accessRequests = station_access_requests_load();
    usort($accessRequests, static function (array $a, array $b): int {
        return strcmp((string) ($b['requestedAt'] ?? ''), (string) ($a['requestedAt'] ?? ''));
    });
    $accessPendingCount = station_access_requests_pending_count();
}
?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('User Admin', 'Manage station users, roles, and passwords.') ?>
</head>
<body class="station-body">
  <div class="dashboard-shell">
    <?= station_dashboard_nav_html('users') ?>
    <main class="dashboard-main">
      <div class="station-shell">
    <header class="topbar card">
      <div>
        <p class="kicker"><?= $isOwner ? 'Owner Console' : 'Admin Console' ?></p>
        <h1>User Admin</h1>
        <p>Click a user to edit role and password. The setup account keeps the <strong>owner</strong> role (Settings, host health). Admins cannot edit owner accounts.</p>
      </div>
      <nav class="nav-pills">
        <a href="station.php">Dashboard</a>
        <a href="logout.php">Logout</a>
      </nav>
    </header>

    <?= station_flash_banners_html() ?>
    <?php if ($error !== ''): ?><div class="alert error"><?= station_h($error) ?></div><?php endif; ?>

    <?php if ($isOwner): ?>
      <nav class="users-admin-tabs" role="tablist" aria-label="User administration">
        <a href="users.php" class="users-admin-tab <?= $usersTab === 'users' ? 'active' : '' ?>" role="tab" aria-selected="<?= $usersTab === 'users' ? 'true' : 'false' ?>">Users</a>
        <a href="users.php?tab=requests" class="users-admin-tab <?= $usersTab === 'requests' ? 'active' : '' ?>" role="tab" aria-selected="<?= $usersTab === 'requests' ? 'true' : 'false' ?>">
          Access requests<?php if ($accessPendingCount > 0): ?> <span class="users-admin-tab-badge"><?= (int) $accessPendingCount ?></span><?php endif; ?>
        </a>
      </nav>
    <?php endif; ?>

    <?php if ($usersTab === 'requests' && $isOwner): ?>
      <?php require __DIR__ . '/partials/users-access-requests-panel.php'; ?>
    <?php else: ?>

    <section class="grid-two">
      <form class="card form-grid" method="post">
        <h2>Add User</h2>
        <input type="hidden" name="action" value="add_user">
        <label>Username
          <input type="text" name="username" required>
        </label>
        <label>Password
          <input type="password" name="password" required>
        </label>
        <label>Role
          <select name="role">
            <?php foreach ($roleOptionsAssignable as $value => $label): ?>
              <option value="<?= station_h($value) ?>"><?= station_h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit">Add User</button>
      </form>

      <form class="card form-grid" method="post">
        <h2>Edit Selected User</h2>
        <input type="hidden" name="action" value="update_user">
        <label>Selected User
          <input type="text" name="target_username" value="<?= station_h((string) ($editingUser['username'] ?? '')) ?>" readonly required>
        </label>
        <?php
          $editingIsOwner = is_array($editingUser) && station_users_is_protected_owner_account($editingUser);
          $editRoleOptions = $isOwner ? $roleOptionsOwner : $roleOptionsAssignable;
          $selectedRole = (string) ($editingUser['role'] ?? 'viewer');
        ?>
        <label>Role
          <?php if ($editingIsOwner): ?>
            <input type="hidden" name="role" value="owner">
            <p class="setting-description" style="margin:8px 0 0;"><strong>Owner</strong> — full station control. Role cannot be lowered when changing password.</p>
          <?php else: ?>
            <select name="role">
              <?php foreach ($editRoleOptions as $value => $label): ?>
                <option value="<?= station_h($value) ?>" <?= $selectedRole === $value ? 'selected' : '' ?>><?= station_h($label) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        </label>
        <label>New Password (optional)
          <input type="password" name="new_password" placeholder="Leave blank to keep current password">
        </label>
        <button type="submit" <?= $editingUser ? '' : 'disabled' ?>>Save User</button>
      </form>
    </section>

    <section class="card">
      <h2>Current Users (Click Edit)</h2>
      <table>
        <thead><tr><th>Username</th><th>Role</th><th>Created</th><th>Last Login</th><th>By</th><th>Edit</th><th>Delete</th></tr></thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <?php
              $username = (string) ($u['username'] ?? '');
              $lastLogin = trim((string) ($u['lastLoginAt'] ?? ''));
              $canEdit = station_can_manage_target($isOwner, $username);
              $canDelete = station_can_delete_target($isOwner, (string) ($currentUser['username'] ?? ''), $username);
            ?>
            <tr>
              <td><?= station_h($username) ?></td>
              <td><?= station_h(match ((string) ($u['role'] ?? 'admin')) {
                  'owner' => 'owner (superuser)',
                  'admin' => 'admin',
                  'builder' => 'builder',
                  'viewer' => 'viewer',
                  default => (string) ($u['role'] ?? 'admin'),
              }) ?></td>
              <td><?= station_h((string) ($u['createdAt'] ?? '')) ?></td>
              <td><?= station_h($lastLogin !== '' ? $lastLogin : 'never') ?></td>
              <td><?= station_h((string) ($u['createdBy'] ?? '')) ?></td>
              <td>
                <?php if ($canEdit): ?>
                  <a href="users.php?edit=<?= urlencode($username) ?>">Edit</a>
                <?php else: ?>
                  <span class="file-meta">Root locked</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($canDelete): ?>
                  <form method="post" onsubmit="return confirm('Delete user <?= station_h($username) ?>? This cannot be undone.');">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="target_username" value="<?= station_h($username) ?>">
                    <button type="submit" class="danger-link">Delete</button>
                  </form>
                <?php else: ?>
                  <span class="file-meta"><?= $username === 'root' ? 'Root locked' : 'Protected' ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <?php endif; ?>

      </div>
    </main>
  </div>
  <?= station_dashboard_nav_script_html() ?>
  <?= station_clipboard_fab_html() ?>
  <?= station_pwa_register_html() ?>
</body>
</html>
