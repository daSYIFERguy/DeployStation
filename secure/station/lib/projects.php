<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function station_realpath_inside(string $base, string $path): bool
{
    $baseReal = realpath($base);
    $pathReal = realpath($path);
    if ($baseReal === false || $pathReal === false) {
        return false;
    }
    return strncmp($pathReal, $baseReal, strlen($baseReal)) === 0;
}

function station_project_path(string $slug): string
{
    return station_projects_dir() . '/' . $slug;
}

function station_project_serve_path(string $slug, string $relativePath = ''): string
{
    $base = station_web_base_path();
    $projectsBase = str_replace('\\', '/', dirname($base !== '' ? $base : '/station'));
    if ($projectsBase === '.' || $projectsBase === '/' || $projectsBase === '\\') {
        $projectsBase = '';
    }

    $path = ($projectsBase !== '' ? $projectsBase : '') . '/' . rawurlencode(station_safe_name($slug)) . '/';
    $relative = trim(str_replace('\\', '/', $relativePath), '/');
    if ($relative === '') {
        return $path;
    }

    $segments = array_map(static fn (string $segment): string => rawurlencode($segment), explode('/', $relative));
    return $path . implode('/', $segments);
}

function station_reserved_dirs(): array
{
    return ['station'];
}

function station_project_exists(string $slug): bool
{
    return is_dir(station_project_path($slug));
}

function station_project_owner_for_slug(string $slug, array $metaProjects): string
{
    foreach ($metaProjects as $project) {
        if (!is_array($project)) {
            continue;
        }
        if ((string) ($project['slug'] ?? '') === $slug) {
            $owner = (string) ($project['owner'] ?? 'unknown');
            return $owner !== '' ? $owner : 'unknown';
        }
    }
    return 'unknown';
}

function station_project_source_for_slug(string $slug, array $metaProjects): string
{
    foreach ($metaProjects as $project) {
        if (!is_array($project)) {
            continue;
        }
        if ((string) ($project['slug'] ?? '') === $slug) {
            $source = (string) ($project['sourceType'] ?? 'existing');
            return $source !== '' ? $source : 'existing';
        }
    }
    return 'existing';
}

function station_project_visibility_for_slug(string $slug, array $metaProjects): string
{
    foreach ($metaProjects as $project) {
        if (!is_array($project)) {
            continue;
        }
        if ((string) ($project['slug'] ?? '') === $slug) {
            $visibility = (string) ($project['visibility'] ?? 'private');
            return $visibility === 'public' ? 'public' : 'private';
        }
    }
    return 'private';
}

function station_project_access_mode_for_slug(string $slug, array $metaProjects): string
{
    foreach ($metaProjects as $project) {
        if (!is_array($project)) {
            continue;
        }
        if ((string) ($project['slug'] ?? '') === $slug) {
            $mode = (string) ($project['accessMode'] ?? 'admin');
            return $mode !== '' ? $mode : 'admin';
        }
    }
    return 'admin';
}

function station_project_created_for_slug(string $slug, array $metaProjects, string $fullPath): string
{
    foreach ($metaProjects as $project) {
        if (!is_array($project)) {
            continue;
        }
        if ((string) ($project['slug'] ?? '') === $slug) {
            $created = (string) ($project['createdAt'] ?? '');
            if ($created !== '') {
                return $created;
            }
        }
    }

    $mtime = @filemtime($fullPath);
    return $mtime ? gmdate('c', (int) $mtime) : gmdate('c');
}

function station_list_projects(): array
{
    $meta = station_projects_meta();
    $metaProjects = isset($meta['projects']) && is_array($meta['projects']) ? $meta['projects'] : [];
    $projectsDir = station_projects_dir();
    $reserved = array_fill_keys(station_reserved_dirs(), true);

    $entries = @scandir($projectsDir);
    if (!is_array($entries)) {
        return [];
    }

    $output = [];
    foreach ($entries as $entry) {
        if (!is_string($entry)) {
            continue;
        }

        if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
            continue;
        }

        if (isset($reserved[$entry])) {
            continue;
        }

        $fullPath = $projectsDir . '/' . $entry;
        if (!is_dir($fullPath)) {
            continue;
        }

        $output[] = [
            'slug' => $entry,
            'owner' => station_project_owner_for_slug($entry, $metaProjects),
            'sourceType' => station_project_source_for_slug($entry, $metaProjects),
            'visibility' => station_project_visibility_for_slug($entry, $metaProjects),
            'accessMode' => station_project_access_mode_for_slug($entry, $metaProjects),
            'createdAt' => station_project_created_for_slug($entry, $metaProjects, $fullPath),
            'updatedAt' => gmdate('c', (int) (@filemtime($fullPath) ?: time()))
        ];
    }

    usort($output, static function (array $a, array $b): int {
        return strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''));
    });

    return $output;
}

function station_upsert_project_meta(array $project): bool
{
    $meta = station_projects_meta();
    $projects = isset($meta['projects']) && is_array($meta['projects']) ? $meta['projects'] : [];

    $slug = (string) ($project['slug'] ?? '');
    if ($slug === '') {
        return false;
    }

    $updated = false;
    foreach ($projects as $idx => $existing) {
        if (!is_array($existing)) {
            continue;
        }
        if ((string) ($existing['slug'] ?? '') === $slug) {
            $projects[$idx] = $project;
            $updated = true;
            break;
        }
    }

    if (!$updated) {
        $projects[] = $project;
    }

    $meta['projects'] = $projects;
    return station_save_projects_meta($meta);
}

function station_scan_project_files(string $slug): array
{
    $projectPath = station_project_path($slug);
    if (!is_dir($projectPath)) {
        return [];
    }

    $result = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $item) {
        if (!$item instanceof SplFileInfo) {
            continue;
        }

        $fullPath = $item->getPathname();
        $relative = str_replace($projectPath . '/', '', $fullPath);
        if ($item->isDir()) {
            continue;
        }

        $result[] = [
            'path' => str_replace('\\', '/', $relative),
            'size' => $item->getSize(),
            'modifiedAt' => gmdate('c', (int) $item->getMTime())
        ];
    }

    usort($result, static function (array $a, array $b): int {
        return strcmp((string) $a['path'], (string) $b['path']);
    });

    return $result;
}

function station_read_project_file(string $slug, string $relativePath): ?string
{
    $projectPath = station_project_path($slug);
    $target = $projectPath . '/' . ltrim($relativePath, '/');

    if (!station_realpath_inside($projectPath, $target) || !is_file($target)) {
        return null;
    }

    $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
    $textExt = ['txt', 'md', 'json', 'csv', 'html', 'htm', 'css', 'js', 'ts', 'php', 'xml', 'yml', 'yaml', 'env'];
    if (!in_array($ext, $textExt, true)) {
        return null;
    }

    $raw = @file_get_contents($target);
    if ($raw === false) {
        return null;
    }

    if (strlen($raw) > 900000) {
        return substr($raw, 0, 900000) . "\n\n... truncated ...";
    }

    return $raw;
}

function station_write_project_file(string $slug, string $relativePath, string $content): bool
{
    $projectPath = station_project_path($slug);
    $target = $projectPath . '/' . ltrim($relativePath, '/');

    $parent = dirname($target);
    if (!is_dir($parent)) {
        mkdir($parent, 0755, true);
    }

    if (!station_realpath_inside($projectPath, $parent)) {
        return false;
    }

    return @file_put_contents($target, $content, LOCK_EX) !== false;
}

function station_unique_slug(string $raw): string
{
    $base = station_safe_name($raw);
    if (in_array($base, station_reserved_dirs(), true)) {
        $base = $base . '-project';
    }
    $slug = $base;
    $idx = 2;
    while (station_project_exists($slug)) {
        $slug = $base . '-' . $idx;
        $idx++;
    }
    return $slug;
}

function station_rename_project_slug(string $oldSlug, string $newRawSlug): array
{
    $oldSlug = station_safe_name($oldSlug);
    $newSlug = station_safe_name($newRawSlug);

    if ($oldSlug === '' || $newSlug === '') {
        return ['ok' => false, 'message' => 'Invalid project name.'];
    }

    if (in_array($newSlug, station_reserved_dirs(), true)) {
        return ['ok' => false, 'message' => 'That name is reserved.'];
    }

    if (!station_project_exists($oldSlug)) {
        return ['ok' => false, 'message' => 'Project not found.'];
    }

    if ($oldSlug === $newSlug) {
        return ['ok' => true, 'message' => 'No rename needed.', 'slug' => $newSlug];
    }

    if (station_project_exists($newSlug)) {
        return ['ok' => false, 'message' => 'Target folder already exists.'];
    }

    $oldPath = station_project_path($oldSlug);
    $newPath = station_project_path($newSlug);
    if (!@rename($oldPath, $newPath)) {
        return ['ok' => false, 'message' => 'Filesystem rename failed.'];
    }

    $meta = station_projects_meta();
    $projects = isset($meta['projects']) && is_array($meta['projects']) ? $meta['projects'] : [];
    foreach ($projects as $idx => $project) {
        if (!is_array($project)) {
            continue;
        }
        if ((string) ($project['slug'] ?? '') === $oldSlug) {
            $projects[$idx]['slug'] = $newSlug;
            $projects[$idx]['updatedAt'] = gmdate('c');
        }
    }
    $meta['projects'] = $projects;
    station_save_projects_meta($meta);

    return ['ok' => true, 'message' => 'Project renamed.', 'slug' => $newSlug];
}

function station_update_project_owner(string $slug, string $owner): bool
{
    $slug = station_safe_name($slug);
    if ($slug === '') {
        return false;
    }

    $meta = station_projects_meta();
    $projects = isset($meta['projects']) && is_array($meta['projects']) ? $meta['projects'] : [];
    $updated = false;

    foreach ($projects as $idx => $project) {
        if (!is_array($project)) {
            continue;
        }
        if ((string) ($project['slug'] ?? '') === $slug) {
            $projects[$idx]['owner'] = $owner;
            $projects[$idx]['updatedAt'] = gmdate('c');
            $updated = true;
        }
    }

    if (!$updated) {
        $projects[] = [
            'slug' => $slug,
            'owner' => $owner,
            'sourceType' => 'existing',
            'visibility' => 'private',
            'accessMode' => 'admin',
            'createdAt' => gmdate('c'),
            'updatedAt' => gmdate('c')
        ];
    }

    $meta['projects'] = $projects;
    return station_save_projects_meta($meta);
}

function station_set_project_visibility(string $slug, string $visibility): bool
{
    $slug = station_safe_name($slug);
    if ($slug === '') {
        return false;
    }

    $safeVisibility = $visibility === 'public' ? 'public' : 'private';
    $meta = station_projects_meta();
    $projects = isset($meta['projects']) && is_array($meta['projects']) ? $meta['projects'] : [];
    $updated = false;

    foreach ($projects as $idx => $project) {
        if (!is_array($project)) {
            continue;
        }
        if ((string) ($project['slug'] ?? '') === $slug) {
            $projects[$idx]['visibility'] = $safeVisibility;
            $projects[$idx]['updatedAt'] = gmdate('c');
            if ($safeVisibility === 'private' && (($projects[$idx]['accessMode'] ?? '') === 'public')) {
                $projects[$idx]['accessMode'] = 'admin';
            }
            $updated = true;
        }
    }

    if (!$updated) {
        $projects[] = [
            'slug' => $slug,
            'owner' => 'unknown',
            'sourceType' => 'existing',
            'visibility' => $safeVisibility,
            'accessMode' => $safeVisibility === 'public' ? 'public' : 'admin',
            'createdAt' => gmdate('c'),
            'updatedAt' => gmdate('c')
        ];
    }

    $meta['projects'] = $projects;
    return station_save_projects_meta($meta);
}

function station_project_visibility(string $slug): string
{
    $meta = station_projects_meta();
    $projects = isset($meta['projects']) && is_array($meta['projects']) ? $meta['projects'] : [];
    return station_project_visibility_for_slug(station_safe_name($slug), $projects);
}

function station_set_project_access_mode(string $slug, string $accessMode): bool
{
    $slug = station_safe_name($slug);
    if ($slug === '') {
        return false;
    }

    $allowed = station_allowed_project_access_modes();
    $safeMode = isset($allowed[$accessMode]) ? $accessMode : 'admin';
    $visibility = $safeMode === 'public' ? 'public' : 'private';
    $meta = station_projects_meta();
    $projects = isset($meta['projects']) && is_array($meta['projects']) ? $meta['projects'] : [];
    $updated = false;

    foreach ($projects as $idx => $project) {
        if (!is_array($project)) {
            continue;
        }
        if ((string) ($project['slug'] ?? '') === $slug) {
            $projects[$idx]['accessMode'] = $safeMode;
            $projects[$idx]['visibility'] = $visibility;
            $projects[$idx]['updatedAt'] = gmdate('c');
            $updated = true;
        }
    }

    if (!$updated) {
        $projects[] = [
            'slug' => $slug,
            'owner' => 'unknown',
            'sourceType' => 'existing',
            'visibility' => $visibility,
            'accessMode' => $safeMode,
            'createdAt' => gmdate('c'),
            'updatedAt' => gmdate('c')
        ];
    }

    $meta['projects'] = $projects;
    return station_save_projects_meta($meta);
}

function station_project_access_mode(string $slug): string
{
    $meta = station_projects_meta();
    $projects = isset($meta['projects']) && is_array($meta['projects']) ? $meta['projects'] : [];
    return station_project_access_mode_for_slug(station_safe_name($slug), $projects);
}

function station_remove_project_meta(string $slug): bool
{
    $safe = station_safe_name($slug);
    $meta = station_projects_meta();
    $projects = isset($meta['projects']) && is_array($meta['projects']) ? $meta['projects'] : [];
    $projects = array_values(array_filter($projects, static function (array $project) use ($safe): bool {
        return (string) ($project['slug'] ?? '') !== $safe;
    }));
    $meta['projects'] = $projects;
    return station_save_projects_meta($meta);
}

function station_archive_project(string $slug): array
{
    $safe = station_safe_name($slug);
    $source = station_project_path($safe);
    if ($safe === '' || !is_dir($source)) {
        return ['ok' => false, 'message' => 'Project not found.'];
    }

    $targetName = $safe . '-' . gmdate('Ymd-His');
    $target = station_archived_projects_dir() . '/' . $targetName;
    if (!@rename($source, $target)) {
        return ['ok' => false, 'message' => 'Could not archive project directory.'];
    }

    station_remove_project_meta($safe);
    return ['ok' => true, 'message' => 'Project archived.', 'archiveName' => $targetName];
}

function station_list_archived_projects(): array
{
    $dir = station_archived_projects_dir();
    $entries = @scandir($dir);
    if (!is_array($entries)) {
        return [];
    }

    $result = [];
    foreach ($entries as $entry) {
        if (!is_string($entry) || $entry === '.' || $entry === '..') {
            continue;
        }
        $full = $dir . '/' . $entry;
        if (!is_dir($full)) {
            continue;
        }
        $result[] = [
            'name' => $entry,
            'modifiedAt' => gmdate('c', (int) (@filemtime($full) ?: time()))
        ];
    }

    usort($result, static function (array $a, array $b): int {
        return strcmp((string) ($b['modifiedAt'] ?? ''), (string) ($a['modifiedAt'] ?? ''));
    });
    return $result;
}

function station_restore_archived_project(string $archiveName): array
{
    $name = station_sanitize_archived_folder_name($archiveName);
    if ($name === null) {
        return ['ok' => false, 'message' => 'Invalid archive name.'];
    }
    $source = station_archived_projects_dir() . '/' . $name;
    if ($name === '' || !is_dir($source)) {
        return ['ok' => false, 'message' => 'Archived project not found.'];
    }

    $baseSlug = station_safe_name(preg_replace('/-\d{8}-\d{6}$/', '', $name) ?? $name);
    $slug = station_unique_slug($baseSlug);
    $target = station_project_path($slug);
    if (!@rename($source, $target)) {
        return ['ok' => false, 'message' => 'Could not restore archived project.'];
    }

    station_update_project_owner($slug, station_current_username() !== '' ? station_current_username() : 'root');
    return ['ok' => true, 'message' => 'Archived project restored.', 'slug' => $slug];
}

function station_delete_project_permanently(string $slug): array
{
    $safe = station_safe_name($slug);
    $path = station_project_path($safe);
    if ($safe === '' || !is_dir($path)) {
        return ['ok' => false, 'message' => 'Project not found.'];
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);

    @unlink(station_project_settings_path($safe));
    station_remove_project_meta($safe);
    return ['ok' => true, 'message' => 'Project permanently deleted.'];
}

function station_create_project_backup(string $slug): array
{
    $safe = station_safe_name($slug);
    $path = station_project_path($safe);
    if ($safe === '' || !is_dir($path)) {
        return ['ok' => false, 'message' => 'Project not found.'];
    }

    $zipName = $safe . '-' . gmdate('Ymd-His') . '.zip';
    $zipPath = station_archives_dir() . '/' . $zipName;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['ok' => false, 'message' => 'Could not create backup zip.'];
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $full = $item->getPathname();
        $relative = str_replace($path . '/', '', $full);
        $relative = str_replace('\\', '/', $relative);
        if ($item->isDir()) {
            $zip->addEmptyDir($relative);
        } else {
            $zip->addFile($full, $relative);
        }
    }
    $zip->close();

    return ['ok' => true, 'message' => 'Backup created.', 'archiveFile' => $zipName];
}

function station_list_backups(): array
{
    $dir = station_archives_dir();
    $entries = @scandir($dir);
    if (!is_array($entries)) {
        return [];
    }

    $result = [];
    foreach ($entries as $entry) {
        if (!is_string($entry) || $entry === '.' || $entry === '..' || !str_ends_with($entry, '.zip')) {
            continue;
        }
        $full = $dir . '/' . $entry;
        if (!is_file($full)) {
            continue;
        }
        $result[] = [
            'name' => $entry,
            'size' => (int) (@filesize($full) ?: 0),
            'modifiedAt' => gmdate('c', (int) (@filemtime($full) ?: time()))
        ];
    }
    usort($result, static function (array $a, array $b): int {
        return strcmp((string) ($b['modifiedAt'] ?? ''), (string) ($a['modifiedAt'] ?? ''));
    });
    return $result;
}

/**
 * Safe filename for a zip under archives/ (download or restore).
 */
function station_sanitize_stored_backup_zip_name(string $name): ?string
{
    $name = basename(str_replace('\\', '/', trim($name)));
    if ($name === '' || str_contains($name, '..')) {
        return null;
    }
    if (!str_ends_with(strtolower($name), '.zip')) {
        return null;
    }
    if (!preg_match('/^[a-zA-Z0-9._-]+\.zip$/', $name)) {
        return null;
    }

    return $name;
}

/**
 * Safe directory name under archived-projects/.
 */
function station_sanitize_archived_folder_name(string $name): ?string
{
    $name = basename(str_replace('\\', '/', trim($name)));
    if ($name === '' || str_contains($name, '..')) {
        return null;
    }
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
        return null;
    }

    return $name;
}

function station_restore_backup_zip(string $archiveFile): array
{
    $name = station_sanitize_stored_backup_zip_name($archiveFile);
    if ($name === null) {
        return ['ok' => false, 'message' => 'Invalid backup file name.'];
    }
    $path = station_archives_dir() . '/' . $name;
    if ($name === '' || !is_file($path)) {
        return ['ok' => false, 'message' => 'Backup not found.'];
    }

    $slugBase = station_safe_name(preg_replace('/-\d{8}-\d{6}\.zip$/', '', $name) ?? $name);
    $slug = station_unique_slug($slugBase);
    $target = station_project_path($slug);
    if (!is_dir($target) && !@mkdir($target, 0755, true)) {
        return ['ok' => false, 'message' => 'Could not create restore target.'];
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['ok' => false, 'message' => 'Could not open backup zip.'];
    }
    $zip->extractTo($target);
    $zip->close();

    station_update_project_owner($slug, station_current_username() !== '' ? station_current_username() : 'root');
    return ['ok' => true, 'message' => 'Backup restored.', 'slug' => $slug];
}

function station_write_env_file(string $slug, array $environment): bool
{
    $lines = [];
    foreach ($environment as $key => $value) {
        $k = strtoupper(trim((string) $key));
        if ($k === '') {
            continue;
        }
        $lines[] = $k . '=' . (string) $value;
    }
    return station_write_project_file($slug, '.env.local', implode("\n", $lines) . (count($lines) ? "\n" : ''));
}

function station_generate_github_bootstrap_files(string $slug, array $settings): array
{
    $repoName = trim((string) ($settings['github']['repoName'] ?? $slug));
    $defaultBranch = trim((string) ($settings['github']['defaultBranch'] ?? 'main')) ?: 'main';
    $readme = '# ' . $repoName . "\n\nManaged by Station.\n";
    $workflow = "name: release\n\non:\n  push:\n    branches: [\"{$defaultBranch}\"]\n\njobs:\n  build:\n    runs-on: ubuntu-latest\n    steps:\n      - uses: actions/checkout@v4\n      - name: Basic release note\n        run: echo \"Release workflow placeholder for {$repoName}\"\n";

    $ok1 = station_write_project_file($slug, 'README.md', $readme);
    $ok2 = station_write_project_file($slug, '.github/workflows/release.yml', $workflow);
    $ok3 = station_write_project_file($slug, '.gitignore', ".env\n.env.local\nnode_modules/\ndist/\nbuild/\n");

    return ['ok' => $ok1 && $ok2 && $ok3, 'message' => $ok1 && $ok2 && $ok3 ? 'GitHub bootstrap generated.' : 'Could not write bootstrap files.'];
}
