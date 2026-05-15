<?php

declare(strict_types=1);

/**
 * @return array{webEntryManual: bool, webEntryDir: string, webEntryFile: string}
 */
function station_project_launch_config(array $settings): array
{
    $launch = isset($settings['launch']) && is_array($settings['launch']) ? $settings['launch'] : [];

    return [
        'webEntryManual' => !empty($launch['webEntryManual']),
        'webEntryDir' => trim(str_replace('\\', '/', (string) ($launch['webEntryDir'] ?? '')), '/'),
        'webEntryFile' => trim((string) ($launch['webEntryFile'] ?? '')) ?: 'index.html',
    ];
}

/**
 * Resolve manual or root web entrypoint for /p/&lt;slug&gt;/ serving.
 *
 * @return array{dir: string, file: string, relative: string, absolute: string, manual: bool}|null
 */
function station_project_resolve_web_entry(string $projectSlug): ?array
{
    $slug = station_safe_name($projectSlug);
    $root = station_project_path($slug);
    if (!is_dir($root)) {
        return null;
    }

    $settings = station_project_settings($slug);
    $cfg = station_project_launch_config($settings);
    if (!$cfg['webEntryManual']) {
        return null;
    }

    $dir = $cfg['webEntryDir'];
    $file = $cfg['webEntryFile'];
    if ($dir !== '' && !station_is_safe_relative_path($dir)) {
        return null;
    }
    if ($file === '' || str_contains($file, '/') || str_contains($file, '\\')) {
        return null;
    }

    $base = $dir === '' ? $root : $root . '/' . $dir;
    if (!is_dir($base) || !station_realpath_inside($root, $base)) {
        return null;
    }

    $candidate = rtrim($base, '/') . '/' . $file;
    if (!is_file($candidate) || !station_realpath_inside($root, $candidate)) {
        return null;
    }

    $relative = ($dir !== '' ? $dir . '/' : '') . $file;

    return [
        'dir' => $dir,
        'file' => $file,
        'relative' => $relative,
        'absolute' => $candidate,
        'manual' => true,
    ];
}

/**
 * @return array{ok: bool, message: string, dir?: string, file?: string}
 */
function station_project_validate_web_entry_input(string $slug, string $dir, string $file): array
{
    $dir = trim(str_replace('\\', '/', $dir), '/');
    $file = trim($file) ?: 'index.html';
    if ($dir !== '' && !station_is_safe_relative_path($dir)) {
        return ['ok' => false, 'message' => 'Entry directory path is not allowed.'];
    }
    if (str_contains($file, '/') || str_contains($file, '\\')) {
        return ['ok' => false, 'message' => 'Entry file must be a filename only (e.g. index.html).'];
    }

    $root = station_project_path($slug);
    $base = $dir === '' ? $root : $root . '/' . $dir;
    if (!is_dir($base) || !station_realpath_inside($root, $base)) {
        return ['ok' => false, 'message' => 'That directory does not exist in the project.'];
    }

    $candidate = rtrim($base, '/') . '/' . $file;
    if (!is_file($candidate) || !station_realpath_inside($root, $candidate)) {
        $allowed = implode(', ', station_project_web_index_basenames());

        return ['ok' => false, 'message' => 'No ' . $file . ' in that folder. Choose a directory that contains ' . $allowed . '.'];
    }

    return ['ok' => true, 'message' => 'OK', 'dir' => $dir, 'file' => $file];
}

/**
 * Detect how a project can be launched (web app, extension, CLI-only, etc.).
 *
 * @return array{
 *   launchable: bool,
 *   launchKind: string,
 *   tone: string,
 *   title: string,
 *   summary: string,
 *   stack: string,
 *   hints: list<string>,
 *   webEntryManual?: bool,
 *   webEntryRelative?: string
 * }
 */
function station_project_launch_profile(string $projectSlug): array
{
    $slug = station_safe_name($projectSlug);
    $path = station_project_path($slug);
    $profile = [
        'launchable' => false,
        'launchKind' => 'unknown',
        'tone' => 'warn',
        'title' => $slug,
        'summary' => 'Open Manage to configure how this project runs.',
        'stack' => station_infer_docker_stack($slug),
        'hints' => [],
        'webEntryManual' => false,
        'webEntryRelative' => '',
    ];

    if (!is_dir($path)) {
        return $profile;
    }

    $manualEntry = station_project_resolve_web_entry($slug);
    if ($manualEntry !== null) {
        $profile['launchable'] = true;
        $profile['launchKind'] = 'web';
        $profile['tone'] = 'ok';
        $profile['title'] = $slug;
        $profile['webEntryManual'] = true;
        $profile['webEntryRelative'] = (string) $manualEntry['relative'];
        $profile['summary'] = 'Web entrypoint set manually at ' . $manualEntry['relative'] . ' — use Launch to open /p/' . $slug . '/.';
        $profile['hints'][] = 'Change under Manage → General → Web entrypoint.';

        return $profile;
    }

    if (is_file($path . '/manifest.json')) {
        $manifest = @json_decode((string) @file_get_contents($path . '/manifest.json'), true);
        if (is_array($manifest) && isset($manifest['manifest_version'])) {
            $profile['launchable'] = true;
            $profile['launchKind'] = 'chrome-extension';
            $profile['tone'] = 'ok';
            $profile['title'] = (string) ($manifest['name'] ?? $slug);
            $profile['summary'] = 'Chrome extension — download the zip and load unpacked in chrome://extensions.';

            return $profile;
        }
    }

    $hasIndex = is_file($path . '/index.html') || is_file($path . '/index.php') || is_file($path . '/public/index.php');
    $hasNode = is_file($path . '/package.json');
    $hasPhp = is_file($path . '/composer.json') || is_file($path . '/index.php');
    $hasPython = is_file($path . '/requirements.txt') || is_file($path . '/pyproject.toml');

    if ($hasIndex || $hasPhp) {
        $profile['launchable'] = true;
        $profile['launchKind'] = 'web';
        $profile['tone'] = 'ok';
        $profile['title'] = $slug;
        $profile['summary'] = 'Static or PHP web app — use Launch when the server route or container is running.';
        $profile['hints'][] = 'Served under /p/' . $slug . '/ when nginx routing is configured.';

        return $profile;
    }

    if ($hasNode) {
        $pkg = @json_decode((string) @file_get_contents($path . '/package.json'), true);
        $scripts = is_array($pkg['scripts'] ?? null) ? $pkg['scripts'] : [];
        if (isset($scripts['start']) || isset($scripts['dev'])) {
            $profile['launchable'] = true;
            $profile['launchKind'] = 'web';
            $profile['tone'] = 'ok';
            $profile['title'] = (string) ($pkg['name'] ?? $slug);
            $profile['summary'] = 'Node app with npm start/dev — enable Docker or run the process, then open Launch.';
            $profile['stack'] = 'node';

            return $profile;
        }
        $profile['launchKind'] = 'node-cli';
        $profile['summary'] = 'Node project without a start script — add "start" in package.json or enable Docker.';
        $profile['hints'][] = 'Use Files to edit package.json, or Manage → Docker.';

        return $profile;
    }

    if ($hasPython) {
        $profile['launchable'] = true;
        $profile['launchKind'] = 'web';
        $profile['tone'] = 'ok';
        $profile['summary'] = 'Python app — typically port 8000 inside Docker.';
        $profile['stack'] = 'python';

        return $profile;
    }

    $files = @scandir($path) ?: [];
    $fileCount = count(array_filter($files, static fn ($f) => $f !== '.' && $f !== '..'));
    if ($fileCount > 0) {
        $profile['launchKind'] = 'files-only';
        $profile['summary'] = 'Project files on disk — not detected as a web entrypoint. Use Manage → General → Select entrypoint if index.html is in a subfolder.';
        $profile['hints'][] = 'Manage → General → Select entrypoint, or add index.html at the project root, or enable Docker.';
    }

    return $profile;
}

require_once __DIR__ . '/app-explorer-ui.php';
