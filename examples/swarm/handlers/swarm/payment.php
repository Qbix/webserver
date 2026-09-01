<?php
/**
 * Simulated payment handler — demonstrates the chokepoint pattern.
 *
 * This handler reads a secret API key from Q.internal.secret.
 * On the authority server, the key exists and the payment succeeds.
 * On the sandbox server, handlersUsingRemote forwards this event
 * to the authority — the sandbox never sees the key.
 *
 * In production, this would call Stripe, authorize.net, etc.
 */
function swarm_payment($params) {
    $apiKey = Q_Config::get('Q', 'internal', 'paymentApiKey', null);

    if (!$apiKey) {
        return array(
            'ok' => false,
            'error' => 'No payment API key configured — this server cannot process payments',
        );
    }

    $amount = $params['amount'] ?? 0;
    $currency = $params['currency'] ?? 'USD';
    $description = $params['description'] ?? '';

    // Simulate payment processing with the secret key
    $transactionId = 'txn_' . bin2hex(random_bytes(8));

    // In production: curl to payment API with $apiKey
    // $response = stripe_charge($apiKey, $amount, $currency);

    return array(
        'ok' => true,
        'transactionId' => $transactionId,
        'amount' => $amount,
        'currency' => $currency,
        'processedBy' => Q_Config::get('Q', 'internal', 'fingerprint', 'unknown'),
        'keyPrefix' => substr($apiKey, 0, 7) . '...',  // show we had access, don't leak
    );
}
