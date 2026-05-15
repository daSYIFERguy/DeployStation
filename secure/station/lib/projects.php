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

/**
 * @return list<string>
 */
function station_project_web_index_basenames(): array
{
    return ['index.html', 'index.htm', 'index.php'];
}

/**
 * List one directory level for entrypoint picker (dirs + index files).
 *
 * @return array{ok: bool, message?: string, dir: string, entries: list<array{name: string, type: string}>, indexFiles: list<string>}
 */
function station_project_list_workspace_dir(string $slug, string $relativeDir = ''): array
{
    $projectPath = station_project_path($slug);
    $relativeDir = trim(str_replace('\\', '/', $relativeDir), '/');
    if ($relativeDir !== '' && !station_is_safe_relative_path($relativeDir)) {
        return ['ok' => false, 'message' => 'Invalid path.', 'dir' => '', 'entries' => [], 'indexFiles' => []];
    }

    $target = $relativeDir === '' ? $projectPath : $projectPath . '/' . $relativeDir;
    if (!is_dir($target) || !station_realpath_inside($projectPath, $target)) {
        return ['ok' => false, 'message' => 'Directory not found.', 'dir' => $relativeDir, 'entries' => [], 'indexFiles' => []];
    }

    $indexNames = array_flip(array_map('strtolower', station_project_web_index_basenames()));
    $entries = [];
    $indexFiles = [];

    foreach (@scandir($target) ?: [] as $name) {
        if (!is_string($name) || $name === '.' || $name === '..') {
            continue;
        }
        $full = $target . '/' . $name;
        if (!station_realpath_inside($projectPath, $full)) {
            continue;
        }
        if (is_dir($full)) {
            $entries[] = ['name' => $name, 'type' => 'dir'];
            continue;
        }
        if (is_file($full) && isset($indexNames[strtolower($name)])) {
            $indexFiles[] = $name;
            $entries[] = ['name' => $name, 'type' => 'index'];
        }
    }

    usort($entries, static function (array $a, array $b): int {
        $order = ['dir' => 0, 'index' => 1];
        $ta = $order[$a['type']] ?? 2;
        $tb = $order[$b['type']] ?? 2;
        if ($ta !== $tb) {
            return $ta <=> $tb;
        }

        return strcasecmp((string) $a['name'], (string) $b['name']);
    });

    return [
        'ok' => true,
        'dir' => $relativeDir,
        'entries' => $entries,
        'indexFiles' => $indexFiles,
    ];
}

/**
 * Public URL path segment for this project's web root (may be a subdirectory).
 */
/**
 * Full directory listing for project workspace IDE (files + folders).
 *
 * @return array{ok: bool, message?: string, dir: string, parent: string, entries: list<array{name: string, type: string, size?: int, modifiedAt?: string}>}
 */
function station_project_explorer_browse(string $slug, string $relativeDir = ''): array
{
    $projectPath = station_project_path($slug);
    $relativeDir = trim(str_replace('\\', '/', $relativeDir), '/');
    if ($relativeDir !== '' && !station_is_safe_relative_path($relativeDir)) {
        return ['ok' => false, 'message' => 'Invalid path.', 'dir' => '', 'parent' => '', 'entries' => []];
    }

    $target = $relativeDir === '' ? $projectPath : $projectPath . '/' . $relativeDir;
    if (!is_dir($target) || !station_realpath_inside($projectPath, $target)) {
        return ['ok' => false, 'message' => 'Directory not found.', 'dir' => $relativeDir, 'parent' => '', 'entries' => []];
    }

    $parent = '';
    if ($relativeDir !== '') {
        $parts = explode('/', $relativeDir);
        array_pop($parts);
        $parent = implode('/', $parts);
    }

    $entries = [];
    foreach (@scandir($target) ?: [] as $name) {
        if (!is_string($name) || $name === '.' || $name === '..') {
            continue;
        }
        $full = $target . '/' . $name;
        if (!station_realpath_inside($projectPath, $full)) {
            continue;
        }
        if (is_dir($full)) {
            $entries[] = [
                'name' => $name,
                'type' => 'dir',
                'modifiedAt' => gmdate('c', (int) (@filemtime($full) ?: 0)),
            ];
            continue;
        }
        if (is_file($full)) {
            $entries[] = [
                'name' => $name,
                'type' => 'file',
                'size' => (int) (@filesize($full) ?: 0),
                'modifiedAt' => gmdate('c', (int) (@filemtime($full) ?: 0)),
            ];
        }
    }

    usort($entries, static function (array $a, array $b): int {
        $ta = ($a['type'] ?? '') === 'dir' ? 0 : 1;
        $tb = ($b['type'] ?? '') === 'dir' ? 0 : 1;
        if ($ta !== $tb) {
            return $ta <=> $tb;
        }

        return strcasecmp((string) $a['name'], (string) $b['name']);
    });

    return [
        'ok' => true,
        'dir' => $relativeDir,
        'parent' => $parent,
        'entries' => $entries,
    ];
}

/**
 * @return array{ok: bool, message: string}
 */
function station_project_workspace_mkdir(string $slug, string $relativeDir): array
{
    $relativeDir = trim(str_replace('\\', '/', $relativeDir), '/');
    if ($relativeDir === '' || !station_is_safe_relative_path($relativeDir)) {
        return ['ok' => false, 'message' => 'Invalid folder path.'];
    }

    $projectPath = station_project_path($slug);
    $target = $projectPath . '/' . $relativeDir;
    if (is_dir($target)) {
        return ['ok' => true, 'message' => 'Folder already exists.'];
    }
    if (is_file($target)) {
        return ['ok' => false, 'message' => 'A file exists at that path.'];
    }

    $parent = dirname($target);
    if (!is_dir($parent)) {
        @mkdir($parent, 0755, true);
    }
    if (!is_dir($parent) || !station_realpath_inside($projectPath, $parent)) {
        return ['ok' => false, 'message' => 'Could not create parent directories.'];
    }

    if (!@mkdir($target, 0755, false) && !is_dir($target)) {
        return ['ok' => false, 'message' => 'Could not create folder.'];
    }
    if (!station_realpath_inside($projectPath, $target)) {
        return ['ok' => false, 'message' => 'Path is outside the project.'];
    }

    return ['ok' => true, 'message' => 'Folder created.'];
}

/**
 * @return array{ok: bool, message: string}
 */
function station_project_workspace_delete(string $slug, string $relativePath): array
{
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || !station_is_safe_relative_path($relativePath)) {
        return ['ok' => false, 'message' => 'Invalid path.'];
    }

    $projectPath = station_project_path($slug);
    $target = $projectPath . '/' . $relativePath;
    if (!file_exists($target) || !station_realpath_inside($projectPath, $target)) {
        return ['ok' => false, 'message' => 'Path not found.'];
    }

    if (is_dir($target)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        if (!@rmdir($target)) {
            return ['ok' => false, 'message' => 'Could not delete folder (not empty or permission denied).'];
        }

        return ['ok' => true, 'message' => 'Folder deleted.'];
    }

    if (!@unlink($target)) {
        return ['ok' => false, 'message' => 'Could not delete file.'];
    }

    return ['ok' => true, 'message' => 'File deleted.'];
}

/**
 * @return array{ok: bool, message: string, path?: string}
 */
function station_project_workspace_rename(string $slug, string $from, string $to): array
{
    $from = trim(str_replace('\\', '/', $from), '/');
    $to = trim(str_replace('\\', '/', $to), '/');
    if ($from === '' || $to === '' || !station_is_safe_relative_path($from) || !station_is_safe_relative_path($to)) {
        return ['ok' => false, 'message' => 'Invalid path.'];
    }

    $projectPath = station_project_path($slug);
    $src = $projectPath . '/' . $from;
    $dst = $projectPath . '/' . $to;
    if (!file_exists($src) || !station_realpath_inside($projectPath, $src)) {
        return ['ok' => false, 'message' => 'Source not found.'];
    }
    if (file_exists($dst)) {
        return ['ok' => false, 'message' => 'Target already exists.'];
    }

    $dstParent = dirname($dst);
    if (!is_dir($dstParent)) {
        @mkdir($dstParent, 0755, true);
    }
    if (!station_realpath_inside($projectPath, $dstParent)) {
        return ['ok' => false, 'message' => 'Target path is outside the project.'];
    }

    if (!@rename($src, $dst)) {
        return ['ok' => false, 'message' => 'Rename failed.'];
    }

    return ['ok' => true, 'message' => 'Renamed.', 'path' => $to];
}

/**
 * @param array{name?: string, tmp_name?: string, error?: int, size?: int} $uploaded
 * @return array{ok: bool, message: string, path?: string}
 */
function station_project_workspace_upload(string $slug, string $relativeDir, array $uploaded): array
{
    $relativeDir = trim(str_replace('\\', '/', $relativeDir), '/');
    if ($relativeDir !== '' && !station_is_safe_relative_path($relativeDir)) {
        return ['ok' => false, 'message' => 'Invalid upload directory.'];
    }

    $error = (int) ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Upload failed (code ' . $error . ').'];
    }

    $tmp = (string) ($uploaded['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'Invalid upload payload.'];
    }

    $name = basename((string) ($uploaded['name'] ?? 'upload.bin'));
    $name = preg_replace('/[^a-zA-Z0-9._\-]+/', '_', $name) ?: 'upload.bin';
    $relPath = ($relativeDir !== '' ? $relativeDir . '/' : '') . $name;

    $projectPath = station_project_path($slug);
    $target = $projectPath . '/' . $relPath;
    $parent = dirname($target);
    if (!is_dir($parent)) {
        @mkdir($parent, 0755, true);
    }
    if (!station_realpath_inside($projectPath, $parent)) {
        return ['ok' => false, 'message' => 'Upload path is outside the project.'];
    }

    if (!@move_uploaded_file($tmp, $target)) {
        return ['ok' => false, 'message' => 'Could not save uploaded file.'];
    }

    return ['ok' => true, 'message' => 'Uploaded.', 'path' => $relPath];
}

function station_project_public_web_path(string $slug): string
{
    if (!function_exists('station_project_resolve_web_entry')) {
        require_once __DIR__ . '/project-launch.php';
    }
    $entry = station_project_resolve_web_entry($slug);
    if ($entry !== null && ($entry['dir'] ?? '') !== '') {
        return station_project_serve_path($slug, (string) $entry['dir']);
    }

    return station_project_serve_path($slug);
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

/**
 * Portable deploy bundle: source tree + compose/Docker hints for any Docker host.
 *
 * @return array{ok: bool, message: string, archiveFile?: string}
 */
function station_export_portable_project(string $slug): array
{
    $safe = station_safe_name($slug);
    $path = station_project_path($safe);
    if ($safe === '' || !is_dir($path)) {
        return ['ok' => false, 'message' => 'Project not found.'];
    }

    $settings = station_project_settings($safe);
    $docker = isset($settings['docker']) && is_array($settings['docker']) ? $settings['docker'] : [];
    $zipName = $safe . '-portable-' . gmdate('Ymd-His') . '.zip';
    $zipPath = station_archives_dir() . '/' . $zipName;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['ok' => false, 'message' => 'Could not create export zip.'];
    }

    $excludeNames = ['.git', 'node_modules', 'vendor', '.venv', '__pycache__'];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $full = $item->getPathname();
        $relative = str_replace($path . DIRECTORY_SEPARATOR, '', $full);
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_contains($relative, '/.git/') || str_starts_with($relative, '.git/')) {
            continue;
        }
        $base = basename($relative);
        if (in_array($base, $excludeNames, true)) {
            continue;
        }
        if ($item->isDir()) {
            $zip->addEmptyDir($relative);
        } else {
            $zip->addFile($full, $relative);
        }
    }

    $appPort = (int) ($docker['appPort'] ?? 80);
    $hostPort = (int) ($docker['hostPort'] ?? 0);
    $exportReadme = station_portable_export_readme($safe, $appPort, $hostPort, !empty($docker['containerized']));
    $zip->addFromString('EXPORT_README.md', $exportReadme);

    $composePath = $path . '/docker-compose.yml';
    if (is_file($composePath)) {
        $zip->addFile($composePath, 'docker-compose.yml');
    }

    $zip->close();

    station_log_event('project.export.portable', ['slug' => $safe, 'archive' => $zipName]);

    return ['ok' => true, 'message' => 'Portable export created.', 'archiveFile' => $zipName];
}

function station_portable_export_readme(string $slug, int $appPort, int $hostPort, bool $containerized): string
{
    $portLine = $hostPort > 0
        ? "Suggested host mapping: `127.0.0.1:{$hostPort}:{$appPort}`"
        : "Map host port to container port **{$appPort}**";

    return <<<MD
# Portable export: {$slug}

Generated by DeployStation. Unzip on any machine with Docker.

## Quick start

```bash
unzip {$slug}-portable-*.zip -d {$slug}
cd {$slug}
cp .env.example .env
# edit .env
docker compose up -d --build
# or: docker build -t {$slug} . && docker run -p 8080:{$appPort} --env-file .env {$slug}
```

{$portLine}

## Files

- Application source (excluding large vendored dirs and `.git`)
- `DEPLOYSTATION.md` — AI/deploy context when present
- `docker-compose.yml` when the project was containerized on the station

## Image export (optional)

To move a built image between hosts:

```bash
docker save myimage:tag | gzip > {$slug}-image.tar.gz
# on target host:
docker load < {$slug}-image.tar.gz
```

Runtime databases and uploads live in Docker volumes — back them up separately.

MD;
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
    $gh = isset($settings['github']) && is_array($settings['github']) ? $settings['github'] : [];
    $repoName = trim((string) ($gh['repoName'] ?? $slug));
    $defaultBranch = trim((string) ($gh['defaultBranch'] ?? 'main')) ?: 'main';
    $wantWorkflow = !empty($gh['releaseWorkflow']);
    $readme = '# ' . $repoName . "\n\nManaged by Station.\n";
    $workflow = "name: release\n\non:\n  push:\n    branches: [\"{$defaultBranch}\"]\n\njobs:\n  build:\n    runs-on: ubuntu-latest\n    steps:\n      - uses: actions/checkout@v4\n      - name: Basic release note\n        run: echo \"Release workflow placeholder for {$repoName}\"\n";

    $ok1 = station_write_project_file($slug, 'README.md', $readme);
    $ok2 = $wantWorkflow
        ? station_write_project_file($slug, '.github/workflows/release.yml', $workflow)
        : true;
    $ok3 = station_write_project_file($slug, '.gitignore', ".env\n.env.local\nnode_modules/\ndist/\nbuild/\n");

    if (!$wantWorkflow) {
        $workflowPath = station_project_path($slug) . '/.github/workflows/release.yml';
        if (is_file($workflowPath)) {
            @unlink($workflowPath);
        }
    }

    $message = $wantWorkflow
        ? 'GitHub bootstrap generated (README, .gitignore, release workflow).'
        : 'GitHub bootstrap generated (README, .gitignore; release workflow skipped).';

    return ['ok' => $ok1 && $ok2 && $ok3, 'message' => $ok1 && $ok2 && $ok3 ? $message : 'Could not write bootstrap files.'];
}

/**
 * Deep links when project GitHub owner/name are configured.
 *
 * @return array{repo_full: string, html: string, github_dev: string, cursor_vfs: string, vscode_vfs: string}|null
 */
function station_project_github_browser_links(string $slug): ?array
{
    $settings = station_project_settings($slug);
    $gh = isset($settings['github']) && is_array($settings['github']) ? $settings['github'] : [];
    $owner = trim((string) ($gh['repoOwner'] ?? ''));
    $name = trim((string) ($gh['repoName'] ?? ''));
    if ($owner === '' || $name === '') {
        return null;
    }

    return [
        'repo_full' => $owner . '/' . $name,
        'html' => 'https://github.com/' . rawurlencode($owner) . '/' . rawurlencode($name),
        'github_dev' => 'https://github.dev/' . rawurlencode($owner) . '/' . rawurlencode($name),
        'cursor_vfs' => 'cursor://vscode-vfs/github/' . $owner . '/' . $name,
        'vscode_vfs' => 'vscode://vscode-vfs/github/' . $owner . '/' . $name,
    ];
}

/**
 * Apache: write .htaccess rewrite to project-serve.php when missing (no-op if file exists).
 */
function station_write_project_access_router(string $slug, string $projectPath): void
{
    $slug = station_safe_name($slug);
    if ($slug === '') {
        return;
    }

    $htaccessPath = $projectPath . '/.htaccess';
    if (!file_exists($htaccessPath)) {
        $projectServePath = station_project_serve_path($slug);
        $rewrite = "RewriteEngine On\nRewriteRule ^$ " . $projectServePath . " [L,QSA]\nRewriteRule ^(.*)$ " . $projectServePath . "&path=$1 [L,QSA,B]\n";
        @file_put_contents($htaccessPath, $rewrite, LOCK_EX);
    }
}
