<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';

station_require_owner();

$type = (string) ($_GET['type'] ?? '');
$name = (string) ($_GET['name'] ?? '');

if ($type === 'backup') {
    $safe = station_sanitize_stored_backup_zip_name($name);
    if ($safe === null) {
        http_response_code(400);
        exit('Invalid backup name.');
    }
    $path = station_archives_dir() . '/' . $safe;
    if (!is_file($path)) {
        http_response_code(404);
        exit('Backup not found.');
    }
    station_log_event('project.backup.downloaded', ['file' => $safe]);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $safe . '"');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

if ($type === 'archived') {
    $safe = station_sanitize_archived_folder_name($name);
    if ($safe === null) {
        http_response_code(400);
        exit('Invalid archive folder name.');
    }
    $source = station_archived_projects_dir() . '/' . $safe;
    if (!is_dir($source)) {
        http_response_code(404);
        exit('Archive not found.');
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'station_arch_');
    if ($tmpFile === false) {
        http_response_code(500);
        exit('Could not create temp file.');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmpFile);
        http_response_code(500);
        exit('Could not create zip.');
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $full = $item->getPathname();
        $relative = str_replace('\\', '/', str_replace($source . '/', '', $full));
        if ($item->isDir()) {
            $zip->addEmptyDir($relative);
        } else {
            $zip->addFile($full, $relative);
        }
    }
    $zip->close();

    station_log_event('project.archive.downloaded', ['name' => $safe]);
    $downloadName = $safe . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . (string) filesize($tmpFile));
    header('Cache-Control: no-store');
    readfile($tmpFile);
    @unlink($tmpFile);
    exit;
}

http_response_code(400);
exit('Unknown type. Use type=backup or type=archived.');
