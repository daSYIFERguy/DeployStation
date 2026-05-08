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

$cfg = station_config();
$users = isset($cfg['users']) && is_array($cfg['users']) ? $cfg['users'] : [];
$error = '';
$ok = station_flash_get('ok');
$roleOptions = [
    'admin' => 'Admin - All Access',
    'builder' => 'Builder - Can See and Create',
    'viewer' => 'Viewer - Can Only See'
];

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_user') {
        $username = station_safe_name((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? 'viewer');
        $role = isset($roleOptions[$role]) ? $role : 'viewer';

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
        $newRole = (string) ($_POST['role'] ?? 'viewer');
        $newRole = isset($roleOptions[$newRole]) ? $newRole : 'viewer';
        $newPassword = (string) ($_POST['new_password'] ?? '');

        if (!station_can_manage_target($isOwner, $target)) {
            $error = 'You are not allowed to edit that user.';
        } else {
            $updated = false;
            foreach ($users as $idx => $u) {
                if ((string) ($u['username'] ?? '') !== $target) {
                    continue;
                }

                $users[$idx]['role'] = $newRole;
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
}
?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('User Admin', 'Manage station users, roles, and passwords.') ?>
</head>
<body class="station-body">
  <main class="station-shell">
    <header class="topbar card">
      <div>
        <p class="kicker"><?= $isOwner ? 'Owner Console' : 'Admin Console' ?></p>
        <h1>User Admin</h1>
        <p>Click a user to edit role and password. Admins can manage all users except root.</p>
      </div>
      <nav class="nav-pills">
        <a href="station.php">Dashboard</a>
        <a href="logout.php">Logout</a>
      </nav>
    </header>

    <?php if ($ok !== ''): ?><div class="alert ok"><?= station_h($ok) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert error"><?= station_h($error) ?></div><?php endif; ?>

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
            <?php foreach ($roleOptions as $value => $label): ?>
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
        <label>Role
          <select name="role">
            <?php $selectedRole = (string) ($editingUser['role'] ?? 'viewer'); ?>
            <?php foreach ($roleOptions as $value => $label): ?>
              <option value="<?= station_h($value) ?>" <?= $selectedRole === $value ? 'selected' : '' ?>><?= station_h($label) ?></option>
            <?php endforeach; ?>
          </select>
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
        <thead><tr><th>Username</th><th>Role</th><th>Created</th><th>Last Login</th><th>By</th><th>Edit</th></tr></thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <?php
              $username = (string) ($u['username'] ?? '');
              $lastLogin = trim((string) ($u['lastLoginAt'] ?? ''));
              $canEdit = station_can_manage_target($isOwner, $username);
            ?>
            <tr>
              <td><?= station_h($username) ?></td>
              <td><?= station_h((string) ($u['role'] ?? 'admin')) ?></td>
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
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  </main>
  <?= station_pwa_register_html() ?>
</body>
</html>
