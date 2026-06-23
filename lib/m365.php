<?php
/**
 * Microsoft 365 (Microsoft Graph) directory sync — directory only, no SSO.
 *
 * Dormant until configured in config.php under the 'm365' block. Uses the
 * client-credentials (app-only) OAuth2 flow, so it needs an Entra app
 * registration with the Application permission "User.Read.All" + admin consent.
 *
 * Secrets live only in config.php and are never returned to the browser or
 * written to the activity log. Network/auth failures are error_log'd with
 * detail but surfaced to the caller as a generic message.
 */

/** True when an M365 connection is fully configured and switched on. */
function m365_enabled(): bool {
    return (bool) cfg('m365.enabled', false)
        && clean(cfg('m365.tenant_id', ''))     !== null
        && clean(cfg('m365.client_id', ''))     !== null
        && clean(cfg('m365.client_secret', '')) !== null;
}

/** Low-level JSON HTTP helper around curl. Returns [httpStatus, decodedBody|null]. */
function m365_http(string $method, string $url, array $opts = []): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('curl is not available on this server.');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => $opts['headers'] ?? [],
    ]);
    if (isset($opts['body'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        error_log('M365 HTTP error: ' . $err);
        throw new RuntimeException('Could not reach Microsoft 365.');
    }
    $decoded = json_decode((string) $raw, true);
    return [$status, is_array($decoded) ? $decoded : null];
}

/** Fetch an app-only access token. Throws on failure (without leaking the secret). */
function m365_token(): string {
    $tenant = rawurlencode((string) cfg('m365.tenant_id', ''));
    $url    = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";
    $body   = http_build_query([
        'client_id'     => (string) cfg('m365.client_id', ''),
        'client_secret' => (string) cfg('m365.client_secret', ''),
        'scope'         => 'https://graph.microsoft.com/.default',
        'grant_type'    => 'client_credentials',
    ]);

    [$status, $data] = m365_http('POST', $url, [
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'body'    => $body,
    ]);

    if ($status !== 200 || empty($data['access_token'])) {
        // Log Microsoft's error code/description, but never the request body (it holds the secret).
        error_log('M365 token failed (' . $status . '): ' . ($data['error_description'] ?? $data['error'] ?? 'unknown'));
        throw new RuntimeException('Microsoft 365 sign-in failed. Check the tenant/client/secret in config.php.');
    }
    return (string) $data['access_token'];
}

/**
 * Fetch all users from the directory (paged). Returns a list of normalized rows:
 * [m365_id, display_name, email, upn, job_title, department, active].
 */
function m365_fetch_users(): array {
    $token = m365_token();
    $url   = 'https://graph.microsoft.com/v1.0/users'
           . '?$select=id,displayName,mail,userPrincipalName,jobTitle,department,accountEnabled'
           . '&$top=100';

    $out = [];
    $guard = 0; // never loop forever on a misbehaving nextLink
    while ($url && $guard++ < 200) {
        [$status, $data] = m365_http('GET', $url, [
            'headers' => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        ]);
        if ($status !== 200 || !isset($data['value'])) {
            error_log('M365 user fetch failed (' . $status . '): ' . ($data['error']['message'] ?? 'unknown'));
            throw new RuntimeException('Microsoft 365 rejected the directory request. Confirm the app has User.Read.All with admin consent.');
        }
        foreach ($data['value'] as $u) {
            $name = clean($u['displayName'] ?? null)
                 ?? clean($u['userPrincipalName'] ?? null)
                 ?? clean($u['mail'] ?? null);
            if (!isset($u['id']) || $name === null) continue; // skip rows we can't identify
            $out[] = [
                'm365_id'      => (string) $u['id'],
                'display_name' => $name,
                'email'        => clean($u['mail'] ?? null),
                'upn'          => clean($u['userPrincipalName'] ?? null),
                'job_title'    => clean($u['jobTitle'] ?? null),
                'department'   => clean($u['department'] ?? null),
                'active'       => array_key_exists('accountEnabled', $u) ? (int) (bool) $u['accountEnabled'] : 1,
            ];
        }
        $url = $data['@odata.nextLink'] ?? null;
    }
    return $out;
}
