<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/github-auth.php';

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

$uiConfig = station_ui_config();
$appName = trim((string) ($uiConfig['appName'] ?? 'Deployment Station'));
$heading = trim((string) ($uiConfig['heading'] ?? 'Deployment Station'));
$admin = station_admin_settings();
$githubOAuthReady = !empty($admin['githubEnabled'])
    && trim((string) ($admin['githubOAuthClientId'] ?? '')) !== ''
    && trim((string) ($admin['githubOAuthClientSecret'] ?? '')) !== '';

$brandIconUrl = trim((string) ($uiConfig['faviconUrl'] ?? '')) !== ''
    ? trim((string) ($uiConfig['faviconUrl'] ?? ''))
    : trim((string) ($uiConfig['appIconUrl'] ?? ''));
$brandMark = $brandIconUrl !== ''
    ? '<img src="' . station_h(station_icon_url_with_cache_buster($brandIconUrl)) . '" alt="">'
    : station_h(mb_strtoupper(mb_substr($appName, 0, 1)));
?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html($appName . ' — Sign in', 'Sign in or request access to ' . $appName, 'assets/style.css?v=20260519d') ?>
  <link rel="stylesheet" href="assets/login.css?v=20260519c">
</head>
<body class="station-body login-page">
  <main class="login-shell">
    <div class="login-card">
      <div class="login-brand">
        <div class="login-brand-mark"><?= $brandMark ?></div>
        <h1><?= station_h($appName) ?></h1>
        <p><?= station_h($heading !== '' ? $heading : 'Sign in to manage deployments') ?></p>
      </div>

      <?= station_flash_banners_html() ?>
      <?php if ($error !== ''): ?>
        <div class="alert error"><?= station_h($error) ?></div>
      <?php endif; ?>

      <div class="login-tabs" role="tablist">
        <button type="button" class="login-tab active" data-login-tab="signin" role="tab" aria-selected="true">Sign in</button>
        <button type="button" class="login-tab" data-login-tab="request" role="tab" aria-selected="false">Request access</button>
      </div>

      <section class="login-panel active" id="login-panel-signin" role="tabpanel">
        <?php if ($githubOAuthReady): ?>
          <a class="login-github-btn" href="github-auth-start.php?purpose=login">
            <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0 0 24 12c0-6.63-5.37-12-12-12z"/></svg>
            Sign in with GitHub
          </a>
          <div class="login-divider">or</div>
        <?php endif; ?>

        <form method="post" class="login-form">
          <label>Username
            <input type="text" name="username" autocomplete="username" required>
          </label>
          <label>Password
            <input type="password" name="password" autocomplete="current-password" required>
          </label>
          <button type="submit" class="login-submit">Sign in</button>
        </form>
      </section>

      <section class="login-panel" id="login-panel-request" role="tabpanel" hidden>
        <p class="login-request-hint">
          Request access with GitHub. You will be added to a <strong>pending list</strong> — an owner reviews requests and will email credentials if approved. You do not get access immediately.
        </p>
        <?php if ($githubOAuthReady): ?>
          <form method="post" action="github-auth-start.php?purpose=request_access" class="login-form">
            <input type="hidden" name="purpose" value="request_access">
            <label>Your name
              <input type="text" name="name" required autocomplete="name" placeholder="Jane Smith">
            </label>
            <label>Email
              <input type="email" name="email" required autocomplete="email" placeholder="you@example.com">
            </label>
            <button type="submit" class="login-github-btn" style="border:0;">
              <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0 0 24 12c0-6.63-5.37-12-12-12z"/></svg>
              Continue with GitHub to request access
            </button>
          </form>
        <?php else: ?>
          <p class="login-note">GitHub sign-in is not configured yet. Ask the station owner to add OAuth credentials under Admin → GitHub.</p>
        <?php endif; ?>
      </section>

      <p class="login-note">Accounts are created by the station owner after review.</p>
    </div>
  </main>
  <script>
  (function () {
    var tabs = document.querySelectorAll('[data-login-tab]');
    var panels = {
      signin: document.getElementById('login-panel-signin'),
      request: document.getElementById('login-panel-request')
    };
    function show(name) {
      tabs.forEach(function (t) {
        var on = t.getAttribute('data-login-tab') === name;
        t.classList.toggle('active', on);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      Object.keys(panels).forEach(function (key) {
        var p = panels[key];
        if (!p) return;
        var on = key === name;
        p.classList.toggle('active', on);
        p.hidden = !on;
      });
    }
    tabs.forEach(function (t) {
      t.addEventListener('click', function () { show(t.getAttribute('data-login-tab')); });
    });
    if (location.hash === '#request-access') {
      show('request');
    }
  })();
  </script>
  <?= station_pwa_register_html() ?>
</body>
</html>
