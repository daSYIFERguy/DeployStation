<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * OAuth redirect URI registered in the GitHub OAuth App must match this exactly.
 */
function station_github_oauth_redirect_uri(): string
{
    $base = station_request_script_dir_url();

    return $base === '' ? '' : $base . '/github-oauth-callback.php';
}

function station_github_oauth_authorize_url(string $clientId, string $state, string $scope = 'repo read:user'): string
{
    $redirect = station_github_oauth_redirect_uri();
    if ($redirect === '') {
        return '';
    }

    return 'https://github.com/login/oauth/authorize?' . http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirect,
        'scope' => trim($scope) !== '' ? trim($scope) : 'read:user',
        'state' => $state,
    ]);
}

/**
 * @return array{ok: bool, access_token?: string, token_type?: string, scope?: string, message: string}
 */
function station_github_oauth_exchange_code(string $clientId, string $clientSecret, string $code, string $redirectUri): array
{
    $clientId = trim($clientId);
    $clientSecret = trim($clientSecret);
    $code = trim($code);
    if ($clientId === '' || $clientSecret === '' || $code === '') {
        return ['ok' => false, 'message' => 'Missing OAuth client configuration or authorization code.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'PHP curl extension is required for GitHub OAuth.'];
    }

    $ch = curl_init('https://github.com/login/oauth/access_token');
    if ($ch === false) {
        return ['ok' => false, 'message' => 'Could not initialize curl.'];
    }

    $payload = http_build_query([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'code' => $code,
        'redirect_uri' => $redirectUri,
    ]);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: DeploymentStation-GitHubOAuth/1',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $body = (string) curl_exec($ch);
    $codeHttp = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === '' && $err !== '') {
        return ['ok' => false, 'message' => $err];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return ['ok' => false, 'message' => 'Unexpected token response (HTTP ' . $codeHttp . ').'];
    }

    if (!empty($data['error'])) {
        $desc = (string) ($data['error_description'] ?? $data['error']);

        return ['ok' => false, 'message' => 'GitHub OAuth error: ' . $desc];
    }

    $token = trim((string) ($data['access_token'] ?? ''));
    if ($token === '') {
        return ['ok' => false, 'message' => 'No access_token in GitHub response.'];
    }

    return [
        'ok' => true,
        'message' => 'OK',
        'access_token' => $token,
        'token_type' => (string) ($data['token_type'] ?? ''),
        'scope' => (string) ($data['scope'] ?? ''),
    ];
}
