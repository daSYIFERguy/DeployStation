<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/templates.php';

station_require_login();
$currentUser = station_current_user();
if (!station_is_admin($currentUser)) {
    station_flash_set('error', 'Admin access required.');
    header('Location: station.php');
    exit;
}

function station_template_manager_normalize_files(array $files): array
{
    $normalized = [];
    foreach ($files as $path => $content) {
        if (!is_string($path) || !is_string($content)) {
            continue;
        }
        $relative = trim(str_replace('\\', '/', $path));
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
            continue;
        }
        $normalized[$relative] = $content;
    }
    return $normalized;
}

$settings = station_admin_settings();
$customTemplates = station_custom_template_definitions();
$builtInTemplates = station_builtin_template_catalog();
$error = '';
$ok = station_flash_get('ok');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save_template');

    if ($action === 'delete_template') {
        $templateKey = station_safe_name((string) ($_POST['template_key'] ?? ''));
        if ($templateKey === '' || !isset($customTemplates[$templateKey])) {
            $error = 'Template not found.';
        } else {
            unset($customTemplates[$templateKey]);
            $settings['customTemplates'] = $customTemplates;
            if (station_save_admin_settings($settings)) {
                station_flash_set('ok', 'Template deleted.');
                header('Location: template-manager.php');
                exit;
            }
            $error = 'Could not delete template.';
        }
    }

    if ($action === 'save_template') {
        $templateKey = station_safe_name((string) ($_POST['template_key'] ?? ''));
        $label = trim((string) ($_POST['template_label'] ?? ''));
        $filesJson = trim((string) ($_POST['template_files_json'] ?? ''));
        $files = json_decode($filesJson, true);

        if ($templateKey === '') {
            $error = 'Template key is required.';
        } elseif (isset($builtInTemplates[$templateKey])) {
            $error = 'That key is reserved by a built-in template.';
        } elseif ($label === '') {
            $error = 'Template label is required.';
        } elseif (!is_array($files)) {
            $error = 'Template files must be valid JSON in object form.';
        } else {
            $normalizedFiles = station_template_manager_normalize_files($files);
            if ($normalizedFiles === []) {
                $error = 'Add at least one valid file path and file body.';
            } else {
                $customTemplates[$templateKey] = [
                    'label' => $label,
                    'files' => $normalizedFiles,
                    'updatedAt' => gmdate('c')
                ];
                $settings['customTemplates'] = $customTemplates;
                if (station_save_admin_settings($settings)) {
                    station_flash_set('ok', 'Template saved.');
                    header('Location: template-manager.php?edit=' . urlencode($templateKey));
                    exit;
                }
                $error = 'Could not save template.';
            }
        }
    }
}

$editKey = station_safe_name((string) ($_GET['edit'] ?? ''));
$editingTemplate = $editKey !== '' && isset($customTemplates[$editKey]) ? $customTemplates[$editKey] : null;
$editingFilesJson = $editingTemplate !== null
    ? json_encode($editingTemplate['files'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    : json_encode([
        'index.html' => "<!doctype html>\n<html lang=\"en\">\n<head>\n  <meta charset=\"utf-8\">\n  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n  <title>{{PROJECT_NAME}}</title>\n</head>\n<body>\n  <h1>{{PROJECT_NAME}}</h1>\n</body>\n</html>\n"
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('Template Manager', 'Create and maintain custom starter templates for new projects.') ?>
</head>
<body class="station-body">
  <main class="station-shell">
    <header class="topbar card">
      <div>
        <p class="kicker">Admin Tools</p>
        <h1>Template Manager</h1>
        <p>Create custom starter templates. Use `{{PROJECT_NAME}}` and `{{PROJECT_SLUG}}` placeholders inside file contents.</p>
      </div>
      <nav class="nav-pills">
        <a href="station.php">Dashboard</a>
        <a href="admin-settings.php">Settings</a>
      </nav>
    </header>

    <?php if ($ok !== ''): ?><div class="alert ok"><?= station_h($ok) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert error"><?= station_h($error) ?></div><?php endif; ?>

    <section class="grid-two">
      <section class="card form-grid">
        <h2><?= $editingTemplate !== null ? 'Edit Custom Template' : 'Add Custom Template' ?></h2>
        <form method="post" class="form-grid">
          <input type="hidden" name="action" value="save_template">
          <label>Template Key
            <input type="text" name="template_key" value="<?= station_h($editKey) ?>" placeholder="marketing-site" <?= $editingTemplate !== null ? 'readonly' : 'required' ?>>
          </label>
          <label>Template Label
            <input type="text" name="template_label" value="<?= station_h((string) ($editingTemplate['label'] ?? '')) ?>" placeholder="Marketing Site" required>
          </label>
          <label>Files JSON
            <textarea name="template_files_json" rows="18" spellcheck="false" placeholder='{"index.html":"..."}'><?= station_h((string) $editingFilesJson) ?></textarea>
          </label>
          <p class="section-note">Keys become the option value in the project creator. File paths must be relative, like `src/index.js` or `README.md`.</p>
          <button type="submit">Save Template</button>
        </form>
      </section>

      <section class="card form-grid">
        <h2>Current Templates</h2>
        <p class="section-note">Built-in templates are read-only. Custom templates appear in the New Project dialog immediately after saving.</p>
        <div>
          <h3>Built-in</h3>
          <div class="clip-files">
            <?php foreach ($builtInTemplates as $key => $label): ?>
              <span class="icon-pill"><span class="icon-pill-symbol">+</span><?= station_h($label) ?> <span class="section-note"><?= station_h($key) ?></span></span>
            <?php endforeach; ?>
          </div>
        </div>
        <div>
          <h3>Custom</h3>
          <?php if ($customTemplates === []): ?>
            <p>No custom templates yet.</p>
          <?php else: ?>
            <?php foreach ($customTemplates as $key => $template): ?>
              <div class="card form-grid">
                <div class="action-row">
                  <strong><?= station_h((string) ($template['label'] ?? $key)) ?></strong>
                  <span class="section-note"><?= station_h($key) ?></span>
                </div>
                <pre class="code-mini"><?= station_h(json_encode($template['files'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') ?></pre>
                <div class="action-row">
                  <a class="quick-link" href="template-manager.php?edit=<?= urlencode($key) ?>">Edit</a>
                  <form method="post" class="inline-form">
                    <input type="hidden" name="action" value="delete_template">
                    <input type="hidden" name="template_key" value="<?= station_h($key) ?>">
                    <button type="submit" class="secondary-btn">Delete</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </section>
    </section>
  </main>
  <?= station_pwa_register_html() ?>
</body>
</html>