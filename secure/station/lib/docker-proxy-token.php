<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Path to the HMAC secret used for optional ?dp_t= signed access to /p/<slug>/ (nginx auth_request).
 */
function station_docker_proxy_token_secret_path(): string
{
    return station_data_dir() . '/nginx/docker-proxy-auth.secret';
}

/**
 * 64-char hex secret; created on first use with restrictive permissions.
 */
function station_docker_proxy_token_secret(): string
{
    $path = station_docker_proxy_token_secret_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (is_readable($path)) {
        $s = trim((string) @file_get_contents($path));
        if (strlen($s) >= 32) {
            return $s;
        }
    }
    $s = bin2hex(random_bytes(32));
    @file_put_contents($path, $s . "\n", LOCK_EX);
    @chmod($path, 0600);

    return $s;
}

function station_docker_proxy_token_b64u_encode(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function station_docker_proxy_token_b64u_decode(string $b64): string|false
{
    $b64 = strtr($b64, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad > 0) {
        $b64 .= str_repeat('=', 4 - $pad);
    }

    return base64_decode($b64, true);
}

/**
 * Issue a time-limited token for nginx-docker-auth.php (same slug as ?project=).
 *
 * @param int $ttlSeconds 60–86400
 */
function station_docker_proxy_token_issue(string $slug, int $ttlSeconds = 600): string
{
    $slug = station_safe_name($slug);
    $exp = time() + max(60, min(86400, $ttlSeconds));
    $nonce = bin2hex(random_bytes(8));
    $expS = (string) $exp;
    $payload = $slug . '|' . $expS . '|' . $nonce;
    $sigHex = hash_hmac('sha256', $payload, station_docker_proxy_token_secret());
    $pack = $payload . '|' . $sigHex;

    return station_docker_proxy_token_b64u_encode($pack);
}

/**
 * Validate token from ?dp_t= on the main /p/… request (nginx forwards it on the auth subrequest via $arg_dp_t).
 */
function station_docker_proxy_token_valid_for_slug(string $slug, ?string $token): bool
{
    if ($token === null) {
        return false;
    }
    $token = trim($token);
    if ($token === '') {
        return false;
    }
    $slug = station_safe_name($slug);
    if ($slug === '') {
        return false;
    }
    $raw = station_docker_proxy_token_b64u_decode($token);
    if ($raw === false || $raw === '') {
        return false;
    }
    $parts = explode('|', $raw, 4);
    if (count($parts) !== 4) {
        return false;
    }
    [$ps, $expS, $nonce, $sigHex] = $parts;
    if (station_safe_name($ps) !== $slug) {
        return false;
    }
    if (!preg_match('/^[0-9]+$/', $expS) || !preg_match('/^[a-f0-9]{16}$/', $nonce) || !preg_match('/^[a-f0-9]{64}$/i', $sigHex)) {
        return false;
    }
    $exp = (int) $expS;
    if ($exp < time()) {
        return false;
    }
    $payload = $ps . '|' . $expS . '|' . $nonce;
    $expect = hash_hmac('sha256', $payload, station_docker_proxy_token_secret());

    return hash_equals(strtolower($expect), strtolower($sigHex));
}
