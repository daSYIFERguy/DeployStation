<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/host-health.php';

station_require_owner();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode(station_host_health_live_payload(), JSON_UNESCAPED_SLASHES);
exit;
