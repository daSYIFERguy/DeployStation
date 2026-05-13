<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/templates.php';
require_once __DIR__ . '/lib/docker.php';

station_require_login();
$currentUser = station_current_user();
if (!station_is_admin($currentUser)) {
    station_flash_set('error', 'Admin access required.');
    header('Location: station.php');
    exit;
}

/**
 * Normalize a posted files map (associative array path → contents)
 * stripping unsafe paths.
 *
 * @param array<string, mixed> $files
 * @return array<string, string>
 */
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

/**
 * Accept the posted files form (either parallel arrays or a JSON
 * fallback). Returns [path => content].
 *
 * @return array<string, string>
 */
function station_template_manager_collect_posted_files(): array
{
    $files = [];
    $paths = $_POST['file_paths'] ?? null;
    $bodies = $_POST['file_bodies'] ?? null;
    if (is_array($paths) && is_array($bodies)) {
        $len = max(count($paths), count($bodies));
        for ($i = 0; $i < $len; $i++) {
            $path = isset($paths[$i]) ? (string) $paths[$i] : '';
            $body = isset($bodies[$i]) ? (string) $bodies[$i] : '';
            if ($path === '') {
                continue;
            }
            $files[$path] = $body;
        }
        return station_template_manager_normalize_files($files);
    }

    // Fallback — JSON blob (used by older flow or programmatic clients).
    $json = trim((string) ($_POST['template_files_json'] ?? ''));
    if ($json !== '') {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            return station_template_manager_normalize_files($decoded);
        }
    }
    return [];
}

$settings = station_admin_settings();
$customTemplates = isset($settings['customTemplates']) && is_array($settings['customTemplates']) ? $settings['customTemplates'] : [];
$dockerServices = station_docker_services();
$availableStacks = ['static', 'node', 'php', 'python', 'other'];

$error = '';
$ok = station_flash_get('ok');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save_template');

    if ($action === 'delete_template') {
        $templateKey = station_safe_name((string) ($_POST['template_key'] ?? ''));
        if ($templateKey === '' || !isset($customTemplates[$templateKey])) {
            $error = 'Custom template not found.';
        } else {
            unset($customTemplates[$templateKey]);
            $settings['customTemplates'] = $customTemplates;
            if (station_save_admin_settings($settings)) {
                station_flash_set('ok', 'Custom template deleted.');
                header('Location: template-manager.php');
                exit;
            }
            $error = 'Could not delete custom template.';
        }
    } elseif ($action === 'duplicate_template') {
        $sourceKey = (string) ($_POST['template_key'] ?? '');
        $builtIn = station_builtin_template_definitions();
        if (!isset($builtIn[$sourceKey])) {
            $error = 'Source template not found.';
        } else {
            $copyKey = station_safe_name($sourceKey . '-custom');
            $suffix = 2;
            while (isset($customTemplates[$copyKey])) {
                $copyKey = station_safe_name($sourceKey . '-custom-' . $suffix);
                $suffix++;
            }
            $source = $builtIn[$sourceKey];
            $customTemplates[$copyKey] = [
                'label' => (string) ($source['label'] ?? $sourceKey) . ' (Custom)',
                'description' => (string) ($source['description'] ?? ''),
                'icon' => (string) ($source['icon'] ?? '🧰'),
                'stack' => (string) ($source['stack'] ?? 'other'),
                'appPort' => (int) ($source['appPort'] ?? 80),
                'recommendedServices' => array_values((array) ($source['recommendedServices'] ?? [])),
                'files' => isset($source['files']) && is_array($source['files']) ? $source['files'] : [],
                'updatedAt' => gmdate('c'),
            ];
            $settings['customTemplates'] = $customTemplates;
            if (station_save_admin_settings($settings)) {
                station_flash_set('ok', 'Template duplicated. Now editable.');
                header('Location: template-manager.php?edit=' . urlencode($copyKey));
                exit;
            }
            $error = 'Could not duplicate template.';
        }
    } elseif ($action === 'save_template') {
        $templateKey = station_safe_name((string) ($_POST['template_key'] ?? ''));
        $label = trim((string) ($_POST['template_label'] ?? ''));
        $description = trim((string) ($_POST['template_description'] ?? ''));
        $icon = trim((string) ($_POST['template_icon'] ?? '🧰'));
        $stack = (string) ($_POST['template_stack'] ?? 'other');
        if (!in_array($stack, $availableStacks, true)) {
            $stack = 'other';
        }
        $appPort = (int) ($_POST['template_app_port'] ?? 80);
        if ($appPort < 1 || $appPort > 65535) {
            $appPort = 80;
        }
        $recommended = [];
        $postedRecommended = $_POST['recommended_services'] ?? [];
        if (is_array($postedRecommended)) {
            foreach ($postedRecommended as $svc) {
                if (!is_string($svc) || $svc === '') {
                    continue;
                }
                if (isset($dockerServices[$svc]) || $svc === 'none') {
                    if ($svc !== 'none') {
                        $recommended[] = $svc;
                    }
                }
            }
        }
        $normalizedFiles = station_template_manager_collect_posted_files();

        $builtInDefinitions = station_builtin_template_definitions();

        if ($templateKey === '') {
            $error = 'Template key is required.';
        } elseif (isset($builtInDefinitions[$templateKey]) && !isset($customTemplates[$templateKey])) {
            $error = 'That key is reserved by a built-in template. Use "Duplicate to custom" to create an editable copy.';
        } elseif ($label === '') {
            $error = 'Template label is required.';
        } elseif ($normalizedFiles === []) {
            $error = 'Add at least one valid file path and file body.';
        } else {
            $customTemplates[$templateKey] = [
                'label' => $label,
                'description' => $description,
                'icon' => $icon !== '' ? $icon : '🧰',
                'stack' => $stack,
                'appPort' => $appPort,
                'recommendedServices' => array_values(array_unique($recommended)),
                'files' => $normalizedFiles,
                'updatedAt' => gmdate('c'),
            ];
            $settings['customTemplates'] = $customTemplates;
            if (station_save_admin_settings($settings)) {
                station_flash_set('ok', 'Custom template saved.');
                header('Location: template-manager.php?edit=' . urlencode($templateKey));
                exit;
            }
            $error = 'Could not save custom template.';
        }
    }
}

$allDefinitions = station_template_definitions();

$activeKey = (string) ($_GET['view'] ?? $_GET['edit'] ?? '');
if ($activeKey === '' && $allDefinitions !== []) {
    $activeKey = array_key_first($allDefinitions);
}
$editing = isset($_GET['edit']);
$activeDefinition = $activeKey !== '' ? ($allDefinitions[$activeKey] ?? null) : null;
$activeIsCustom = $activeDefinition !== null && !empty($activeDefinition['custom']);

$showEditor = $editing && $activeIsCustom;
$showNewEditor = $activeKey === '__new__' || (isset($_GET['edit']) && $_GET['edit'] === '__new__');
if ($showNewEditor) {
    $activeDefinition = [
        'key' => '',
        'label' => '',
        'description' => '',
        'icon' => '🧰',
        'stack' => 'static',
        'appPort' => 80,
        'recommendedServices' => [],
        'files' => [
            'index.html' => "<!doctype html>\n<html lang=\"en\">\n<head>\n  <meta charset=\"utf-8\">\n  <title>{{PROJECT_NAME}}</title>\n</head>\n<body>\n  <h1>{{PROJECT_NAME}}</h1>\n</body>\n</html>\n",
            'Dockerfile' => "FROM nginx:alpine\nCOPY . /usr/share/nginx/html\nEXPOSE 80\n",
        ],
        'custom' => true,
    ];
    $activeIsCustom = true;
    $showEditor = true;
}

// Sort definitions: built-in first, then custom, alphabetical inside each.
uasort($allDefinitions, static function ($a, $b): int {
    $aCustom = !empty($a['custom']) ? 1 : 0;
    $bCustom = !empty($b['custom']) ? 1 : 0;
    if ($aCustom !== $bCustom) {
        return $aCustom <=> $bCustom;
    }
    return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
});

/**
 * Build a hierarchical file-tree representation from a flat
 * [path => content] map. Returns sortable nested array.
 */
function station_template_build_tree(array $files): array
{
    $root = [];
    ksort($files);
    foreach (array_keys($files) as $path) {
        $segments = explode('/', $path);
        $cursor = &$root;
        $depth = count($segments);
        foreach ($segments as $i => $segment) {
            if ($segment === '') {
                continue;
            }
            $isFile = $i === $depth - 1;
            if (!isset($cursor[$segment])) {
                $cursor[$segment] = [
                    'name' => $segment,
                    'isFile' => $isFile,
                    'path' => $isFile ? $path : '',
                    'children' => [],
                ];
            }
            $cursor = &$cursor[$segment]['children'];
        }
        unset($cursor);
    }
    return $root;
}

function station_template_render_tree(array $nodes, int $indent = 0): string
{
    if ($nodes === []) {
        return '';
    }
    ksort($nodes);
    $html = '<ul class="template-tree-list" data-depth="' . $indent . '">';
    foreach ($nodes as $node) {
        $name = (string) ($node['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $isFile = !empty($node['isFile']);
        if ($isFile) {
            $path = (string) ($node['path'] ?? '');
            $html .= '<li class="template-tree-item template-tree-file"><button type="button" class="template-tree-link" data-template-file="' . station_h($path) . '"><span class="template-tree-icon">📄</span><span>' . station_h($name) . '</span></button></li>';
        } else {
            $html .= '<li class="template-tree-item template-tree-folder"><details open><summary><span class="template-tree-icon">📁</span><span>' . station_h($name) . '</span></summary>';
            $html .= station_template_render_tree((array) ($node['children'] ?? []), $indent + 1);
            $html .= '</details></li>';
        }
    }
    $html .= '</ul>';
    return $html;
}

$activeFiles = $activeDefinition !== null && isset($activeDefinition['files']) && is_array($activeDefinition['files'])
    ? $activeDefinition['files']
    : [];
$firstFilePath = '';
foreach (array_keys($activeFiles) as $candidatePath) {
    $firstFilePath = (string) $candidatePath;
    break;
}
?>
<!doctype html>
<html lang="en">
<head>
  <?= station_pwa_head_html('Template Manager', 'Browse, duplicate, and edit project starter templates.') ?>
</head>
<body class="station-body">
  <div class="dashboard-shell">
    <?= station_dashboard_nav_html('templates') ?>
    <main class="dashboard-main">
      <header class="dashboard-topbar">
        <div>
          <p class="dashboard-kicker">Admin Tools</p>
          <h1 class="dashboard-heading">Template Manager</h1>
          <p class="dashboard-subheading">Browse, duplicate, and edit project starter templates. Built-in templates are read-only — use “Duplicate to custom” to make an editable copy.</p>
        </div>
        <nav class="nav-pills">
          <a href="station.php">← Dashboard</a>
          <a href="admin-settings.php">Settings</a>
          <a href="template-manager.php?edit=__new__" class="btn-primary">+ New custom template</a>
        </nav>
      </header>

      <?php if ($ok !== ''): ?><div class="alert ok"><?= station_h($ok) ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="alert error"><?= station_h($error) ?></div><?php endif; ?>

      <section class="settings-shell template-manager-shell">
        <aside class="settings-nav template-list" aria-label="Template list">
          <p class="template-list-section">Built-in</p>
          <?php foreach ($allDefinitions as $key => $def): if (!empty($def['custom'])) continue; ?>
            <a href="template-manager.php?view=<?= urlencode($key) ?>" class="settings-nav-item template-list-item <?= $activeKey === $key && !$showEditor ? 'active' : '' ?>">
              <span class="settings-nav-icon"><?= station_h((string) ($def['icon'] ?? '🧰')) ?></span>
              <span class="template-list-item-text">
                <span><?= station_h((string) ($def['label'] ?? $key)) ?></span>
                <span class="template-list-item-meta"><?= station_h((string) ($def['stack'] ?? 'other')) ?> · <?= count((array) ($def['files'] ?? [])) ?> file<?= count((array) ($def['files'] ?? [])) === 1 ? '' : 's' ?></span>
              </span>
            </a>
          <?php endforeach; ?>

          <p class="template-list-section">Custom</p>
          <?php $hasCustom = false; foreach ($allDefinitions as $key => $def): if (empty($def['custom'])) continue; $hasCustom = true; ?>
            <a href="template-manager.php?edit=<?= urlencode($key) ?>" class="settings-nav-item template-list-item <?= $activeKey === $key ? 'active' : '' ?>">
              <span class="settings-nav-icon"><?= station_h((string) ($def['icon'] ?? '🧰')) ?></span>
              <span class="template-list-item-text">
                <span><?= station_h((string) ($def['label'] ?? $key)) ?></span>
                <span class="template-list-item-meta">custom · <?= count((array) ($def['files'] ?? [])) ?> file<?= count((array) ($def['files'] ?? [])) === 1 ? '' : 's' ?></span>
              </span>
            </a>
          <?php endforeach; if (!$hasCustom): ?>
            <p class="template-list-empty">No custom templates yet. Duplicate a built-in to start.</p>
          <?php endif; ?>
        </aside>

        <div class="settings-content template-manager-content">
          <?php if ($activeDefinition === null): ?>
            <section class="settings-panel">
              <div class="settings-panel-head">
                <h1 class="settings-panel-heading">No template selected</h1>
                <p class="settings-panel-subtitle">Pick a template from the left, or create a new custom template.</p>
              </div>
            </section>
          <?php elseif ($showEditor): ?>
            <form method="post" class="settings-panel template-editor-panel" data-template-editor>
              <input type="hidden" name="action" value="save_template">
              <div class="settings-panel-head">
                <div>
                  <h1 class="settings-panel-heading"><?= $showNewEditor ? 'New custom template' : 'Edit custom template' ?></h1>
                  <p class="settings-panel-subtitle">Use <code>{{PROJECT_NAME}}</code> and <code>{{PROJECT_SLUG}}</code> placeholders inside file paths and contents. They are substituted at create time.</p>
                </div>
                <?php if (!$showNewEditor): ?>
                  <button type="button" class="danger-btn" onclick="if (confirm('Delete this custom template?')) { document.getElementById('template-delete-form').submit(); }">Delete</button>
                <?php endif; ?>
              </div>

              <div class="settings-form-group grid-2">
                <div class="setting-item">
                  <label class="setting-label">Template Key</label>
                  <input type="text" name="template_key" value="<?= station_h((string) ($activeDefinition['key'] ?? $activeKey)) ?>" <?= $showNewEditor ? 'required placeholder="my-stack"' : 'readonly' ?>>
                  <p class="setting-description">Used as the dropdown value when creating a project. Lowercase letters, digits, and dashes.</p>
                </div>
                <div class="setting-item">
                  <label class="setting-label">Label</label>
                  <input type="text" name="template_label" value="<?= station_h((string) ($activeDefinition['label'] ?? '')) ?>" required placeholder="My Stack">
                </div>
                <div class="setting-item">
                  <label class="setting-label">Icon (emoji)</label>
                  <input type="text" name="template_icon" value="<?= station_h((string) ($activeDefinition['icon'] ?? '🧰')) ?>" maxlength="8">
                </div>
                <div class="setting-item">
                  <label class="setting-label">Stack</label>
                  <select name="template_stack">
                    <?php foreach ($availableStacks as $stackOption): ?>
                      <option value="<?= station_h($stackOption) ?>" <?= ((string) ($activeDefinition['stack'] ?? 'other')) === $stackOption ? 'selected' : '' ?>><?= station_h($stackOption) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="setting-item">
                  <label class="setting-label">App container port</label>
                  <input type="number" name="template_app_port" min="1" max="65535" value="<?= station_h((string) ($activeDefinition['appPort'] ?? 80)) ?>">
                  <p class="setting-description">Default port to set on the project's docker config (80, 3000, 8000, …).</p>
                </div>
                <div class="setting-item">
                  <label class="setting-label">Description</label>
                  <textarea name="template_description" rows="2" placeholder="One-line description shown in the picker."><?= station_h((string) ($activeDefinition['description'] ?? '')) ?></textarea>
                </div>
              </div>

              <fieldset class="settings-form-group" style="margin-top: 18px;">
                <legend class="setting-label">Recommended services</legend>
                <p class="setting-description">Pre-checked when a user creates a project from this template.</p>
                <div class="template-services-grid">
                  <?php $currentRecommended = (array) ($activeDefinition['recommendedServices'] ?? []); ?>
                  <?php foreach ($dockerServices as $svcKey => $svc): ?>
                    <label class="template-service-toggle">
                      <input type="checkbox" name="recommended_services[]" value="<?= station_h($svcKey) ?>" <?= in_array($svcKey, $currentRecommended, true) ? 'checked' : '' ?>>
                      <span><?= station_h((string) ($svc['icon'] ?? '')) ?> <?= station_h((string) ($svc['name'] ?? $svcKey)) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </fieldset>

              <div class="settings-panel template-files-editor">
                <div class="settings-panel-head" style="margin-bottom:12px;">
                  <h2 class="settings-panel-heading" style="font-size:18px;">Files</h2>
                  <p class="settings-panel-subtitle">Each file becomes a real file in the new project directory.</p>
                </div>
                <div class="template-file-rows" id="templateFileRows">
                  <?php $idx = 0; foreach ($activeFiles as $relativePath => $contents): ?>
                    <div class="template-file-row" data-row-index="<?= (int) $idx ?>">
                      <div class="template-file-row-head">
                        <input type="text" name="file_paths[]" value="<?= station_h((string) $relativePath) ?>" class="template-file-path" placeholder="src/main.js">
                        <button type="button" class="secondary-btn template-file-remove" data-row-remove>Remove</button>
                      </div>
                      <textarea name="file_bodies[]" rows="10" spellcheck="false" class="template-file-body"><?= station_h((string) $contents) ?></textarea>
                    </div>
                  <?php $idx++; endforeach; ?>
                </div>
                <button type="button" class="secondary-btn" id="templateAddFile">+ Add file</button>
              </div>

              <div class="action-row" style="margin-top:18px;gap:12px;">
                <button type="submit" class="btn-primary">Save template</button>
                <a class="quick-link" href="template-manager.php?view=<?= urlencode((string) ($activeDefinition['key'] ?? $activeKey)) ?>">Cancel</a>
              </div>
            </form>

            <?php if (!$showNewEditor): ?>
              <form id="template-delete-form" method="post" style="display:none;">
                <input type="hidden" name="action" value="delete_template">
                <input type="hidden" name="template_key" value="<?= station_h((string) ($activeDefinition['key'] ?? $activeKey)) ?>">
              </form>
            <?php endif; ?>
          <?php else: ?>
            <section class="settings-panel template-viewer-panel" data-template-viewer>
              <div class="settings-panel-head">
                <div>
                  <h1 class="settings-panel-heading">
                    <span class="template-viewer-icon"><?= station_h((string) ($activeDefinition['icon'] ?? '🧰')) ?></span>
                    <?= station_h((string) ($activeDefinition['label'] ?? $activeKey)) ?>
                  </h1>
                  <p class="settings-panel-subtitle">
                    <?= station_h((string) ($activeDefinition['description'] ?? 'No description.')) ?>
                  </p>
                  <div class="template-badges">
                    <span class="template-badge">stack: <strong><?= station_h((string) ($activeDefinition['stack'] ?? 'other')) ?></strong></span>
                    <span class="template-badge">port: <strong><?= (int) ($activeDefinition['appPort'] ?? 80) ?></strong></span>
                    <span class="template-badge">files: <strong><?= count($activeFiles) ?></strong></span>
                    <?php if (!empty($activeDefinition['custom'])): ?>
                      <span class="template-badge template-badge-custom">custom</span>
                    <?php else: ?>
                      <span class="template-badge template-badge-builtin">built-in (read-only)</span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="action-row" style="gap:8px;">
                  <?php if (!empty($activeDefinition['custom'])): ?>
                    <a class="btn-primary" href="template-manager.php?edit=<?= urlencode($activeKey) ?>">Edit</a>
                  <?php else: ?>
                    <form method="post" style="display:inline;">
                      <input type="hidden" name="action" value="duplicate_template">
                      <input type="hidden" name="template_key" value="<?= station_h($activeKey) ?>">
                      <button type="submit" class="btn-primary">Duplicate to custom</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>

              <?php $recommended = (array) ($activeDefinition['recommendedServices'] ?? []); ?>
              <?php if ($recommended !== []): ?>
                <p class="setting-description" style="margin-bottom:14px;">
                  <strong>Recommended services:</strong>
                  <?php foreach ($recommended as $svc): $svcMeta = $dockerServices[$svc] ?? null; ?>
                    <span class="template-badge"><?= $svcMeta ? station_h((string) ($svcMeta['icon'] ?? '')) . ' ' : '' ?><?= station_h((string) ($svcMeta['name'] ?? $svc)) ?></span>
                  <?php endforeach; ?>
                </p>
              <?php endif; ?>

              <div class="template-viewer-grid">
                <div class="template-viewer-tree">
                  <h3 class="setting-label" style="margin-bottom:8px;">File tree</h3>
                  <div class="template-tree">
                    <?= station_template_render_tree(station_template_build_tree($activeFiles)) ?>
                  </div>
                </div>
                <div class="template-viewer-file">
                  <div class="template-viewer-file-head">
                    <h3 class="setting-label" id="templateViewerFileLabel">
                      <?= $firstFilePath !== '' ? station_h($firstFilePath) : 'Select a file' ?>
                    </h3>
                    <button type="button" class="quick-link" id="templateViewerCopy">Copy</button>
                  </div>
                  <pre class="code-block template-viewer-code" id="templateViewerCode"><?= $firstFilePath !== '' ? station_h((string) ($activeFiles[$firstFilePath] ?? '')) : '' ?></pre>
                </div>
              </div>
            </section>
          <?php endif; ?>
        </div>
      </section>
    </main>
  </div>

  <script id="templateFilesData" type="application/json"><?= json_encode($activeFiles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}' ?></script>
  <script>
    (function () {
      const dataEl = document.getElementById('templateFilesData');
      const files = dataEl ? JSON.parse(dataEl.textContent || '{}') : {};

      // ── Viewer mode: clicking a tree entry loads its file ──
      const codeEl = document.getElementById('templateViewerCode');
      const labelEl = document.getElementById('templateViewerFileLabel');
      const copyEl = document.getElementById('templateViewerCopy');
      document.querySelectorAll('[data-template-file]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          const path = btn.getAttribute('data-template-file') || '';
          const content = Object.prototype.hasOwnProperty.call(files, path) ? files[path] : '';
          if (codeEl) { codeEl.textContent = content || ''; }
          if (labelEl) { labelEl.textContent = path; }
          document.querySelectorAll('[data-template-file]').forEach(function (other) { other.classList.remove('active'); });
          btn.classList.add('active');
        });
      });
      if (copyEl) {
        copyEl.addEventListener('click', function () {
          const text = (codeEl && codeEl.textContent) || '';
          if (!text) { return; }
          try {
            navigator.clipboard.writeText(text)
              .then(function () { copyEl.textContent = 'Copied'; setTimeout(function () { copyEl.textContent = 'Copy'; }, 1200); })
              .catch(function () { copyEl.textContent = 'Copy failed'; });
          } catch (_) { copyEl.textContent = 'Copy blocked'; }
        });
      }

      // ── Editor mode: dynamic file rows ──
      const rowsContainer = document.getElementById('templateFileRows');
      const addBtn = document.getElementById('templateAddFile');

      function attachRemove(row) {
        const btn = row.querySelector('[data-row-remove]');
        if (!btn) { return; }
        btn.addEventListener('click', function () {
          if (rowsContainer && rowsContainer.children.length <= 1) {
            alert('Templates must contain at least one file.');
            return;
          }
          row.remove();
        });
      }

      if (rowsContainer) {
        Array.from(rowsContainer.children).forEach(attachRemove);
      }

      if (addBtn && rowsContainer) {
        addBtn.addEventListener('click', function () {
          const row = document.createElement('div');
          row.className = 'template-file-row';
          row.innerHTML = ''
            + '<div class="template-file-row-head">'
            + '<input type="text" name="file_paths[]" class="template-file-path" placeholder="path/to/file.ext">'
            + '<button type="button" class="secondary-btn template-file-remove" data-row-remove>Remove</button>'
            + '</div>'
            + '<textarea name="file_bodies[]" rows="10" spellcheck="false" class="template-file-body"></textarea>';
          rowsContainer.appendChild(row);
          attachRemove(row);
          const input = row.querySelector('.template-file-path');
          if (input) { input.focus(); }
        });
      }
    })();
  </script>

  <?= station_dashboard_nav_script_html() ?>
  <?= station_clipboard_fab_html() ?>
  <?= station_pwa_register_html() ?>
</body>
</html>
