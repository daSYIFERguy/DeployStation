<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function station_access_requests_path(): string
{
    return station_data_dir() . '/access-requests.json';
}

/**
 * @return list<array<string, mixed>>
 */
function station_access_requests_load(): array
{
    $data = station_read_json(station_access_requests_path(), ['requests' => []]);
    $list = $data['requests'] ?? [];

    return is_array($list) ? array_values($list) : [];
}

/**
 * @param list<array<string, mixed>> $requests
 */
function station_access_requests_save(array $requests): bool
{
    return station_write_json(station_access_requests_path(), [
        'updatedAt' => gmdate('c'),
        'requests' => array_values($requests),
    ]);
}

/**
 * @return list<array<string, mixed>>
 */
function station_access_requests_pending(): array
{
    return array_values(array_filter(
        station_access_requests_load(),
        static fn (array $r): bool => (string) ($r['status'] ?? '') === 'pending'
    ));
}

function station_access_requests_pending_count(): int
{
    return count(station_access_requests_pending());
}

function station_access_requests_unseen_count(): int
{
    $n = 0;
    foreach (station_access_requests_pending() as $r) {
        if (empty($r['seenByOwner'])) {
            $n++;
        }
    }

    return $n;
}

function station_access_requests_queue_owner_alert(): void
{
    $user = function_exists('station_current_user') ? station_current_user() : null;
    if ($user === null || !function_exists('station_is_owner') || !station_is_owner($user)) {
        return;
    }
    if (station_access_requests_unseen_count() > 0) {
        $_SESSION['station_access_requests_alert'] = true;
    }
}

function station_access_requests_clear_owner_alert_session(): void
{
    unset($_SESSION['station_access_requests_alert']);
}

function station_access_request_mark_all_seen(): void
{
    $changed = false;
    $list = station_access_requests_load();
    foreach ($list as $i => $r) {
        if ((string) ($r['status'] ?? '') === 'pending' && empty($r['seenByOwner'])) {
            $list[$i]['seenByOwner'] = true;
            $changed = true;
        }
    }
    if ($changed) {
        station_access_requests_save($list);
    }
}

/**
 * @param array{name?: string, email?: string, githubLogin?: string, githubId?: int|string, githubName?: string, githubAvatar?: string} $data
 * @return array{ok: bool, message: string, id?: string}
 */
function station_access_request_create(array $data): array
{
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $name = trim((string) ($data['name'] ?? ''));
    $githubLogin = trim((string) ($data['githubLogin'] ?? ''));

    if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'A valid name and email are required.'];
    }
    if ($githubLogin === '') {
        return ['ok' => false, 'message' => 'GitHub verification is required.'];
    }

    $list = station_access_requests_load();
    foreach ($list as $r) {
        if ((string) ($r['status'] ?? '') !== 'pending') {
            continue;
        }
        if (strtolower((string) ($r['email'] ?? '')) === $email
            || (string) ($r['githubLogin'] ?? '') === $githubLogin) {
            return ['ok' => false, 'message' => 'You already have a pending access request.'];
        }
    }

    $id = 'req_' . bin2hex(random_bytes(8));
    $list[] = [
        'id' => $id,
        'status' => 'pending',
        'name' => $name,
        'email' => $email,
        'githubLogin' => $githubLogin,
        'githubId' => (string) ($data['githubId'] ?? ''),
        'githubName' => trim((string) ($data['githubName'] ?? '')),
        'githubAvatar' => trim((string) ($data['githubAvatar'] ?? '')),
        'requestedAt' => gmdate('c'),
        'seenByOwner' => false,
    ];

    if (!station_access_requests_save($list)) {
        return ['ok' => false, 'message' => 'Could not save your request.'];
    }

    station_log_event('access.request.created', [
        'id' => $id,
        'email' => $email,
        'githubLogin' => $githubLogin,
    ]);

    return ['ok' => true, 'message' => 'Request submitted.', 'id' => $id];
}

/**
 * @return array{ok: bool, message: string}
 */
function station_access_request_update_status(string $id, string $status): array
{
    $status = strtolower(trim($status));
    if (!in_array($status, ['pending', 'approved', 'dismissed'], true)) {
        return ['ok' => false, 'message' => 'Invalid status.'];
    }

    $list = station_access_requests_load();
    $found = false;
    foreach ($list as $i => $r) {
        if ((string) ($r['id'] ?? '') !== $id) {
            continue;
        }
        $list[$i]['status'] = $status;
        $list[$i]['updatedAt'] = gmdate('c');
        $found = true;
        break;
    }

    if (!$found) {
        return ['ok' => false, 'message' => 'Request not found.'];
    }

    if (!station_access_requests_save($list)) {
        return ['ok' => false, 'message' => 'Could not update request.'];
    }

    station_log_event('access.request.' . $status, ['id' => $id]);

    return ['ok' => true, 'message' => 'Request updated.'];
}
