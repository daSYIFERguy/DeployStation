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
 * Owner-only nuclear reset: compose down for each project tree, optional volume removal and image prune,
 * delete all project directories, wipe the Station data directory, then recreate an empty data skeleton.
 *
 * @param array{
 *   docker_remove_volumes?: bool,
 *   docker_prune_all_images?: bool
 * } $options
 * @return array{ok: bool, message: string, detail: list<string>}
 */
function station_factory_reset_run(array $options = []): array
{
    @set_time_limit(0);
    @ignore_user_abort(true);

    $removeVolumes = !empty($options['docker_remove_volumes']);
    $pruneImages = !empty($options['docker_prune_all_images']);
    $detail = [];

    $slugs = station_factory_reset_project_slugs_on_disk();
    station_log_event('admin.factory_reset.start', [
        'projects' => $slugs,
        'docker_remove_volumes' => $removeVolumes,
        'docker_prune_all_images' => $pruneImages,
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
            if ($pruneImages) {
                $docker = station_docker_binary();
                $pr = station_run_shell_cmd([$docker, 'image', 'prune', '-af'], null, 180);
                $detail[] = 'docker image prune -af (exit ' . (string) ($pr['code'] ?? '?') . ')';
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
        foreach (scandir($dataDir) ?: [] as $ent) {
            if ($ent === '.' || $ent === '..') {
                continue;
            }
            $p = $dataDir . '/' . $ent;
            if (is_dir($p)) {
                station_factory_reset_rrmdir($p);
            } else {
                @unlink($p);
            }
        }
        $detail[] = 'Wiped Station data directory: ' . $dataDir;
    }

    station_ensure_data_dir();

    station_log_event('admin.factory_reset.complete', [
        'docker_remove_volumes' => $removeVolumes,
        'docker_prune_all_images' => $pruneImages,
    ]);

    return [
        'ok' => true,
        'message' => 'Factory reset finished. Run setup again to reinitialize Station.',
        'detail' => $detail,
    ];
}
