<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/access-requests.php';

station_require_owner();
station_access_requests_clear_owner_alert_session();

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true]);
