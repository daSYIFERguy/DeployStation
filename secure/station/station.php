<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/templates.php';

station_require_login();

$user        = station_current_user();
$isOwner     = station_is_owner($user);
$isAdmin     = station_is_admin($user);
$canBuild    = station_can_build($user);
$profile     = station_current_user_profile();
$adminSettings = station_admin_settings();
$uiConfig    = station_ui_config();

if (station_user_needs_onboarding(station_current_username())) {
    header('Location: onboarding.php');
    exit;
}

$githubReady  = !empty($adminSettings['githubEnabled'])  && station_integration_ready($profile, 'github');
$vscodeReady  = !empty($adminSettings['vscodeEnabled'])  && station_integration_ready($profile, 'vscode');
$chatgptReady = !empty($adminSettings['chatgptEnabled']) && station_integration_ready($profile, 'chatgpt');
$codexReady   = !empty($adminSettings['codexEnabled'])   && station_integration_ready($profile, 'codex');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update_station_name' && $isOwner) {
        $name = (string) ($_POST['station_name'] ?? '');
        station_update_app_name($name)
            ? station_flash_set('ok', 'Station name updated.')
            : station_flash_set('error', 'Could not update name.');
        station_log_event('station.name.updated', ['name' => $name]);
        header('Location: station.php'); exit;
    }

    if ($action === 'rename_station_dir' && $isOwner) {
        $result = station_rename_station_dir((string) ($_POST['station_dir_name'] ?? ''));
        if (!empty($result['ok'])) {
            $dir = (string) ($result['dir'] ?? 'station');
            station_log_event('station.dir.renamed', ['dir' => $dir]);
            station_flash_set('ok', 'Station renamed. Reopen at /secure/' . $dir . '/');
            header('Location: ../' . rawurlencode($dir) . '/index.php'); exit;
        }
        station_flash_set('error', (string) ($result['message'] ?? 'Rename failed.'));
        header('Location: station.php'); exit;
    }

    if ($action === 'rename_project') {
        $project = station_safe_name((string) ($_POST['project_slug'] ?? ''));
        $result  = station_rename_project_slug($project, (string) ($_POST['new_project_slug'] ?? ''));
        if (!empty($result['ok'])) {
            station_log_event('project.renamed', ['from' => $project, 'to' => $result['slug']]);
            station_flash_set('ok', 'Project renamed to ' . $result['slug']);
            header('Location: station.php'); exit;
        }
        station_flash_set('error', (string) ($result['message'] ?? 'Rename failed.'));
        header('Location: station.php'); exit;
    }

    if ($action === 'claim_project_owner') {
        $project = station_safe_name((string) ($_POST['project_slug'] ?? ''));
        $owner   = (string) ($user['username'] ?? 'unknown');
        station_update_project_owner($project, $owner)
            ? station_flash_set('ok', 'Owner set to ' . $owner)
            : station_flash_set('error', 'Could not update owner.');
        station_log_event('project.owner.updated', ['slug' => $project, 'owner' => $owner]);
        header('Location: station.php'); exit;
    }

    if ($action === 'set_access_mode') {
        $project    = station_safe_name((string) ($_POST['project_slug'] ?? ''));
        $accessMode = (string) ($_POST['access_mode'] ?? 'admin');
        station_set_project_access_mode($project, $accessMode)
            ? station_flash_set('ok', 'Access updated.')
            : station_flash_set('error', 'Could not update access.');
        station_log_event('project.access.updated', ['slug' => $project, 'accessMode' => $accessMode]);
        header('Location: station.php'); exit;
    }
}

$appName        = (string) (station_config()['appName'] ?? 'Deployment Station');
$projects       = station_list_projects();
$projects       = array_values(array_filter($projects, static function (array $p) use ($user): bool {
    return station_can_access_project($user, (string) ($p['accessMode'] ?? 'admin'));
}));
$error          = station_flash_get('error');
$ok             = station_flash_get('ok');
$templates      = station_template_catalog();
$accessModes    = station_allowed_project_access_modes();
$recentEvents   = $isOwner ? station_recent_events(50)              : [];
$backups        = $isOwner ? station_list_backups()                 : [];
$archivedProjects = $isOwner ? station_list_archived_projects()     : [];
$clipboard      = station_get_user_clipboard(station_current_username());
$projectCount   = count($projects);
$publicCount    = count(array_filter($projects, static function (array $project): bool {
  return ((string) ($project['visibility'] ?? 'private')) === 'public';
}));
$privateCount   = $projectCount - $publicCount;
$viewerBuilderCount = count(array_filter($projects, static function (array $project): bool {
  return ((string) ($project['accessMode'] ?? 'admin')) === 'viewersorbuilder';
}));
$builderAdminCount = count(array_filter($projects, static function (array $project): bool {
  return ((string) ($project['accessMode'] ?? 'admin')) === 'buildersoradmin';
}));
$ownerOnlyCount = count(array_filter($projects, static function (array $project): bool {
  return ((string) ($project['accessMode'] ?? 'admin')) === 'adminsonly';
}));
$readyIntegrationCount = (int) $githubReady + (int) $vscodeReady + (int) $chatgptReady + (int) $codexReady;
$userInitial    = strtoupper(substr((string) station_current_username(), 0, 1));
?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html($appName, 'Manage projects, integrations, users, and station settings from one mobile-friendly workspace.', 'assets/style.css?v=20260508e') ?>
  <style>
    .station-body {
      margin: 0;
      background: linear-gradient(180deg, #f7faff 0%, #eef3fb 100%);
      color: #13233f;
      font-family: "Google Sans Text", "Segoe UI", sans-serif;
    }

    .station-body a {
      color: inherit;
      text-decoration: none;
    }

    .station-body button,
    .station-body input,
    .station-body select,
    .station-body textarea {
      font: inherit;
    }

    .dashboard-shell {
      display: grid;
      grid-template-columns: 104px minmax(0, 1fr);
      min-height: 100vh;
    }

    .dashboard-nav {
      position: sticky;
      top: 0;
      height: 100vh;
      padding: 14px 12px 18px;
      background: linear-gradient(180deg, #1e5dac 0%, #184c91 100%);
      box-shadow: inset -1px 0 0 rgba(255,255,255,.08);
    }

    .dashboard-nav-inner {
      height: 100%;
      display: flex;
      flex-direction: column;
      gap: 18px;
    }

    .dashboard-brand {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 10px;
    }

    .dashboard-mobile-bar {
      display: none;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }

    .dashboard-mobile-toggle {
      display: none;
      width: 46px;
      height: 46px;
      align-items: center;
      justify-content: center;
      border: 0;
      border-radius: 14px;
      background: rgba(255,255,255,.14);
      color: #fff;
      cursor: pointer;
      box-shadow: inset 0 1px 0 rgba(255,255,255,.10);
    }

    .dashboard-mobile-toggle-lines {
      display: grid;
      gap: 4px;
    }

    .dashboard-mobile-toggle-lines span {
      display: block;
      width: 18px;
      height: 2px;
      border-radius: 999px;
      background: currentColor;
    }

    .dashboard-mobile-user {
      display: none;
    }

    .dashboard-brand-mark {
      width: 48px;
      height: 48px;
      display: grid;
      place-items: center;
      border-radius: 16px;
      background: rgba(255,255,255,.16);
      color: #fff;
      font-family: "Google Sans", sans-serif;
      font-size: 26px;
      font-weight: 700;
    }

    .dashboard-brand-copy {
      display: grid;
      justify-items: center;
      gap: 2px;
    }

    .dashboard-brand-kicker {
      font-size: 10px;
      letter-spacing: .12em;
      text-transform: uppercase;
      color: rgba(255,255,255,.58);
    }

    .dashboard-brand-name {
      font-size: 12px;
      font-weight: 700;
      color: #fff;
      text-align: center;
    }

    .dashboard-menu {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .dashboard-menu-link {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 6px;
      padding: 12px 6px;
      border-radius: 16px;
      color: rgba(255,255,255,.82);
      font-size: 11px;
      font-weight: 600;
      transition: background .15s ease, transform .15s ease;
    }

    .dashboard-menu-link:hover,
    .dashboard-menu-link.active {
      background: rgba(255,255,255,.15);
      color: #fff;
      transform: translateY(-1px);
    }

    .menu-icon {
      width: 38px;
      height: 38px;
      display: grid;
      place-items: center;
      border-radius: 12px;
      background: rgba(255,255,255,.16);
      color: #fff;
      font-size: 18px;
      font-weight: 700;
      line-height: 1;
      box-shadow: inset 0 1px 0 rgba(255,255,255,.10);
    }

    .dashboard-account {
      margin-top: auto;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 10px;
      padding: 12px 8px;
      border-radius: 18px;
      background: rgba(255,255,255,.08);
    }

    .dashboard-avatar {
      width: 42px;
      height: 42px;
      display: grid;
      place-items: center;
      border-radius: 999px;
      background: rgba(255,255,255,.18);
      color: #fff;
      font-family: "Google Sans", sans-serif;
      font-size: 18px;
      font-weight: 700;
    }

    .dashboard-account-copy {
      display: grid;
      gap: 2px;
      justify-items: center;
    }

    .dashboard-account-copy strong {
      font-size: 11px;
      color: #fff;
    }

    .dashboard-account-copy span {
      font-size: 10px;
      color: rgba(255,255,255,.68);
    }

    .dashboard-main {
      min-width: 0;
      padding: 28px 28px 48px;
    }

    .dashboard-topbar {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 20px;
      margin-bottom: 18px;
    }

    .dashboard-kicker,
    .projects-kicker,
    .search-label {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .14em;
      text-transform: uppercase;
      color: #2f7de2;
    }

    .dashboard-heading {
      margin: 6px 0 0;
      font-family: "Google Sans", sans-serif;
      font-size: clamp(28px, 4vw, 42px);
      line-height: 1.05;
      color: #13233f;
    }

    .dashboard-subheading {
      margin: 8px 0 0;
      max-width: 62ch;
      font-size: 14px;
      color: #587092;
      line-height: 1.7;
    }

    .btn-primary,
    .dashboard-create-btn,
    .station-body button {
      border: 0;
      border-radius: 14px;
      background: linear-gradient(135deg, #2f7de2 0%, #5aa1ff 100%);
      color: #fff;
      font-weight: 700;
      box-shadow: 0 14px 32px rgba(47,125,226,.22);
    }

    .dashboard-search-panel {
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin-bottom: 16px;
    }

    .dashboard-search-panel input[type="search"] {
      width: 100%;
      min-height: 56px;
      padding: 0 20px 0 54px;
      border: 1.5px solid #dbe5f4;
      border-radius: 22px;
      background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='%238da0bf' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='m21 21-4.35-4.35'/%3E%3C/svg%3E") no-repeat 22px center;
      box-shadow: 0 12px 36px rgba(19,35,63,.08);
    }

    .workspace-strip {
      display: grid;
      grid-template-columns: minmax(0, 1.8fr) minmax(290px, .9fr);
      gap: 18px;
      margin-bottom: 18px;
    }

    .workspace-card,
    .projects-panel,
    .admin-panel {
      background: #fff;
      border: 1px solid #dbe5f4;
      border-radius: 26px;
      padding: 20px;
      box-shadow: 0 12px 36px rgba(19,35,63,.08);
    }

    .section-head {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 12px;
      margin-bottom: 14px;
    }

    .section-head h2,
    .projects-title {
      margin: 0;
      font-family: "Google Sans", sans-serif;
      color: #13233f;
    }

    .section-note,
    .projects-note,
    .clip-status,
    .mini-link {
      color: #8da0bf;
      font-size: 12px;
    }

    .clipboard-toolbar {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    .icon-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      min-height: 38px;
      padding: 0 14px;
      border-radius: 999px;
      border: 1px solid #b8cae8;
      background: #f7faff;
      color: #1a5cbc;
      font-size: 12px;
      font-weight: 700;
      cursor: pointer;
    }

    .icon-pill-symbol {
      width: 18px;
      height: 18px;
      display: grid;
      place-items: center;
      border-radius: 999px;
      background: rgba(47,125,226,.12);
      font-size: 12px;
    }

    .clipboard-card textarea {
      width: 100%;
      min-height: 174px;
      border: 1.5px solid #dbe5f4;
      border-radius: 20px;
      background: linear-gradient(180deg, #fcfdff 0%, #f4f8ff 100%);
      padding: 16px;
      font-family: "JetBrains Mono", monospace;
      font-size: 12px;
      line-height: 1.7;
      color: #13233f;
      box-shadow: inset 0 1px 0 rgba(255,255,255,.7);
    }

    .clip-files {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 12px;
    }

    .clip-file-item {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      min-height: 40px;
      max-width: 100%;
      padding: 5px 6px 5px 10px;
      border-radius: 999px;
      border: 1px solid #dbe5f4;
      background: #f7faff;
    }

    .clip-file-link {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      min-width: 0;
    }

    .clip-file-type {
      display: inline-flex;
      align-items: center;
      min-height: 24px;
      padding: 0 8px;
      border-radius: 999px;
      background: rgba(47,125,226,.12);
      color: #1a5cbc;
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .05em;
    }

    .clip-file-name {
      max-width: 240px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      color: #13233f;
      font-size: 12px;
      font-weight: 600;
    }

    .clip-delete-btn {
      width: 28px;
      height: 28px;
      padding: 0;
      border-radius: 999px;
      border: 1px solid rgba(197,34,31,.18);
      background: #fff;
      color: #c5221f;
      font-size: 16px;
      line-height: 1;
      box-shadow: none;
    }

    .clip-status-row {
      display: flex;
      justify-content: flex-end;
      margin-top: 10px;
    }

    .workspace-mini-stats,
    .dashboard-metrics {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 10px;
    }

    .dashboard-metrics {
      margin-bottom: 18px;
    }

    .mini-stat,
    .metric-card {
      display: grid;
      gap: 4px;
      padding: 14px 12px;
      border-radius: 18px;
      background: rgba(255,255,255,.96);
      border: 1px solid #dbe5f4;
      box-shadow: 0 8px 24px rgba(19,35,63,.06);
    }

    .mini-stat strong,
    .metric-card strong {
      font-family: "Google Sans", sans-serif;
      font-size: 24px;
      color: #13233f;
      line-height: 1;
    }

    .mini-stat span,
    .metric-card span {
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .08em;
      color: #8da0bf;
    }

    .projects-panel {
      background: rgba(255,255,255,.76);
      backdrop-filter: blur(10px);
    }

    .projects-panel-head {
      display: flex;
      justify-content: space-between;
      align-items: flex-end;
      gap: 16px;
      margin-bottom: 18px;
    }

    .projects-title {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      margin-top: 6px;
      font-size: 24px;
    }

    .project-card-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 18px;
    }

    .project-card,
    .create-project-card {
      min-height: 212px;
      border-radius: 28px;
      padding: 20px;
      box-shadow: 0 12px 36px rgba(19,35,63,.08);
    }

    .project-card {
      background: linear-gradient(180deg, #ffffff 0%, #f9fbff 100%);
      border: 1px solid rgba(217, 228, 247, .9);
      display: flex;
      flex-direction: column;
      gap: 14px;
    }

    .create-project-card {
      border: 1.5px dashed #b8cae8;
      background: radial-gradient(circle at top right, rgba(47,125,226,.14), transparent 36%), linear-gradient(135deg, #ffffff 0%, #edf4ff 100%);
      display: flex;
      flex-direction: column;
      gap: 12px;
      text-align: left;
    }

    .create-plus {
      width: 62px;
      height: 62px;
      display: grid;
      place-items: center;
      border-radius: 20px;
      background: linear-gradient(135deg, #2f7de2 0%, #5aa1ff 100%);
      color: #fff;
      font-size: 36px;
      font-weight: 700;
      box-shadow: 0 16px 34px rgba(47,125,226,.24);
    }

    .project-card-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
    }

    .project-card-heading {
      display: flex;
      flex-direction: column;
      gap: 8px;
      min-width: 0;
    }

    .proj-name-row {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    .proj-slug {
      font-family: "Google Sans", sans-serif;
      font-size: 18px;
      font-weight: 700;
      color: #13233f;
    }

    .proj-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 5px;
      align-items: center;
      font-size: 12px;
      color: #8da0bf;
    }

    .status-pill {
      display: inline-flex;
      align-items: center;
      border-radius: 999px;
      padding: 3px 10px;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: .06em;
      text-transform: uppercase;
    }

    .status-pill.is-public { background: #eaf6ef; color: #14683a; }
    .status-pill.is-private { background: #f2f5fb; color: #4e5e82; }

    .quick-link {
      display: inline-flex;
      align-items: center;
      border: 1px solid #b8cae8;
      border-radius: 999px;
      padding: 7px 12px;
      background: #f7faff;
      color: #1a5cbc;
      font-size: 11.5px;
      font-weight: 700;
    }

    .proj-quick-actions {
      display: flex;
      gap: 7px;
      flex-wrap: wrap;
      margin-top: auto;
    }

    .gear-summary {
      list-style: none;
      width: 42px;
      height: 42px;
      display: grid;
      place-items: center;
      border-radius: 14px;
      border: 1px solid #dbe5f4;
      background: #f7faff;
      color: #1a5cbc;
      font-size: 20px;
      font-weight: 700;
    }

    .gear-summary::-webkit-details-marker { display: none; }

    .proj-more-body {
      position: absolute;
      top: 40px;
      right: 0;
      z-index: 50;
      width: min(94vw, 360px);
      padding: 14px;
      border-radius: 22px;
      border: 1px solid #dbe5f4;
      background: #fff;
      box-shadow: 0 34px 90px rgba(19,35,63,.18);
      display: grid;
      gap: 9px;
    }

    .proj-more { position: relative; }

    .integration-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
    }

    .integration-chip {
      display: flex;
      justify-content: space-between;
      align-items: center;
      min-height: 62px;
      padding: 12px 14px;
      border-radius: 18px;
      border: 1px solid #dbe5f4;
      font-size: 12px;
      font-weight: 700;
    }

    .chip-on { background: #ecfdf5; color: #065f46; border-color: #88e0b9; }
    .chip-off { background: #f5f7fb; color: #8da0bf; }

    .count-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 24px;
      height: 24px;
      padding: 0 7px;
      border-radius: 999px;
      background: linear-gradient(135deg, #2f7de2 0%, #5aa1ff 100%);
      color: #fff;
      font-size: 11px;
      font-weight: 700;
    }

    .collapsible-section > summary {
      list-style: none;
      cursor: pointer;
      font-size: 13px;
      font-weight: 700;
      color: #13233f;
    }

    .collapsible-section > summary::-webkit-details-marker { display: none; }

    @media (max-width: 1060px) {
      .workspace-strip,
      .dashboard-metrics {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 760px) {
      .dashboard-shell {
        grid-template-columns: 1fr;
      }

      .dashboard-nav {
        position: sticky;
        top: 0;
        z-index: 30;
        padding: 12px 14px;
        height: auto;
        box-shadow: 0 10px 24px rgba(19,35,63,.14);
      }

      .dashboard-nav-inner {
        gap: 12px;
        height: auto;
      }

      .dashboard-mobile-bar {
        display: flex;
      }

      .dashboard-mobile-toggle {
        display: inline-flex;
      }

      .dashboard-nav-inner > .dashboard-brand,
      .dashboard-nav-inner > .dashboard-account {
        display: none;
      }

      .dashboard-nav-inner > .dashboard-menu {
        display: none;
        grid-template-columns: 1fr;
        gap: 6px;
        padding: 12px;
        border-radius: 18px;
        background: rgba(255,255,255,.08);
      }

      .dashboard-nav.is-open .dashboard-nav-inner > .dashboard-menu {
        display: grid;
      }

      .dashboard-main {
        padding: 18px 14px 36px;
      }

      .dashboard-topbar,
      .projects-panel-head {
        flex-direction: column;
        align-items: stretch;
      }

      .project-card-grid,
      .integration-grid,
      .workspace-mini-stats {
        grid-template-columns: 1fr;
      }

      .dashboard-account-copy {
        display: grid;
        justify-items: start;
      }

      .dashboard-mobile-user {
        display: grid;
        gap: 2px;
        padding: 8px 10px 12px;
        border-bottom: 1px solid rgba(255,255,255,.12);
        margin-bottom: 2px;
      }

      .dashboard-mobile-user strong {
        font-size: 13px;
        color: #fff;
      }

      .dashboard-mobile-user span {
        font-size: 11px;
        color: rgba(255,255,255,.66);
        text-transform: capitalize;
      }

      .dashboard-menu-link {
        flex-direction: row;
        justify-content: flex-start;
        padding: 10px 12px;
        font-size: 13px;
      }

      .menu-icon {
        display: none;
      }

      .dashboard-menu-link span:last-child {
        display: inline;
      }
    }
  </style>
</head>
<body class="station-body">
  <div class="dashboard-shell">
    <aside class="dashboard-nav">
      <div class="dashboard-mobile-bar">
        <a class="dashboard-brand" href="station.php">
          <span class="dashboard-brand-mark">V</span>
          <span class="dashboard-brand-copy">
            <span class="dashboard-brand-kicker"><?= station_h($uiConfig['heading']) ?></span>
            <span class="dashboard-brand-name"><?= station_h($appName) ?></span>
          </span>
        </a>
        <button type="button" class="dashboard-mobile-toggle" id="dashboardMobileToggle" aria-label="Open navigation" aria-controls="dashboardMenu" aria-expanded="false">
          <span class="dashboard-mobile-toggle-lines" aria-hidden="true">
            <span></span>
            <span></span>
            <span></span>
          </span>
        </button>
      </div>
      <div class="dashboard-nav-inner">
        <a class="dashboard-brand" href="station.php">
          <span class="dashboard-brand-mark">V</span>
          <span class="dashboard-brand-copy">
            <span class="dashboard-brand-kicker"><?= station_h($uiConfig['heading']) ?></span>
            <span class="dashboard-brand-name"><?= station_h($appName) ?></span>
          </span>
        </a>

        <nav class="dashboard-menu" id="dashboardMenu" aria-label="Primary navigation">
          <div class="dashboard-mobile-user">
            <strong><?= station_h(station_current_username()) ?></strong>
            <span><?= station_h((string) ($user['role'] ?? 'user')) ?></span>
          </div>
          <a href="station.php" class="dashboard-menu-link active">
            <span class="menu-icon">⌂</span>
            <span>Dashboard</span>
          </a>
          <?php if ($isAdmin): ?>
          <a href="users.php" class="dashboard-menu-link">
            <span class="menu-icon">◎</span>
            <span>Users</span>
          </a>
          <?php endif; ?>
          <?php if ($isOwner): ?>
          <a href="admin-settings.php" class="dashboard-menu-link">
            <span class="menu-icon">◫</span>
            <span>Settings</span>
          </a>
          <?php endif; ?>
          <a href="user-settings.php" class="dashboard-menu-link">
            <span class="menu-icon">◌</span>
            <span>User Settings</span>
          </a>
          <a href="logout.php" class="dashboard-menu-link logout-link">
            <span class="menu-icon">↗</span>
            <span>Sign out</span>
          </a>
        </nav>

        <a class="dashboard-account" href="user-settings.php">
          <span class="dashboard-avatar"><?= station_h($userInitial) ?></span>
          <span class="dashboard-account-copy">
            <strong><?= station_h(station_current_username()) ?></strong>
            <span><?= station_h((string) ($user['role'] ?? 'user')) ?></span>
          </span>
        </a>
      </div>
    </aside>

    <main class="dashboard-main">
      <?php if ($ok !== '' || $error !== ''): ?>
      <div class="page-notices">
        <?php if ($ok !== ''): ?><div class="alert ok"><?= station_h($ok) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="alert error"><?= station_h($error) ?></div><?php endif; ?>
      </div>
      <?php endif; ?>

      <header class="dashboard-topbar">
        <div>
          <p class="dashboard-kicker">Deployment workspace</p>
          <h1 class="dashboard-heading"><?= station_h($appName) ?></h1>
          <p class="dashboard-subheading">
            <?php if ($uiConfig['subheading'] !== ''): ?>
              <?= station_h($uiConfig['subheading']) ?>
            <?php else: ?>
              Build, edit, launch, and manage projects from one dashboard.
            <?php endif; ?>
          </p>
        </div>
        <?php if ($canBuild): ?>
        <button type="button" class="btn-primary dashboard-create-btn" id="newProjectBtnTop">Create Project</button>
        <?php endif; ?>
      </header>

      <section class="dashboard-search-panel">
        <label class="search-label" for="globalSearch">Search</label>
        <input id="globalSearch" type="search" placeholder="Search projects, owner, access, status, created date..." aria-label="Search projects">
      </section>

      <section class="dashboard-metrics" aria-label="Project access overview">
        <div class="metric-card"><strong><?= $publicCount ?></strong><span>Public</span></div>
        <div class="metric-card"><strong><?= $viewerBuilderCount ?></strong><span>Viewer / Builder</span></div>
        <div class="metric-card"><strong><?= $builderAdminCount ?></strong><span>Builder / Admin</span></div>
        <div class="metric-card"><strong><?= $ownerOnlyCount ?></strong><span>Owner Only</span></div>
      </section>

      <section class="workspace-strip">
        <section class="workspace-card clipboard-card clipboard-card-compact clipboard-composer">
          <div class="section-head clipboard-section-head">
            <div>
              <h2>Clipboard</h2>
              <p class="section-note">Keep text, snippets, images, and files ready across devices.</p>
            </div>
            <div class="clipboard-toolbar">
              <label class="icon-pill upload-pill" for="clipFileInput" title="Add file">
                <span class="icon-pill-symbol">＋</span>
                <span>File</span>
              </label>
              <label class="icon-pill upload-pill" for="clipImageInput" title="Add image">
                <span class="icon-pill-symbol">◫</span>
                <span>Image</span>
              </label>
              <button class="secondary-btn" id="copyClipboardBtn" type="button">Copy</button>
              <button class="secondary-btn" id="clearClipboardBtn" type="button">Clear all</button>
            </div>
          </div>
          <input id="clipFileInput" type="file" hidden>
          <input id="clipImageInput" type="file" accept="image/*" hidden>
          <div class="clipboard-text-wrap">
            <textarea id="clipboardText" rows="6" placeholder="Paste text, links, notes, code, or instructions..."><?= station_h($clipboard) ?></textarea>
          </div>
          <div class="clip-files" id="clipFiles"></div>
          <div class="clip-status-row">
            <span class="clip-status" id="clipStatus"></span>
          </div>
        </section>

        <section class="workspace-card integrations-panel">
          <div class="section-head integrations-head">
            <div>
              <h2>Integrations</h2>
              <p class="section-note"><?= $readyIntegrationCount ?>/4 connected</p>
            </div>
            <a class="mini-link" href="user-settings.php">Configure</a>
          </div>
          <div class="integration-grid">
            <a class="integration-chip <?= $githubReady  ? 'chip-on' : 'chip-off' ?>" href="<?= $githubReady  ? 'user-settings.php' : 'integration-help.php#github'  ?>"><span>GitHub</span><span><?=  $githubReady  ? '✓' : '○' ?></span></a>
            <a class="integration-chip <?= $vscodeReady  ? 'chip-on' : 'chip-off' ?>" href="<?= $vscodeReady  ? 'user-settings.php' : 'integration-help.php#vscode'  ?>"><span>VS Code</span><span><?= $vscodeReady  ? '✓' : '○' ?></span></a>
            <a class="integration-chip <?= $chatgptReady ? 'chip-on' : 'chip-off' ?>" href="<?= $chatgptReady ? 'user-settings.php' : 'integration-help.php#chatgpt' ?>"><span>ChatGPT</span><span><?= $chatgptReady ? '✓' : '○' ?></span></a>
            <a class="integration-chip <?= $codexReady   ? 'chip-on' : 'chip-off' ?>" href="<?= $codexReady   ? 'user-settings.php' : 'integration-help.php#codex'   ?>"><span>Codex</span><span><?=   $codexReady   ? '✓' : '○' ?></span></a>
          </div>
          <div class="workspace-mini-stats">
            <div class="mini-stat"><strong><?= $projectCount ?></strong><span>Projects</span></div>
            <div class="mini-stat"><strong><?= $publicCount ?></strong><span>Public</span></div>
            <div class="mini-stat"><strong><?= $privateCount ?></strong><span>Private</span></div>
          </div>
        </section>
      </section>

      <section class="projects-panel">
        <div class="projects-panel-head">
          <div>
            <p class="projects-kicker">Projects</p>
            <h2 class="projects-title">All Deployments <span class="count-badge"><?= $projectCount ?></span></h2>
          </div>
          <p class="projects-note">Launch, inspect files, manage access, and open linked repos in github.dev.</p>
        </div>

        <div class="project-card-grid" id="projectList">
          <?php if ($canBuild): ?>
          <button type="button" class="project-card create-project-card" id="newProjectBtn">
            <span class="create-plus">+</span>
            <strong>Create New Project</strong>
            <span>Upload or start from a template.</span>
          </button>
          <?php endif; ?>

          <?php foreach ($projects as $project): ?>
            <?php
              $slug       = (string) ($project['slug'] ?? '');
              $pOwner     = (string) ($project['owner'] ?? 'unknown');
              $visibility = (string) ($project['visibility'] ?? 'private');
              $pAccess    = (string) ($project['accessMode'] ?? 'admin');
              $createdAt  = substr((string) ($project['createdAt'] ?? ''), 0, 10);
              $isPublic   = $visibility === 'public';
              $ps         = station_project_settings($slug);
              $ghOwner    = (string) ($ps['github']['repoOwner'] ?? '');
              $ghRepo     = (string) ($ps['github']['repoName'] ?? '');
              $ghdevUrl   = ($ghOwner !== '' && $ghRepo !== '')
                  ? 'https://github.dev/' . rawurlencode($ghOwner) . '/' . rawurlencode($ghRepo)
                  : '';
            ?>
            <article class="project-card" data-search="<?= station_h(strtolower($slug . ' ' . $pOwner . ' ' . $pAccess . ' ' . $visibility . ' ' . $createdAt)) ?>">
              <div class="project-card-head">
                <div class="project-card-heading">
                  <div class="proj-name-row">
                    <strong class="proj-slug"><?= station_h($slug) ?></strong>
                    <span class="status-pill <?= $isPublic ? 'is-public' : 'is-private' ?>"><?= $isPublic ? 'Public' : 'Private' ?></span>
                  </div>
                  <div class="proj-meta">
                    <span>Owner: <?= station_h($pOwner) ?></span>
                    <span class="meta-sep">·</span>
                    <span><?= station_h((string) ($accessModes[$pAccess] ?? $pAccess)) ?></span>
                    <?php if ($createdAt !== ''): ?>
                      <span class="meta-sep">·</span>
                      <span><?= station_h($createdAt) ?></span>
                    <?php endif; ?>
                  </div>
                </div>

                <?php if ($canBuild): ?>
                <details class="proj-more">
                  <summary title="Project settings" class="gear-summary">⚙</summary>
                  <div class="proj-more-body">
                    <form method="post" class="more-action-form">
                      <input type="hidden" name="action" value="rename_project">
                      <input type="hidden" name="project_slug" value="<?= station_h($slug) ?>">
                      <input type="text" name="new_project_slug" placeholder="New project URL name" required>
                      <button type="submit">Rename</button>
                    </form>
                    <form method="post" class="more-action-form">
                      <input type="hidden" name="action" value="set_access_mode">
                      <input type="hidden" name="project_slug" value="<?= station_h($slug) ?>">
                      <select name="access_mode">
                        <?php foreach ($accessModes as $val => $lbl): ?>
                          <?php if ($val !== 'public' || !empty($adminSettings['allowPublicProjects'])): ?>
                            <option value="<?= station_h($val) ?>" <?= $pAccess === $val ? 'selected' : '' ?>><?= station_h($lbl) ?></option>
                          <?php endif; ?>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit">Set Access</button>
                    </form>
                    <form method="post" class="more-action-form">
                      <input type="hidden" name="action" value="claim_project_owner">
                      <input type="hidden" name="project_slug" value="<?= station_h($slug) ?>">
                      <button type="submit" class="secondary-btn">Set Me as Owner</button>
                    </form>
                    <form method="post" class="more-action-form" action="backups.php">
                      <input type="hidden" name="action" value="backup_project">
                      <input type="hidden" name="project" value="<?= station_h($slug) ?>">
                      <button type="submit" class="secondary-btn">Backup</button>
                    </form>
                    <form method="post" class="more-action-form" action="backups.php">
                      <input type="hidden" name="action" value="archive_project">
                      <input type="hidden" name="project" value="<?= station_h($slug) ?>">
                      <button type="submit" class="secondary-btn">Archive</button>
                    </form>
                    <?php if ($isOwner): ?>
                    <form method="post" class="more-action-form" action="backups.php"
                          onsubmit="return confirm('Permanently delete <?= station_h($slug) ?>? This cannot be undone.')">
                      <input type="hidden" name="action" value="delete_project">
                      <input type="hidden" name="project" value="<?= station_h($slug) ?>">
                      <button type="submit" class="danger-btn">Delete Forever</button>
                    </form>
                    <?php endif; ?>
                  </div>
                </details>
                <?php endif; ?>
              </div>

              <div class="proj-quick-actions">
                <a class="quick-link" href="launch.php?project=<?= urlencode($slug) ?>" target="_blank" rel="noreferrer">Launch ↗</a>
                <a class="quick-link" href="viewer.php?project=<?= urlencode($slug) ?>">Files</a>
                <?php if ($ghdevUrl !== ''): ?>
                  <a class="quick-link ghdev-link" href="<?= station_h($ghdevUrl) ?>" target="_blank" rel="noreferrer">github.dev ↗</a>
                <?php endif; ?>
                <?php if ($canBuild): ?>
                  <a class="quick-link" href="project-settings.php?project=<?= urlencode($slug) ?>">Settings</a>
                <?php endif; ?>
                <button class="quick-link share-btn" type="button" data-share-url="/secure/<?= station_h($slug) ?>/">Share</button>
              </div>
            </article>
          <?php endforeach; ?>
        </div>

        <?php if (!$projects && !$canBuild): ?>
          <p class="empty-state">No projects are visible with your access level.</p>
        <?php endif; ?>
      </section>

      <?php if ($isOwner): ?>
      <div class="admin-sections">
        <details class="admin-panel collapsible-section">
          <summary>Station Settings</summary>
          <div class="grid-two collapsible-body">
            <form class="form-grid" method="post">
              <h2>Station Name</h2>
              <input type="hidden" name="action" value="update_station_name">
              <label>Display Name<input type="text" name="station_name" value="<?= station_h($appName) ?>" required></label>
              <button type="submit">Save</button>
            </form>
            <form class="form-grid" method="post">
              <h2>Station Directory</h2>
              <input type="hidden" name="action" value="rename_station_dir">
              <label>New Directory Name<input type="text" name="station_dir_name" placeholder="station" required></label>
              <button type="submit">Rename Directory</button>
            </form>
          </div>
        </details>

        <?php if ($backups || $archivedProjects): ?>
        <details class="admin-panel collapsible-section">
          <summary>Backups &amp; Archives</summary>
          <div class="grid-two collapsible-body">
            <div>
              <h2>Project Backups</h2>
              <?php if (!$backups): ?>
                <p>No backups yet.</p>
              <?php else: ?>
                <table>
                  <thead><tr><th>Archive</th><th>Size</th><th>Restore</th></tr></thead>
                  <tbody>
                    <?php foreach ($backups as $bk): ?>
                      <tr>
                        <td><?= station_h((string) ($bk['name'] ?? '')) ?></td>
                        <td><?= number_format((int) ($bk['size'] ?? 0)) ?> B</td>
                        <td>
                          <form method="post" action="backups.php" class="inline-form">
                            <input type="hidden" name="action" value="restore_backup">
                            <input type="hidden" name="archive_name" value="<?= station_h((string) ($bk['name'] ?? '')) ?>">
                            <button type="submit" class="secondary-btn">Restore</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
            <div>
              <h2>Archived Projects</h2>
              <?php if (!$archivedProjects): ?>
                <p>No archived projects.</p>
              <?php else: ?>
                <table>
                  <thead><tr><th>Archive</th><th>Restore</th></tr></thead>
                  <tbody>
                    <?php foreach ($archivedProjects as $ar): ?>
                      <tr>
                        <td><?= station_h((string) ($ar['name'] ?? '')) ?></td>
                        <td>
                          <form method="post" action="backups.php" class="inline-form">
                            <input type="hidden" name="action" value="restore_archive">
                            <input type="hidden" name="archive_name" value="<?= station_h((string) ($ar['name'] ?? '')) ?>">
                            <button type="submit" class="secondary-btn">Restore</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
          </div>
        </details>
        <?php endif; ?>

        <details class="admin-panel collapsible-section">
          <summary>Activity Log (<?= count($recentEvents) ?>)</summary>
          <div class="collapsible-body">
            <?php if (!$recentEvents): ?>
              <p>No activity yet.</p>
            <?php else: ?>
              <table>
                <thead><tr><th>Time</th><th>Event</th><th>User</th><th>Detail</th></tr></thead>
                <tbody>
                  <?php foreach ($recentEvents as $ev): ?>
                    <tr>
                      <td><?= station_h(substr((string) ($ev['at'] ?? ''), 0, 19)) ?></td>
                      <td><?= station_h((string) ($ev['event'] ?? '')) ?></td>
                      <td><?= station_h((string) ($ev['by'] ?? '')) ?></td>
                      <td><pre class="code-mini"><?= station_h(json_encode($ev['context'] ?? [], JSON_UNESCAPED_SLASHES) ?: '{}') ?></pre></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </details>
      </div>
      <?php endif; ?>
    </main>
  </div>

  <?php if ($canBuild): ?>
  <dialog id="newProjectDialog" class="modal-dialog">
    <div class="modal-inner">
      <div class="modal-header">
        <h2>Start New Project</h2>
        <button class="modal-close-btn" type="button" id="closeNewProjectDialog">✕</button>
      </div>
      <div class="modal-tabs" role="tablist">
        <button class="tab-btn active" type="button" data-tab="zip">Upload Zip</button>
        <button class="tab-btn" type="button" data-tab="folder">Folder</button>
        <button class="tab-btn" type="button" data-tab="file">Single File</button>
        <button class="tab-btn" type="button" data-tab="template">Template</button>
      </div>
      <div class="tab-pane active" id="tab-zip">
        <form action="upload.php" method="post" enctype="multipart/form-data" class="form-grid">
          <input type="hidden" name="action" value="upload_zip">
          <label>Project Name<input type="text" name="project_name" placeholder="my-app" required></label>
          <label>Zip File<input type="file" name="zip_file" accept=".zip" required></label>
          <button type="submit">Upload &amp; Deploy</button>
        </form>
      </div>
      <div class="tab-pane" id="tab-folder">
        <form action="upload.php" method="post" enctype="multipart/form-data" class="form-grid">
          <input type="hidden" name="action" value="upload_folder">
          <label>Project Name<input type="text" name="project_name" placeholder="my-folder" required></label>
          <label>Folder<input type="file" name="folder_files[]" webkitdirectory directory multiple required></label>
          <button type="submit">Upload Folder</button>
        </form>
      </div>
      <div class="tab-pane" id="tab-file">
        <form action="upload.php" method="post" enctype="multipart/form-data" class="form-grid">
          <input type="hidden" name="action" value="upload_single">
          <label>Project Name<input type="text" name="project_name" placeholder="single-file-project" required></label>
          <label>File<input type="file" name="single_file" required></label>
          <button type="submit">Create Project</button>
        </form>
      </div>
      <div class="tab-pane" id="tab-template">
        <form action="upload.php" method="post" class="form-grid">
          <input type="hidden" name="action" value="create_template">
          <label>Project Name<input type="text" name="project_name" placeholder="starter-project" required></label>
          <label>Template
            <select name="template_type" required>
              <?php foreach ($templates as $tKey => $tLabel): ?>
                <option value="<?= station_h($tKey) ?>"><?= station_h($tLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="submit">Create Starter</button>
        </form>
      </div>
    </div>
  </dialog>
  <?php endif; ?>

  <script>
  (function () {
    'use strict';

    const mobileNav = document.querySelector('.dashboard-nav');
    const mobileToggle = document.getElementById('dashboardMobileToggle');

    if (mobileNav && mobileToggle) {
      mobileToggle.addEventListener('click', function () {
        const isOpen = mobileNav.classList.toggle('is-open');
        mobileToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        mobileToggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
      });
    }

    /* ── New Project modal ── */
    const newBtn = document.getElementById('newProjectBtn');
    const newBtnTop = document.getElementById('newProjectBtnTop');
    const newDlg = document.getElementById('newProjectDialog');
    const closeBtnDlg = document.getElementById('closeNewProjectDialog');
    function openNewDialog() {
      if (newDlg) newDlg.showModal();
    }
    if (newBtn && newDlg) {
      newBtn.addEventListener('click', openNewDialog);
      newDlg.addEventListener('click', (e) => { if (e.target === newDlg) newDlg.close(); });
    }
    if (newBtnTop && newDlg) newBtnTop.addEventListener('click', openNewDialog);
    if (closeBtnDlg && newDlg) closeBtnDlg.addEventListener('click', () => newDlg.close());

    /* ── Tab switching ── */
    document.querySelectorAll('.modal-tabs .tab-btn').forEach((btn) => {
      btn.addEventListener('click', function () {
        const dlg = this.closest('dialog');
        if (!dlg) return;
        dlg.querySelectorAll('.tab-btn').forEach((b) => b.classList.remove('active'));
        dlg.querySelectorAll('.tab-pane').forEach((p) => p.classList.remove('active'));
        this.classList.add('active');
        const pane = dlg.querySelector('#tab-' + this.dataset.tab);
        if (pane) pane.classList.add('active');
      });
    });

    /* ── Global search filter ── */
    const searchInput = document.getElementById('globalSearch');
    const projectList = document.getElementById('projectList');
    if (searchInput && projectList) {
      searchInput.addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();
        projectList.querySelectorAll('.project-card').forEach((card) => {
          if (card.classList.contains('create-project-card')) {
            card.style.display = '';
            return;
          }
          card.style.display = (!q || (card.dataset.search || '').toLowerCase().includes(q)) ? '' : 'none';
        });
      });
    }

    /* ── Share buttons ── */
    document.querySelectorAll('.share-btn').forEach((btn) => {
      btn.addEventListener('click', async function () {
        const url = this.dataset.shareUrl || '';
        if (!url) return;
        try {
          await navigator.clipboard.writeText(new URL(url, window.location.origin).toString());
          const orig = this.textContent;
          this.textContent = 'Copied!';
          setTimeout(() => { this.textContent = orig; }, 1500);
        } catch (_) {
          this.textContent = 'Failed'; setTimeout(() => { this.textContent = 'Share'; }, 1500);
        }
      });
    });

    /* ── Clipboard sync ── */
    const clipText    = document.getElementById('clipboardText');
    const clipStatus  = document.getElementById('clipStatus');
    const copyClipBtn = document.getElementById('copyClipboardBtn');
    const clearClipBtn = document.getElementById('clearClipboardBtn');
    const clipFileInput = document.getElementById('clipFileInput');
    const clipImageInput = document.getElementById('clipImageInput');
    const clipFiles = document.getElementById('clipFiles');
    let clipTimer = null;

    function setStatus(msg) {
      if (clipStatus) { clipStatus.textContent = msg; }
    }

    function renderFiles(items) {
      if (!clipFiles) return;
      clipFiles.innerHTML = '';
      if (!Array.isArray(items) || items.length === 0) return;

      items.forEach((item) => {
        const row = document.createElement('div');
        row.className = 'clip-file-item';

        const link = document.createElement('a');
        link.className = 'clip-file-link';
        link.href = item.url || '#';
        link.target = '_blank';
        link.rel = 'noreferrer';

        const type = document.createElement('span');
        type.className = 'clip-file-type';
        type.textContent = item.isImage ? 'Image' : 'File';

        const name = document.createElement('span');
        name.className = 'clip-file-name';
        name.textContent = item.originalName || 'attachment';

        link.appendChild(type);
        link.appendChild(name);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'clip-delete-btn';
        remove.textContent = '×';
        remove.title = 'Remove attachment';
        remove.addEventListener('click', function () {
          if (!item.name) return;
          if (!window.confirm('Delete this clipboard attachment?')) return;

          fetch('clipboard.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=remove_file&name=' + encodeURIComponent(item.name)
          })
            .then((r) => r.json())
            .then((d) => {
              if (!d.ok) {
                setStatus('Delete failed');
                setTimeout(() => setStatus(''), 2000);
                return;
              }
              renderFiles(d.files || []);
              setStatus('Attachment removed');
              setTimeout(() => setStatus(''), 2000);
            })
            .catch(() => {
              setStatus('Delete failed');
              setTimeout(() => setStatus(''), 2000);
            });
        });

        row.appendChild(link);
        row.appendChild(remove);
        clipFiles.appendChild(row);
      });
    }

    function uploadClipboardFile(file) {
      if (!file) return;
      const fd = new FormData();
      fd.append('action', 'upload_file');
      fd.append('clip_file', file);

      fetch('clipboard.php', { method: 'POST', body: fd })
        .then((r) => r.json())
        .then((d) => {
          if (!d.ok) {
            setStatus('Upload failed');
            setTimeout(() => setStatus(''), 2000);
            return;
          }
          renderFiles(d.files || []);
          setStatus('Attachment clipped');
          setTimeout(() => setStatus(''), 2000);
        })
        .catch(() => {
          setStatus('Upload error');
          setTimeout(() => setStatus(''), 2000);
        });
    }

    function refreshClipboard() {
      fetch('clipboard.php?action=get')
        .then((r) => r.json())
        .then((d) => {
          if (clipText && typeof d.content === 'string') {
            clipText.value = d.content;
          }
          renderFiles(d.files || []);
        })
        .catch(() => {});
    }

    function saveClipboard() {
      const content = clipText ? clipText.value : '';
      fetch('clipboard.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=save&content=' + encodeURIComponent(content)
      }).then((r) => r.json())
        .then((d) => { setStatus(d.ok ? 'Saved' : 'Save failed'); setTimeout(() => setStatus(''), 2000); })
        .catch(() => { setStatus('Error'); setTimeout(() => setStatus(''), 2000); });
    }

    if (clipText) {
      clipText.addEventListener('input', () => {
        clearTimeout(clipTimer);
        setStatus('…');
        clipTimer = setTimeout(saveClipboard, 1500);
      });
    }
    if (clipFileInput) {
      clipFileInput.addEventListener('change', function () {
        if (this.files && this.files[0]) {
          uploadClipboardFile(this.files[0]);
          this.value = '';
        }
      });
    }
    if (clipImageInput) {
      clipImageInput.addEventListener('change', function () {
        if (this.files && this.files[0]) {
          uploadClipboardFile(this.files[0]);
          this.value = '';
        }
      });
    }
    if (copyClipBtn && clipText) {
      copyClipBtn.addEventListener('click', async function () {
        try {
          await navigator.clipboard.writeText(clipText.value);
          const orig = this.textContent; this.textContent = 'Copied!';
          setTimeout(() => { this.textContent = orig; }, 1500);
        } catch (_) { this.textContent = 'Failed'; setTimeout(() => { this.textContent = 'Copy'; }, 1500); }
      });
    }
    if (clearClipBtn && clipText) {
      clearClipBtn.addEventListener('click', () => {
        clipText.value = '';
        fetch('clipboard.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'action=clear'
        }).then((r) => r.json())
          .then(() => { renderFiles([]); setStatus('Cleared'); setTimeout(() => setStatus(''), 1500); })
          .catch(() => { setStatus('Error'); setTimeout(() => setStatus(''), 1500); });
      });
    }
    refreshClipboard();
  })();
  </script>
  <?= station_pwa_register_html() ?>
</body>
</html>
