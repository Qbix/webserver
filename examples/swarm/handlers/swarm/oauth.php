<?php
/**
 * OAuth token exchange — requires client_secret.
 * Only the authority has the secret. The sandbox forwards the code exchange
 * via handleUsingRemote, never seeing the client_secret.
 */
function swarm_oauth($params) {
    $oauth = Q_Config::get('Q', 'internal', 'oauth', null);
    if (!$oauth) {
        return array(
            'ok' => false,
            'error' => 'No OAuth credentials — this server cannot exchange tokens',
        );
    }

    $provider = $params['provider'] ?? 'google';
    $code = $params['code'] ?? '';
    $redirectUri = $params['redirectUri'] ?? '';
    if (!$code) {
        return array('ok' => false, 'error' => 'authorization code required');
    }

    // In production: POST to provider's token endpoint with
    // client_id, client_secret, code, redirect_uri, grant_type
    // $response = curl_post($oauth[$provider]['tokenUrl'], [...])
    $accessToken = 'at_' . bin2hex(random_bytes(16));
    $refreshToken = 'rt_' . bin2hex(random_bytes(16));

    return array(
        'ok' => true,
        'provider' => $provider,
        'accessToken' => $accessToken,
        'refreshToken' => $refreshToken,
        'expiresIn' => 3600,
        'clientId' => $oauth[$provider]['clientId'] ?? 'unknown',
        'processedBy' => Q_Config::get('Q', 'internal', 'fingerprint', 'unknown'),
    );
}
