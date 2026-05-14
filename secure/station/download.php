<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/projects.php';

station_require_login();

$slug = station_safe_name((string) ($_GET['project'] ?? ''));
if ($slug === '' || !station_project_exists($slug)) {
    http_response_code(404);
    exit('Project not found.');
}

$user = station_current_user();
if (!station_user_may_access_project($user, $slug)) {
    http_response_code(403);
    exit('Forbidden.');
}

$path = station_project_path($slug);
$tmpFile = tempnam(sys_get_temp_dir(), 'station_dl_');
if ($tmpFile === false) {
    http_response_code(500);
    exit('Could not create temp file.');
}

$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Could not create zip.');
}

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($it as $item) {
    $full     = $item->getPathname();
    $relative = str_replace('\\', '/', str_replace($path . '/', '', $full));
    if ($item->isDir()) {
        $zip->addEmptyDir($relative);
    } else {
        $zip->addFile($full, $relative);
    }
}
$zip->close();

station_log_event('project.download', ['slug' => $slug]);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $slug . '.zip"');
header('Content-Length: ' . (string) filesize($tmpFile));
header('Cache-Control: no-store');
readfile($tmpFile);
@unlink($tmpFile);
exit;
