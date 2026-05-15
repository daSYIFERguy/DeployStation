<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function station_assist_progress_dir(): string
{
    return station_data_dir() . '/assist-progress';
}

function station_assist_progress_sanitize_request_id(string $requestId): string
{
    $requestId = trim($requestId);
    if ($requestId === '' || strlen($requestId) > 64) {
        return '';
    }

    return preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $requestId) === 1 ? $requestId : '';
}

function station_assist_progress_path(string $requestId): string
{
    $safe = station_assist_progress_sanitize_request_id($requestId);

    return $safe !== '' ? station_assist_progress_dir() . '/' . $safe . '.json' : '';
}

/**
 * @return array{active: bool, label: string, steps: list<array{label: string, detail: string, at: string, state: string}>}
 */
function station_assist_progress_read(string $requestId): array
{
    $empty = ['active' => false, 'label' => '', 'steps' => []];
    $path = station_assist_progress_path($requestId);
    if ($path === '' || !is_file($path)) {
        return $empty;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return $empty;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $empty;
    }

    $steps = isset($data['steps']) && is_array($data['steps']) ? $data['steps'] : [];

    return [
        'active' => !empty($data['active']),
        'label' => (string) ($data['label'] ?? ''),
        'steps' => $steps,
    ];
}

/**
 * @param list<array{label: string, detail?: string, at?: string, state?: string}> $steps
 */
function station_assist_progress_write(string $requestId, bool $active, string $label, array $steps): void
{
    $path = station_assist_progress_path($requestId);
    if ($path === '') {
        return;
    }

    if (!is_dir(station_assist_progress_dir())) {
        @mkdir(station_assist_progress_dir(), 0750, true);
    }

    $payload = json_encode([
        'active' => $active,
        'label' => $label,
        'updatedAt' => gmdate('c'),
        'steps' => $steps,
    ], JSON_UNESCAPED_SLASHES);

    if (is_string($payload)) {
        @file_put_contents($path, $payload, LOCK_EX);
    }
}

function station_assist_progress_begin(string $requestId, string $label = 'Working…'): void
{
    station_assist_progress_write($requestId, true, $label, []);
}

/**
 * @return list<array{label: string, detail: string, at: string, state: string}>
 */
function station_assist_progress_load_steps(string $requestId): array
{
    $state = station_assist_progress_read($requestId);

    return isset($state['steps']) && is_array($state['steps']) ? $state['steps'] : [];
}

function station_assist_progress_step(string $requestId, string $label, string $detail = '', string $state = 'active'): void
{
    $path = station_assist_progress_path($requestId);
    if ($path === '') {
        return;
    }

    $steps = station_assist_progress_load_steps($requestId);
    $now = gmdate('c');

    foreach ($steps as $idx => $step) {
        if (!is_array($step)) {
            continue;
        }
        if (($step['state'] ?? '') === 'active') {
            $steps[$idx]['state'] = 'done';
        }
    }

    $steps[] = [
        'label' => $label,
        'detail' => $detail,
        'at' => $now,
        'state' => $state,
    ];

    station_assist_progress_write($requestId, true, $label, $steps);
}

function station_assist_progress_finish(string $requestId, bool $ok, string $label = ''): void
{
    $steps = station_assist_progress_load_steps($requestId);
    foreach ($steps as $idx => $step) {
        if (!is_array($step)) {
            continue;
        }
        if (($step['state'] ?? '') === 'active') {
            $steps[$idx]['state'] = 'done';
        }
    }

    if ($label === '') {
        $label = $ok ? 'Done' : 'Finished with errors';
    }

    $steps[] = [
        'label' => $label,
        'detail' => '',
        'at' => gmdate('c'),
        'state' => $ok ? 'done' : 'error',
    ];

    station_assist_progress_write($requestId, false, $label, $steps);

    $path = station_assist_progress_path($requestId);
    if ($path !== '' && is_file($path)) {
        @touch($path, time() + 3600);
    }
}

function station_assist_set_active_progress_request(?string $requestId): void
{
    $GLOBALS['station_assist_progress_request_id'] = station_assist_progress_sanitize_request_id((string) $requestId);
}

function station_assist_get_active_progress_request(): string
{
    return (string) ($GLOBALS['station_assist_progress_request_id'] ?? '');
}

function station_assist_progress_tick(string $label, string $detail = ''): void
{
    $requestId = station_assist_get_active_progress_request();
    if ($requestId === '') {
        return;
    }

    station_assist_progress_step($requestId, $label, $detail);
}
