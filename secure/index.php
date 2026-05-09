<?php

declare(strict_types=1);

$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/secure/index.php'));
$basePath = str_replace('\\', '/', dirname($scriptName));
if ($basePath === '.' || $basePath === '/' || $basePath === '\\') {
	$basePath = '';
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Location: ' . ($basePath !== '' ? $basePath : '') . '/station/', true, 302);
exit;
