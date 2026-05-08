<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

station_require_login();

$username = station_current_username();
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? 'get');

$safeUser = station_safe_name($username);
$filesDir = station_user_profiles_dir() . '/' . $safeUser . '.clipboard-files';
$filesMetaPath = station_user_profiles_dir() . '/' . $safeUser . '.clipboard-files.json';

$readFilesMeta = static function () use ($filesMetaPath): array {
    if (!file_exists($filesMetaPath)) {
        return [];
    }
    $data = json_decode((string) (@file_get_contents($filesMetaPath) ?: ''), true);
    return is_array($data) ? array_values($data) : [];
};

$saveFilesMeta = static function (array $files) use ($filesMetaPath): bool {
    $json = json_encode(array_values($files), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }
    return @file_put_contents($filesMetaPath, $json, LOCK_EX) !== false;
};

if ($action === 'file' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $name = basename((string) ($_GET['name'] ?? ''));
    if ($name === '') {
        http_response_code(404);
        exit('Not found');
    }
    $path = $filesDir . '/' . $name;
    if (!is_file($path)) {
        http_response_code(404);
        exit('Not found');
    }

    $mime = (string) (mime_content_type($path) ?: 'application/octet-stream');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: inline; filename="' . rawurlencode($name) . '"');
    readfile($path);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = (string) ($_POST['content'] ?? '');
    $ok = station_save_user_clipboard($username, $content);
    echo json_encode(['ok' => $ok]);
    exit;
}

if ($action === 'upload_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['clip_file']) || !is_array($_FILES['clip_file'])) {
        echo json_encode(['ok' => false, 'error' => 'No file uploaded']);
        exit;
    }
    $file = $_FILES['clip_file'];
    $tmp = (string) ($file['tmp_name'] ?? '');
    $origName = basename((string) ($file['name'] ?? 'file'));
    $size = (int) ($file['size'] ?? 0);
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($errorCode !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        echo json_encode(['ok' => false, 'error' => 'Upload failed']);
        exit;
    }
    if ($size > 20 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'File too large']);
        exit;
    }

    if (!is_dir($filesDir) && !@mkdir($filesDir, 0775, true) && !is_dir($filesDir)) {
        echo json_encode(['ok' => false, 'error' => 'Could not prepare storage']);
        exit;
    }

    $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $origName) ?: 'file.bin';
    $storedName = gmdate('YmdHis') . '-' . bin2hex(random_bytes(4)) . '-' . $safeBase;
    $target = $filesDir . '/' . $storedName;
    if (!@move_uploaded_file($tmp, $target)) {
        echo json_encode(['ok' => false, 'error' => 'Could not store file']);
        exit;
    }

    $mime = (string) (mime_content_type($target) ?: 'application/octet-stream');
    $files = $readFilesMeta();
    array_unshift($files, [
        'name' => $storedName,
        'originalName' => $origName,
        'size' => filesize($target),
        'mime' => $mime,
        'isImage' => str_starts_with($mime, 'image/'),
        'url' => 'clipboard.php?action=file&name=' . rawurlencode($storedName)
    ]);

    if (count($files) > 20) {
        $toDrop = array_slice($files, 20);
        foreach ($toDrop as $drop) {
            $dropName = basename((string) ($drop['name'] ?? ''));
            if ($dropName !== '') {
                @unlink($filesDir . '/' . $dropName);
            }
        }
        $files = array_slice($files, 0, 20);
    }

    $saveFilesMeta($files);
    echo json_encode(['ok' => true, 'files' => $files]);
    exit;
}

if ($action === 'remove_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = basename((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing file']);
        exit;
    }

    $files = $readFilesMeta();
    $nextFiles = [];
    $removed = false;

    foreach ($files as $entry) {
        $entryName = basename((string) ($entry['name'] ?? ''));
        if ($entryName === $name) {
            @unlink($filesDir . '/' . $entryName);
            $removed = true;
            continue;
        }
        $nextFiles[] = $entry;
    }

    $saveFilesMeta($nextFiles);
    echo json_encode(['ok' => $removed, 'files' => $nextFiles]);
    exit;
}

if ($action === 'clear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    station_save_user_clipboard($username, '');
    $files = $readFilesMeta();
    foreach ($files as $entry) {
        $name = basename((string) ($entry['name'] ?? ''));
        if ($name !== '') {
            @unlink($filesDir . '/' . $name);
        }
    }
    $saveFilesMeta([]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode([
    'ok' => true,
    'content' => station_get_user_clipboard($username),
    'files' => $readFilesMeta()
]);
