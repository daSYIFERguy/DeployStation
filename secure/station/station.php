<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/templates.php';
require_once __DIR__ . '/lib/docker.php';

station_require_login();

$user        = station_current_user();
$username    = station_current_username();
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

      if ($action === 'update_audit_log_limit' && $isOwner) {
        $settings = station_admin_settings();
        $settings['auditLogLimit'] = station_normalize_audit_log_limit($_POST['audit_log_limit'] ?? ($settings['auditLogLimit'] ?? 50));
        if (station_save_admin_settings($settings)) {
          station_log_event('admin.audit_log_limit.updated', ['limit' => $settings['auditLogLimit']]);
          station_flash_set('ok', 'Audit log retention updated.');
        } else {
          station_flash_set('error', 'Could not update audit log retention.');
        }
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
$auditLogLimit  = station_audit_log_limit($adminSettings);
$recentEvents   = $isOwner ? station_recent_events($auditLogLimit)  : [];
$backups        = $isOwner ? station_list_backups()                 : [];
$archivedProjects = $isOwner ? station_list_archived_projects()     : [];
$clipboard      = station_get_user_clipboard(station_current_username());
$ownedProjects  = array_values(array_filter($projects, static function (array $project) use ($username): bool {
  return station_safe_name((string) ($project['owner'] ?? '')) === station_safe_name($username);
}));
$projectCount   = count($projects);
$publicCount    = count(array_filter($projects, static function (array $project): bool {
  return ((string) ($project['visibility'] ?? 'private')) === 'public';
}));
$privateCount   = $projectCount - $publicCount;
$ownedProjectCount = count($ownedProjects);
$ownedPublicCount = count(array_filter($ownedProjects, static function (array $project): bool {
  return ((string) ($project['visibility'] ?? 'private')) === 'public';
}));
$ownedPrivateCount = $ownedProjectCount - $ownedPublicCount;
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
$statsCards = !$canBuild
  ? [
      ['count' => $publicCount, 'label' => 'Public', 'filterType' => 'visibility', 'filterValue' => 'public'],
      ['count' => $privateCount, 'label' => 'Secured', 'filterType' => 'visibility', 'filterValue' => 'private'],
    ]
  : [
      ['count' => $publicCount, 'label' => 'Public', 'filterType' => 'visibility', 'filterValue' => 'public'],
      ['count' => $viewerBuilderCount, 'label' => 'Viewer / Builder', 'filterType' => 'access', 'filterValue' => 'viewersorbuilder'],
      ['count' => $builderAdminCount, 'label' => 'Builder / Admin', 'filterType' => 'access', 'filterValue' => 'buildersoradmin'],
      ['count' => $ownerOnlyCount, 'label' => 'Owner Only', 'filterType' => 'access', 'filterValue' => 'adminsonly'],
    ];
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
      background: linear-gradient(180deg, var(--nav-bg) 0%, var(--nav-dark) 100%);
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
      overflow: hidden;
      border-radius: 16px;
      background: rgba(255,255,255,.16);
      color: #fff;
      font-family: "Google Sans", sans-serif;
      font-size: 26px;
      font-weight: 700;
    }

    .dashboard-brand-mark img {
      display: block;
      width: 100%;
      height: 100%;
      object-fit: cover;
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
      color: var(--brand);
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
      background: linear-gradient(135deg, var(--brand) 0%, var(--brand-bright) 100%);
      color: #fff;
      font-weight: 700;
      box-shadow: 0 14px 32px rgba(var(--brand-rgb), .22);
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

    .metric-trigger {
      width: 100%;
      text-align: left;
      cursor: pointer;
      transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease;
    }

    .metric-trigger:hover {
      transform: translateY(-1px);
      border-color: #b8cae8;
      box-shadow: 0 12px 28px rgba(19,35,63,.10);
    }

    .metric-trigger:focus-visible {
      outline: 3px solid rgba(var(--brand-rgb), .22);
      outline-offset: 2px;
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
      background: linear-gradient(135deg, var(--brand) 0%, var(--brand-bright) 100%);
      color: #fff;
      font-size: 36px;
      font-weight: 700;
      box-shadow: 0 16px 34px rgba(var(--brand-rgb), .24);
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
      background: linear-gradient(135deg, var(--brand) 0%, var(--brand-bright) 100%);
      color: #fff;
      font-size: 11px;
      font-weight: 700;
    }

    .workspace-strip.workspace-strip-wide {
      grid-template-columns: minmax(0, 1fr);
    }

    .clipboard-card-expanded {
      min-height: 420px;
    }

    .clipboard-card-expanded #clipboardText {
      min-height: 280px;
    }

    .stats-dialog {
      width: min(96vw, 980px);
      max-height: min(92vh, 860px);
      border-radius: 28px;
      overflow: hidden;
    }

    .stats-dialog-inner {
      max-height: min(92vh, 860px);
      overflow: hidden;
      background: linear-gradient(180deg, #ffffff 0%, #f7fbff 100%);
    }

    .stats-dialog-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 0 24px 18px;
      border-bottom: 1px solid var(--line);
    }

    .stats-filter-chip {
      display: inline-flex;
      align-items: center;
      min-height: 38px;
      padding: 0 14px;
      border-radius: 999px;
      background: var(--brand-soft);
      color: var(--brand-dark);
      font-size: 12px;
      font-weight: 700;
      letter-spacing: .04em;
      text-transform: uppercase;
    }

    .stats-search {
      flex: 1;
      max-width: 340px;
    }

    .stats-search input {
      width: 100%;
      min-height: 44px;
      padding: 0 14px;
      border-radius: 14px;
      border: 1px solid #dbe5f4;
      background: rgba(255,255,255,.96);
    }

    .stats-table-wrap {
      padding: 20px 24px 24px;
      overflow: auto;
    }

    .stats-table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
    }

    .stats-table th,
    .stats-table td {
      padding: 12px 14px;
      border-bottom: 1px solid #e7eef8;
      font-size: 13px;
      text-align: left;
      white-space: nowrap;
    }

    .stats-table td:last-child,
    .stats-table th:last-child {
      text-align: right;
    }

    .stats-sort {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      min-height: 34px;
      padding: 0 12px;
      border: 1px solid rgba(var(--brand-rgb), .16);
      border-radius: 999px;
      background: rgba(255,255,255,.94);
      color: var(--brand-dark);
      font-size: 11px;
      font-weight: 800;
      letter-spacing: .06em;
      text-transform: uppercase;
      cursor: pointer;
      box-shadow: inset 0 1px 0 rgba(255,255,255,.7);
      transition: border-color var(--ease), background var(--ease), box-shadow var(--ease), transform var(--ease);
    }

    .stats-sort:hover {
      transform: translateY(-1px);
      border-color: rgba(var(--brand-rgb), .3);
      background: rgba(var(--brand-rgb), .06);
      box-shadow: 0 10px 24px rgba(var(--brand-rgb), .12);
    }

    .stats-sort-indicator {
      font-size: 10px;
      color: rgba(var(--brand-rgb), .6);
    }

    .stats-table .status-pill {
      margin: 0;
    }

    .stats-table-empty {
      padding: 0 24px 24px;
      font-size: 13px;
      color: #8da0bf;
    }

    .audit-log-table-wrap {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }

    .audit-log-table {
      min-width: 720px;
    }

    .audit-log-table .code-mini {
      margin: 0;
      max-width: 420px;
      white-space: pre-wrap;
      overflow-wrap: anywhere;
      word-break: break-word;
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

      .stats-dialog {
        width: 100%;
        max-height: 100dvh;
        border-radius: 24px 24px 0 0;
      }

      .stats-dialog-inner {
        max-height: 100dvh;
      }

      .stats-dialog-toolbar,
      .stats-table-wrap,
      .stats-table-empty {
        padding-left: 16px;
        padding-right: 16px;
      }

      .stats-dialog-toolbar {
        flex-direction: column;
        align-items: stretch;
        padding-bottom: 14px;
      }

      .stats-search {
        max-width: none;
      }

      .stats-table th,
      .stats-table td {
        padding: 11px 10px;
        font-size: 12px;
      }

      .audit-log-table-wrap {
        overflow: visible;
      }

      .audit-log-table {
        min-width: 0;
      }

      .audit-log-table,
      .audit-log-table tbody,
      .audit-log-table tr,
      .audit-log-table td {
        display: block;
        width: 100%;
      }

      .audit-log-table thead {
        display: none;
      }

      .audit-log-table tr {
        padding: 12px 14px;
        border: 1px solid #dbe5f4;
        border-radius: 18px;
        background: #f9fbff;
        margin-bottom: 12px;
      }

      .audit-log-table td {
        display: grid;
        grid-template-columns: 86px minmax(0, 1fr);
        gap: 10px;
        padding: 7px 0;
        border-bottom: 1px solid #edf2fa;
        white-space: normal;
      }

      .audit-log-table td:last-child {
        border-bottom: 0;
      }

      .audit-log-table td::before {
        content: attr(data-label);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .05em;
        text-transform: uppercase;
        color: #8da0bf;
      }

      .audit-log-table .code-mini {
        max-width: none;
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
    <?= station_dashboard_nav_html('dashboard') ?>

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
        <?php foreach ($statsCards as $statCard): ?>
        <button class="metric-card metric-trigger" type="button" data-stats-filter-type="<?= station_h((string) $statCard['filterType']) ?>" data-stats-filter-value="<?= station_h((string) $statCard['filterValue']) ?>" data-stats-label="<?= station_h((string) $statCard['label']) ?>">
          <strong><?= (int) $statCard['count'] ?></strong>
          <span><?= station_h((string) $statCard['label']) ?></span>
        </button>
        <?php endforeach; ?>
      </section>

      <section class="workspace-strip<?= !$canBuild ? ' workspace-strip-wide' : '' ?>">
        <section class="workspace-card clipboard-card clipboard-card-compact clipboard-composer<?= !$canBuild ? ' clipboard-card-expanded' : '' ?>">
          <div class="section-head clipboard-section-head">
            <div>
              <h2>Clipboard</h2>
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
            <textarea id="clipboardText" rows="<?= $canBuild ? '6' : '10' ?>" placeholder="Paste text, links, notes, code, or instructions..."><?= station_h($clipboard) ?></textarea>
          </div>
          <div class="clip-files" id="clipFiles"></div>
          <div class="clip-status-row">
            <span class="clip-status" id="clipStatus"></span>
          </div>
        </section>

        <?php if ($canBuild): ?>
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
            <div class="mini-stat"><strong><?= $ownedProjectCount ?></strong><span>Projects</span></div>
            <div class="mini-stat"><strong><?= $ownedPublicCount ?></strong><span>Public</span></div>
            <div class="mini-stat"><strong><?= $ownedPrivateCount ?></strong><span>Private</span></div>
          </div>
        </section>
        <?php endif; ?>
      </section>

      <section class="projects-panel">
        <div class="projects-panel-head">
          <div>
            <p class="projects-kicker">Projects</p>
            <h2 class="projects-title">All Deployments <span class="count-badge"><?= $projectCount ?></span></h2>
          </div>
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
              $dockerConfig = isset($ps['docker']) && is_array($ps['docker']) ? $ps['docker'] : [];
              $dockerConfigured = $dockerConfig !== [] || is_file(station_project_path($slug) . '/docker-compose.yml');
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
                <a class="quick-link primary-link" href="launch.php?project=<?= urlencode($slug) ?>" target="_blank" rel="noreferrer">Launch ↗</a>
                <a class="quick-link" href="viewer.php?project=<?= urlencode($slug) ?>">Files</a>
                <?php if ($ghdevUrl !== ''): ?>
                  <a class="quick-link ghdev-link" href="<?= station_h($ghdevUrl) ?>" target="_blank" rel="noreferrer">github.dev ↗</a>
                <?php endif; ?>
                <?php if ($canBuild): ?>
                  <a class="quick-link" href="project-settings.php?project=<?= urlencode($slug) ?>">Settings</a>
                  <?php if (station_docker_enabled()): ?>
                    <?php if ($dockerConfigured): ?>
                    <details class="docker-menu" data-docker-menu data-project-slug="<?= station_h($slug) ?>">
                      <summary class="quick-link docker-trigger" data-docker-pill>
                        <span class="docker-dot" data-docker-dot></span>
                        <span data-docker-state-label>Deploy</span>
                      </summary>
                      <div class="docker-menu-body">
                        <form class="quick-action-form" method="post" action="docker-actions.php">
                          <input type="hidden" name="project" value="<?= station_h($slug) ?>">
                          <input type="hidden" name="action" value="start">
                          <button class="docker-menu-action" type="submit">▶ Start / Build</button>
                        </form>
                        <form class="quick-action-form" method="post" action="docker-actions.php">
                          <input type="hidden" name="project" value="<?= station_h($slug) ?>">
                          <input type="hidden" name="action" value="restart">
                          <button class="docker-menu-action" type="submit">↻ Restart</button>
                        </form>
                        <form class="quick-action-form" method="post" action="docker-actions.php">
                          <input type="hidden" name="project" value="<?= station_h($slug) ?>">
                          <input type="hidden" name="action" value="stop">
                          <button class="docker-menu-action" type="submit">■ Stop</button>
                        </form>
                        <a class="docker-menu-action" href="docker-config.php?project=<?= urlencode($slug) ?>">⚙ Configure</a>
                      </div>
                    </details>
                    <?php else: ?>
                      <a class="quick-link" href="docker-config.php?project=<?= urlencode($slug) ?>">Configure Docker</a>
                    <?php endif; ?>
                  <?php endif; ?>
                <?php endif; ?>
                <button class="quick-link share-btn" type="button" data-share-url="<?= station_h(station_project_serve_path($slug)) ?>">Share</button>
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
            <form class="form-grid" method="post">
              <h2>Activity Log</h2>
              <input type="hidden" name="action" value="update_audit_log_limit">
              <label>Entries to Keep<input type="number" name="audit_log_limit" min="50" max="200000" step="50" value="<?= station_h((string) $auditLogLimit) ?>" required></label>
              <p><a class="mini-link" href="admin-settings.php">More admin settings</a></p>
              <button type="submit">Save Retention</button>
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
              <div class="audit-log-table-wrap">
              <table class="audit-log-table">
                <thead><tr><th>Time</th><th>Event</th><th>User</th><th>Detail</th></tr></thead>
                <tbody>
                  <?php foreach ($recentEvents as $ev): ?>
                    <tr>
                      <td data-label="Time"><?= station_h(substr((string) ($ev['at'] ?? ''), 0, 19)) ?></td>
                      <td data-label="Event"><?= station_h((string) ($ev['event'] ?? '')) ?></td>
                      <td data-label="User"><?= station_h((string) ($ev['by'] ?? '')) ?></td>
                      <td data-label="Detail"><pre class="code-mini"><?= station_h(json_encode($ev['context'] ?? [], JSON_UNESCAPED_SLASHES) ?: '{}') ?></pre></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              </div>
            <?php endif; ?>
          </div>
        </details>
      </div>
      <?php endif; ?>
    </main>
  </div>

  <?php if ($canBuild): ?>
  <dialog id="newProjectDialog" class="modal-dialog create-project-modal">
    <div class="modal-inner create-project-modal-inner">
      <div class="modal-header">
        <div>
          <p class="kicker">Create workspace</p>
          <h2>Start New Project</h2>
        </div>
        <button class="modal-close-btn" type="button" id="closeNewProjectDialog">✕</button>
      </div>
      <div class="modal-tabs" role="tablist">
        <button class="tab-btn active" type="button" data-tab="zip">Upload Zip</button>
        <button class="tab-btn" type="button" data-tab="folder">Folder</button>
        <button class="tab-btn" type="button" data-tab="file">Single File</button>
        <button class="tab-btn" type="button" data-tab="template">Template</button>
        <?php if (!empty($adminSettings['githubEnabled'])): ?>
          <button class="tab-btn" type="button" data-tab="github">GitHub</button>
        <?php endif; ?>
      </div>
      <div class="tab-pane active" id="tab-zip">
        <div class="create-project-pane-head">
          <h3>Upload a zip archive</h3>
        </div>
        <form action="upload.php" method="post" enctype="multipart/form-data" class="form-grid create-project-form" data-upload-form="true">
          <input type="hidden" name="action" value="upload_zip">
          <label>Project Name<input type="text" name="project_name" class="project-name-input" placeholder="my-app" required></label>
          <label>Zip File<input type="file" name="zip_file" class="project-source-input" data-project-name-source="file" accept=".zip" required></label>
          <div class="upload-progress" hidden>
            <div class="upload-progress-bar"><span class="upload-progress-fill"></span></div>
            <div class="upload-progress-meta"><strong class="upload-progress-label">Preparing upload…</strong><span class="upload-progress-value">0%</span></div>
          </div>
          <button type="submit">Upload and deploy</button>
        </form>
      </div>
      <div class="tab-pane" id="tab-folder">
        <div class="create-project-pane-head">
          <h3>Upload a local folder</h3>
        </div>
        <form action="upload.php" method="post" enctype="multipart/form-data" class="form-grid create-project-form" data-upload-form="true">
          <input type="hidden" name="action" value="upload_folder">
          <label>Project Name<input type="text" name="project_name" class="project-name-input" placeholder="my-folder" required></label>
          <label>Folder<input type="file" name="folder_files[]" class="project-source-input project-folder-input" data-project-name-source="folder" webkitdirectory directory multiple required></label>
          <div class="upload-progress" hidden>
            <div class="upload-progress-bar"><span class="upload-progress-fill"></span></div>
            <div class="upload-progress-meta"><strong class="upload-progress-label">Preparing upload…</strong><span class="upload-progress-value">0%</span></div>
          </div>
          <button type="submit">Upload Folder</button>
        </form>
      </div>
      <div class="tab-pane" id="tab-file">
        <div class="create-project-pane-head">
          <h3>Start from one file</h3>
        </div>
        <form action="upload.php" method="post" enctype="multipart/form-data" class="form-grid create-project-form" data-upload-form="true">
          <input type="hidden" name="action" value="upload_single">
          <label>Project Name<input type="text" name="project_name" class="project-name-input" placeholder="single-file-project" required></label>
          <label>File<input type="file" name="single_file" class="project-source-input" data-project-name-source="file" required></label>
          <div class="upload-progress" hidden>
            <div class="upload-progress-bar"><span class="upload-progress-fill"></span></div>
            <div class="upload-progress-meta"><strong class="upload-progress-label">Preparing upload…</strong><span class="upload-progress-value">0%</span></div>
          </div>
          <button type="submit">Create Project</button>
        </form>
      </div>
      <div class="tab-pane" id="tab-template">
        <div class="create-project-pane-head">
          <h3>Generate from a starter</h3>
        </div>
        <form action="upload.php" method="post" class="form-grid create-project-form">
          <input type="hidden" name="action" value="create_template">
          <label>Project Name<input type="text" name="project_name" placeholder="starter-project" required></label>
          <label>Template
            <select name="template_type" required>
              <?php foreach ($templates as $tKey => $tLabel): ?>
                <option value="<?= station_h($tKey) ?>"><?= station_h($tLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php if ($isAdmin): ?><p><a class="mini-link" href="template-manager.php">Manage templates</a></p><?php endif; ?>
          <button type="submit">Create Starter</button>
        </form>
      </div>
      <?php if (!empty($adminSettings['githubEnabled'])): ?>
      <div class="tab-pane" id="tab-github">
        <div class="create-project-pane-head">
          <h3>Import from GitHub</h3>
        </div>
        <form action="upload.php" method="post" class="form-grid create-project-form">
          <input type="hidden" name="action" value="import_github">
          <label>Project Name<input type="text" name="project_name" placeholder="github-project" required></label>
          <label>Repository URL<input type="text" name="github_repo_url" placeholder="https://github.com/owner/repo" required></label>
          <label>Branch<input type="text" name="github_branch" placeholder="main"></label>
          <label>One-time Token<input type="password" name="github_token" placeholder="Leave blank to use saved GitHub token"></label>
          <?php if (station_docker_enabled()): ?>
            <label class="feature-toggle">
              <input type="checkbox" name="configure_docker_next" value="1" checked>
              <div class="feature-toggle-content">
                <span class="feature-toggle-title">Configure Docker services next</span>
                <span class="feature-toggle-desc">Choose databases and service credentials after the repo is cloned locally.</span>
              </div>
            </label>
          <?php endif; ?>
          <button type="submit">Import Repository</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </dialog>
  <?php endif; ?>

  <dialog id="statsDialog" class="modal-dialog stats-dialog">
    <div class="modal-inner stats-dialog-inner">
      <div class="modal-header">
        <div>
          <p class="kicker">Project table</p>
          <h2 id="statsDialogTitle">Projects</h2>
        </div>
        <button class="modal-close-btn" type="button" id="closeStatsDialog">✕</button>
      </div>
      <div class="stats-dialog-toolbar">
        <span class="stats-filter-chip" id="statsDialogFilterLabel">All visible</span>
        <label class="stats-search"><input id="statsDialogSearch" type="search" placeholder="Search project, owner, access, visibility, date..."></label>
      </div>
      <div class="stats-table-wrap">
        <table class="stats-table" id="statsTable">
          <thead>
            <tr>
              <th><button class="stats-sort" type="button" data-sort-key="slug">Project <span class="stats-sort-indicator">↕</span></button></th>
              <th><button class="stats-sort" type="button" data-sort-key="owner">Owner <span class="stats-sort-indicator">↕</span></button></th>
              <th><button class="stats-sort" type="button" data-sort-key="visibility">Visibility <span class="stats-sort-indicator">↕</span></button></th>
              <th><button class="stats-sort" type="button" data-sort-key="access">Access <span class="stats-sort-indicator">↕</span></button></th>
              <th><button class="stats-sort" type="button" data-sort-key="created">Created <span class="stats-sort-indicator">↕</span></button></th>
              <th>Open</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($projects as $project): ?>
              <?php
                $statsSlug = (string) ($project['slug'] ?? '');
                $statsOwner = (string) ($project['owner'] ?? 'unknown');
                $statsVisibility = (string) ($project['visibility'] ?? 'private');
                $statsAccess = (string) ($project['accessMode'] ?? 'admin');
                $statsCreatedIso = (string) ($project['createdAt'] ?? '');
                $statsCreated = substr($statsCreatedIso, 0, 10);
                $statsAccessLabel = (string) ($accessModes[$statsAccess] ?? $statsAccess);
                $statsSearch = strtolower(trim($statsSlug . ' ' . $statsOwner . ' ' . $statsVisibility . ' ' . $statsAccessLabel . ' ' . $statsCreated));
              ?>
            <tr data-search="<?= station_h($statsSearch) ?>" data-visibility="<?= station_h($statsVisibility) ?>" data-access-mode="<?= station_h($statsAccess) ?>" data-sort-slug="<?= station_h(strtolower($statsSlug)) ?>" data-sort-owner="<?= station_h(strtolower($statsOwner)) ?>" data-sort-visibility="<?= station_h($statsVisibility) ?>" data-sort-access="<?= station_h(strtolower($statsAccessLabel)) ?>" data-sort-created="<?= station_h($statsCreatedIso) ?>">
              <td><?= station_h($statsSlug) ?></td>
              <td><?= station_h($statsOwner) ?></td>
              <td><span class="status-pill <?= $statsVisibility === 'public' ? 'is-public' : 'is-private' ?>"><?= $statsVisibility === 'public' ? 'Public' : 'Private' ?></span></td>
              <td><?= station_h($statsAccessLabel) ?></td>
              <td><?= station_h($statsCreated) ?></td>
              <td><a class="quick-link" href="viewer.php?project=<?= urlencode($statsSlug) ?>">Files</a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="stats-table-empty" id="statsTableEmpty" hidden>No matching projects.</p>
    </div>
  </dialog>

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

    document.querySelectorAll('.project-source-input').forEach((input) => {
      input.addEventListener('change', function () {
        const form = this.closest('form');
        const projectNameInput = form ? form.querySelector('.project-name-input') : null;
        const firstFile = this.files && this.files[0] ? this.files[0] : null;
        if (!projectNameInput || !firstFile) {
          return;
        }

        let suggestedName = '';
        if (this.dataset.projectNameSource === 'folder') {
          const relativePath = typeof firstFile.webkitRelativePath === 'string' ? firstFile.webkitRelativePath : '';
          suggestedName = relativePath !== '' ? (relativePath.split('/')[0] || '') : '';
        } else {
          const rawName = typeof firstFile.name === 'string' ? firstFile.name : '';
          suggestedName = rawName.replace(/\.[^.]+$/, '');
        }

        if (suggestedName !== '') {
          projectNameInput.value = suggestedName;
        }
      });
    });

    document.querySelectorAll('.create-project-form[data-upload-form="true"]').forEach((form) => {
      form.addEventListener('submit', function (event) {
        event.preventDefault();

        const submitButton = this.querySelector('button[type="submit"]');
        const progressWrap = this.querySelector('.upload-progress');
        const progressFill = this.querySelector('.upload-progress-fill');
        const progressValue = this.querySelector('.upload-progress-value');
        const progressLabel = this.querySelector('.upload-progress-label');
        const formData = new FormData(this);
        const xhr = new XMLHttpRequest();

        if (submitButton) {
          submitButton.disabled = true;
          submitButton.dataset.originalLabel = submitButton.textContent || 'Submit';
          submitButton.textContent = 'Uploading…';
        }
        if (progressWrap) {
          progressWrap.hidden = false;
        }
        if (progressFill) {
          progressFill.style.width = '0%';
        }
        if (progressValue) {
          progressValue.textContent = '0%';
        }
        if (progressLabel) {
          progressLabel.textContent = 'Preparing upload…';
        }

        const formAction = this.getAttribute('action') || this.action || 'upload.php';

        xhr.open('POST', formAction, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.responseType = 'text';
        xhr.timeout = 120000;

        function readResponsePayload() {
          const raw = typeof xhr.responseText === 'string' ? xhr.responseText.trim() : '';
          if (!raw) {
            return null;
          }

          try {
            return JSON.parse(raw);
          } catch (_) {
            return {
              ok: false,
              isHtml: raw.startsWith('<'),
              raw,
              message: raw.startsWith('<') ? 'The server returned an unexpected page instead of a create-project response.' : raw
            };
          }
        }

        function handleHtmlFallback(response) {
          if (!response || !response.isHtml) {
            return false;
          }

          const responseUrl = typeof xhr.responseURL === 'string' ? xhr.responseURL : '';
          if (progressLabel) {
            progressLabel.textContent = 'The server returned a page. Opening it now…';
          }

          window.setTimeout(function () {
            if (responseUrl && responseUrl !== formAction) {
              window.location.href = responseUrl;
              return;
            }

            const popup = window.open('', '_self');
            if (popup && typeof response.raw === 'string') {
              popup.document.open();
              popup.document.write(response.raw);
              popup.document.close();
            }
          }, 150);

          return true;
        }

        function restoreSubmitButton() {
          if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = submitButton.dataset.originalLabel || 'Submit';
          }
        }

        xhr.upload.addEventListener('progress', function (progressEvent) {
          if (!progressEvent.lengthComputable) {
            return;
          }
          const percent = Math.max(0, Math.min(100, Math.round((progressEvent.loaded / progressEvent.total) * 100)));
          if (progressFill) {
            progressFill.style.width = percent + '%';
          }
          if (progressValue) {
            progressValue.textContent = percent + '%';
          }
          if (progressLabel) {
            progressLabel.textContent = percent < 100 ? 'Uploading files…' : 'Processing project…';
          }
        });

        xhr.upload.addEventListener('load', function () {
          if (progressLabel) {
            progressLabel.textContent = 'Processing project…';
          }
        });

        xhr.addEventListener('load', function () {
          const response = readResponsePayload();
          if (xhr.status >= 200 && xhr.status < 300 && response && response.ok) {
            if (progressFill) {
              progressFill.style.width = '100%';
            }
            if (progressValue) {
              progressValue.textContent = '100%';
            }
            if (progressLabel) {
              progressLabel.textContent = 'Upload complete. Opening project…';
            }
            window.location.href = response.redirectUrl || 'station.php';
            return;
          }

          if (handleHtmlFallback(response)) {
            restoreSubmitButton();
            return;
          }

          restoreSubmitButton();
          if (progressLabel) {
            const message = response && response.message
              ? response.message
              : ('Create project failed with HTTP ' + xhr.status + '.');
            progressLabel.textContent = message;
          }
        });

        xhr.addEventListener('error', function () {
          restoreSubmitButton();
          if (progressLabel) {
            progressLabel.textContent = 'Upload failed. Check your connection and try again.';
          }
        });

        xhr.addEventListener('timeout', function () {
          restoreSubmitButton();
          if (progressLabel) {
            progressLabel.textContent = 'Upload finished sending, but the server took too long to respond. Try a smaller upload or check PHP upload limits.';
          }
        });

        xhr.send(formData);
      });
    });

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

    const statsDlg = document.getElementById('statsDialog');
    const closeStatsBtn = document.getElementById('closeStatsDialog');
    const statsDialogTitle = document.getElementById('statsDialogTitle');
    const statsDialogFilterLabel = document.getElementById('statsDialogFilterLabel');
    const statsDialogSearch = document.getElementById('statsDialogSearch');
    const statsTable = document.getElementById('statsTable');
    const statsTableEmpty = document.getElementById('statsTableEmpty');
    let statsSortKey = 'created';
    let statsSortDirection = 'desc';
    let statsFilterType = '';
    let statsFilterValue = '';

    function updateStatsEmptyState() {
      if (!statsTable || !statsTableEmpty) {
        return;
      }
      const hasVisibleRows = Array.from(statsTable.querySelectorAll('tbody tr')).some((row) => !row.hidden);
      statsTableEmpty.hidden = hasVisibleRows;
    }

    function statsSortDatasetKey(key) {
      return 'sort' + key.charAt(0).toUpperCase() + key.slice(1);
    }

    function sortStatsRows() {
      if (!statsTable) {
        return;
      }
      const tbody = statsTable.querySelector('tbody');
      if (!tbody) {
        return;
      }
      const rows = Array.from(tbody.querySelectorAll('tr'));
      const datasetKey = statsSortDatasetKey(statsSortKey);
      rows.sort((left, right) => {
        const leftValue = left.dataset[datasetKey] || '';
        const rightValue = right.dataset[datasetKey] || '';
        const compare = leftValue.localeCompare(rightValue, undefined, { numeric: true, sensitivity: 'base' });
        return statsSortDirection === 'asc' ? compare : compare * -1;
      });
      rows.forEach((row) => tbody.appendChild(row));
    }

    function applyStatsTableState() {
      if (!statsTable) {
        return;
      }
      const query = statsDialogSearch ? statsDialogSearch.value.trim().toLowerCase() : '';
      statsTable.querySelectorAll('tbody tr').forEach((row) => {
        const matchesFilter = !statsFilterType || (statsFilterType === 'visibility'
          ? (row.dataset.visibility || '') === statsFilterValue
          : (row.dataset.accessMode || '') === statsFilterValue);
        const matchesSearch = !query || (row.dataset.search || '').includes(query);
        row.hidden = !(matchesFilter && matchesSearch);
      });
      sortStatsRows();
      updateStatsEmptyState();
    }

    document.querySelectorAll('.metric-trigger').forEach((card) => {
      card.addEventListener('click', function () {
        statsFilterType = this.dataset.statsFilterType || '';
        statsFilterValue = this.dataset.statsFilterValue || '';
        const label = this.dataset.statsLabel || 'Projects';
        if (statsDialogTitle) {
          statsDialogTitle.textContent = label + ' Projects';
        }
        if (statsDialogFilterLabel) {
          statsDialogFilterLabel.textContent = label;
        }
        if (statsDialogSearch) {
          statsDialogSearch.value = '';
        }
        applyStatsTableState();
        if (statsDlg) {
          statsDlg.showModal();
        }
      });
    });

    if (statsDlg) {
      statsDlg.addEventListener('click', function (event) {
        if (event.target === statsDlg) {
          statsDlg.close();
        }
      });
    }
    if (closeStatsBtn && statsDlg) {
      closeStatsBtn.addEventListener('click', function () {
        statsDlg.close();
      });
    }
    if (statsDialogSearch) {
      statsDialogSearch.addEventListener('input', applyStatsTableState);
    }
    document.querySelectorAll('.stats-sort').forEach((button) => {
      button.addEventListener('click', function () {
        const nextKey = this.dataset.sortKey || 'created';
        if (statsSortKey === nextKey) {
          statsSortDirection = statsSortDirection === 'asc' ? 'desc' : 'asc';
        } else {
          statsSortKey = nextKey;
          statsSortDirection = nextKey === 'created' ? 'desc' : 'asc';
        }
        applyStatsTableState();
      });
    });

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

    /* ── Live Docker status ── */
    const dockerMenus = Array.from(document.querySelectorAll('[data-docker-menu]'));
    if (dockerMenus.length > 0) {
      const stateMeta = {
        running: { label: 'Running', tone: 'ok' },
        partial: { label: 'Partial', tone: 'warn' },
        stopped: { label: 'Stopped', tone: 'bad' },
        unknown: { label: 'Deploy', tone: 'idle' },
        unavailable: { label: 'Docker offline', tone: 'bad' },
        unconfigured: { label: 'Deploy', tone: 'idle' }
      };

      function applyDockerState(menu, state) {
        const meta = stateMeta[state] || stateMeta.unknown;
        const label = menu.querySelector('[data-docker-state-label]');
        const trigger = menu.querySelector('[data-docker-pill]');
        if (label) { label.textContent = meta.label; }
        if (trigger) {
          trigger.dataset.tone = meta.tone;
        }
      }

      function pollDockerStatus() {
        const slugs = dockerMenus.map((m) => m.dataset.projectSlug || '').filter(Boolean);
        if (slugs.length === 0) { return; }
        fetch('docker-status.php?projects=' + encodeURIComponent(slugs.join(',')), { credentials: 'same-origin' })
          .then((r) => r.json())
          .then((payload) => {
            if (!payload || !payload.projects) { return; }
            dockerMenus.forEach((menu) => {
              const slug = menu.dataset.projectSlug || '';
              const entry = payload.projects[slug];
              if (entry && entry.state) {
                applyDockerState(menu, entry.state);
              } else if (payload.engine === false) {
                applyDockerState(menu, 'unavailable');
              }
            });
          })
          .catch(() => {});
      }

      pollDockerStatus();
      window.setInterval(pollDockerStatus, 15000);

      // Refresh when any docker action form is submitted, so the pill updates faster.
      document.querySelectorAll('.docker-menu form').forEach((form) => {
        form.addEventListener('submit', () => {
          window.setTimeout(pollDockerStatus, 2500);
          window.setTimeout(pollDockerStatus, 8000);
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
    let clipRevision = '0';
    let clipDirty = false;
    let clipLastAppliedContent = clipText ? clipText.value : '';
    let clipPollTimer = null;

    function setStatus(msg) {
      if (clipStatus) { clipStatus.textContent = msg; }
    }

    function scheduleClipboardPolling() {
      if (clipPollTimer) {
        return;
      }
      clipPollTimer = window.setInterval(refreshClipboard, 1000);
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

      setStatus('Uploading attachment…');

      fetch('clipboard.php', { method: 'POST', body: fd })
        .then((r) => r.json())
        .then((d) => {
          if (!d.ok) {
            setStatus('Upload failed');
            setTimeout(() => setStatus(''), 2000);
            return;
          }
          clipRevision = String(d.revision || clipRevision || '0');
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
          applyClipboardPayload(d, false);
        })
        .catch(() => {});
    }

    function applyClipboardPayload(payload, fromStream) {
      if (!payload || !payload.ok) {
        return;
      }

      clipRevision = String(payload.revision || clipRevision || '0');

      if (clipText && typeof payload.content === 'string' && (!clipDirty || payload.content === clipText.value)) {
        const active = document.activeElement === clipText;
        const selectionStart = clipText.selectionStart;
        const selectionEnd = clipText.selectionEnd;

        clipText.value = payload.content;
        clipLastAppliedContent = payload.content;

        if (active && typeof selectionStart === 'number' && typeof selectionEnd === 'number') {
          clipText.setSelectionRange(selectionStart, selectionEnd);
        }
      }

      renderFiles(payload.files || []);

      if (fromStream && !clipDirty) {
        setStatus('Live');
        window.clearTimeout(clipTimer);
        clipTimer = window.setTimeout(() => {
          if (!clipDirty) {
            setStatus('');
          }
        }, 1200);
      }
    }

    function saveClipboard() {
      const content = clipText ? clipText.value : '';
      fetch('clipboard.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=save&content=' + encodeURIComponent(content)
      }).then((r) => r.json())
        .then((d) => {
          if (!d.ok) {
            setStatus('Save failed');
            setTimeout(() => setStatus(''), 2000);
            return;
          }
          clipDirty = false;
          clipRevision = String(d.revision || clipRevision || '0');
          clipLastAppliedContent = typeof d.content === 'string' ? d.content : content;
          setStatus('Live');
          setTimeout(() => {
            if (!clipDirty) {
              setStatus('');
            }
          }, 1200);
        })
        .catch(() => { setStatus('Error'); setTimeout(() => setStatus(''), 2000); });
    }

    if (clipText) {
      clipText.addEventListener('input', () => {
        clearTimeout(clipTimer);
        clipDirty = true;
        setStatus('Syncing…');
        clipTimer = setTimeout(saveClipboard, 300);
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
          .then((d) => {
            clipDirty = false;
            clipRevision = String((d && d.revision) || clipRevision || '0');
            clipLastAppliedContent = '';
            renderFiles([]);
            setStatus('Cleared');
            setTimeout(() => setStatus(''), 1500);
          })
          .catch(() => { setStatus('Error'); setTimeout(() => setStatus(''), 1500); });
      });
    }
    refreshClipboard();
    scheduleClipboardPolling();
  })();
  </script>
  <?= station_clipboard_fab_html() ?>
  <?= station_pwa_register_html() ?>
</body>
</html>
