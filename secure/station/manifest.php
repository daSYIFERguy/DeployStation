<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

$uiConfig = station_ui_config();
$icons = station_pwa_icons();
$appName = trim((string) ($uiConfig['appName'] ?? 'Deployment Station'));
$shortName = trim((string) ($uiConfig['heading'] ?? 'Deployment Station'));

$manifest = [
    'id' => '/secure/station/',
    'name' => $appName,
    'short_name' => $shortName !== '' ? $shortName : $appName,
    'start_url' => './index.php',
    'scope' => './',
    'display' => 'standalone',
    'background_color' => '#eef3fb',
    'theme_color' => (string) ($uiConfig['themeColor'] ?? '#2f7de2'),
    'description' => trim((string) ($uiConfig['subheading'] ?? '')) ?: $appName,
    'icons' => []
];

if (($icons['192'] ?? '') !== '') {
    $manifest['icons'][] = [
        'src' => $icons['192'],
        'sizes' => '192x192',
        'purpose' => 'any'
    ];
}

if (($icons['512'] ?? '') !== '') {
    $manifest['icons'][] = [
        'src' => $icons['512'],
        'sizes' => '512x512',
        'purpose' => 'any'
    ];
}

if (($icons['maskable'] ?? '') !== '') {
    $manifest['icons'][] = [
        'src' => $icons['maskable'],
        'sizes' => '512x512',
        'purpose' => 'maskable'
    ];
}

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);