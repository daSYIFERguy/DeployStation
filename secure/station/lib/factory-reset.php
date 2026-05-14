<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/projects.php';
require_once __DIR__ . '/docker.php';

/**
 * Remove a directory tree (not the parent path itself if it equals $dir — only contents then rmdir $dir).
 */
function station_factory_reset_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($it as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

/**
 * Delete all children under $dir but keep $dir itself (for paths we must not unlink, e.g. /var/lib/…).
 *
 * @param list<string> $detail
 */
function station_factory_reset_wipe_directory_contents(string $dir, array &$detail, string $logLabel): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $ent) {
        if ($ent === '.' || $ent === '..') {
            continue;
        }
        $p = $dir . '/' . $ent;
        if (is_dir($p)) {
            station_factory_reset_rrmdir($p);
        } else {
            @unlink($p);
        }
    }
    @chmod($dir, 0775);
    $detail[] = $logLabel . ': ' . $dir;
}

/**
 * Canonical Station state paths that may exist besides the active `station_data_dir()`.
 * Only these paths under /var/lib are touched — never all of /var/lib.
 *
 * @return list<string>
 */
function station_factory_reset_known_storage_roots(): array
{
    $roots = [
        dirname(station_base_dir()) . '/.secure-station-data',
        '/var/lib/deployment-station-data',
        '/var/lib/deployment-station',
    ];
    $out = [];
    foreach ($roots as $r) {
        $r = str_replace('\\', '/', $r);
        if ($r !== '') {
            $out[$r] = true;
        }
    }

    return array_keys($out);
}

function station_factory_reset_realpath_key(string $path): string
{
    $rp = realpath($path);

    return $rp !== false ? $rp : $path;
}

/**
 * Remove leftover Station trees: alternate `.secure-station-data`, or other deployment-station
 * dirs that are not the same inode as the primary data directory (already wiped above).
 *
 * @param list<string> $detail
 */
function station_factory_reset_wipe_other_station_roots(string $primaryDataDir, array &$detail): void
{
    $primaryKey = station_factory_reset_realpath_key($primaryDataDir);
    foreach (station_factory_reset_known_storage_roots() as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $key = station_factory_reset_realpath_key($root);
        if ($key === $primaryKey) {
            continue;
        }
        $norm = str_replace('\\', '/', $root);
        if (str_ends_with($norm, '/.secure-station-data') || basename($norm) === '.secure-station-data') {
            station_factory_reset_rrmdir($root);
            $detail[] = 'Removed alternate Station directory: ' . $root;

            continue;
        }
        station_factory_reset_wipe_directory_contents(
            $root,
            $detail,
            'Cleared Station storage directory (kept empty path)'
        );
    }
}

/**
 * Collect immediate child directory names under the projects root, excluding reserved names.
 *
 * @return list<string>
 */
function station_factory_reset_project_slugs_on_disk(): array
{
    $root = station_projects_dir();
    if (!is_dir($root)) {
        return [];
    }
    $reserved = array_fill_keys(station_reserved_dirs(), true);
    $out = [];
    foreach (scandir($root) ?: [] as $ent) {
        if ($ent === '.' || $ent === '..' || isset($reserved[$ent])) {
            continue;
        }
        if (is_dir($root . '/' . $ent)) {
            $out[] = $ent;
        }
    }
    sort($out);

    return $out;
}

/**
 * Owner-only nuclear reset: compose down for each project tree, optional Docker prunes,
 * delete all project directories, wipe the active Station data directory (keep root path),
 * clear alternate Station storage paths (.secure-station-data, other /var/lib/deployment-station*
 * when not the same inode as the active data dir),
 * then recreate an empty data skeleton.
 *
 * @param array{
 *   docker_remove_volumes?: bool,
 *   docker_prune_all_images?: bool,
 *   docker_prune_stopped_containers?: bool
 * } $options
 * @return array{ok: bool, message: string, detail: list<string>}
 */
function station_factory_reset_run(array $options = []): array
{
    @set_time_limit(0);
    @ignore_user_abort(true);

    $removeVolumes = !empty($options['docker_remove_volumes']);
    $pruneImages = !empty($options['docker_prune_all_images']);
    $pruneStopped = !empty($options['docker_prune_stopped_containers']);
    $detail = [];

    $slugs = station_factory_reset_project_slugs_on_disk();
    station_log_event('admin.factory_reset.start', [
        'projects' => $slugs,
        'docker_remove_volumes' => $removeVolumes,
        'docker_prune_all_images' => $pruneImages,
        'docker_prune_stopped_containers' => $pruneStopped,
    ]);

    if (station_docker_enabled()) {
        $engine = station_docker_engine_available();
        if (!empty($engine['ok'])) {
            foreach ($slugs as $slug) {
                $compose = station_projects_dir() . '/' . $slug . '/docker-compose.yml';
                if (!is_file($compose)) {
                    continue;
                }
                $args = ['down', '--remove-orphans'];
                if ($removeVolumes) {
                    $args[] = '-v';
                }
                $r = station_run_project_docker_compose($slug, $args, 420);
                $detail[] = $slug . ': docker compose down (exit ' . (string) ($r['code'] ?? '?') . ')';
            }
            if ($pruneStopped || $pruneImages) {
                $docker = station_docker_binary();
                if ($pruneStopped) {
                    $pr = station_run_shell_cmd([$docker, 'container', 'prune', '-f'], null, 300);
                    $detail[] = 'docker container prune -f (exit ' . (string) ($pr['code'] ?? '?') . ')';
                }
                if ($pruneImages) {
                    $pr = station_run_shell_cmd([$docker, 'image', 'prune', '-af'], null, 180);
                    $detail[] = 'docker image prune -af (exit ' . (string) ($pr['code'] ?? '?') . ')';
                }
            }
        } else {
            $detail[] = 'Docker engine unreachable — skipped compose down / image prune.';
        }
    }

    $root = station_projects_dir();
    foreach ($slugs as $slug) {
        $path = $root . '/' . $slug;
        if (is_dir($path)) {
            station_factory_reset_rrmdir($path);
            $detail[] = 'Removed project directory: ' . $slug;
        }
    }

    $dataDir = station_data_dir();
    if (is_dir($dataDir)) {
        station_factory_reset_wipe_directory_contents(
            $dataDir,
            $detail,
            'Wiped Station data directory (kept root path)'
        );
    }

    station_factory_reset_wipe_other_station_roots($dataDir, $detail);

    station_ensure_data_dir();

    station_log_event('admin.factory_reset.complete', [
        'docker_remove_volumes' => $removeVolumes,
        'docker_prune_all_images' => $pruneImages,
        'docker_prune_stopped_containers' => $pruneStopped,
    ]);

    return [
        'ok' => true,
        'message' => 'Factory reset finished. Run setup again to reinitialize Station.',
        'detail' => $detail,
    ];
}
