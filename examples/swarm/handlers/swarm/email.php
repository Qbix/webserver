<?php
/**
 * Email sending — requires SMTP credentials.
 * Only the authority has these. The sandbox forwards via handleUsingRemote.
 */
function swarm_email($params) {
    $smtp = Q_Config::get('Q', 'internal', 'smtp', null);
    if (!$smtp) {
        return array(
            'ok' => false,
            'error' => 'No SMTP credentials — this server cannot send email',
        );
    }

    $to = $params['to'] ?? '';
    $subject = $params['subject'] ?? '';
    $body = $params['body'] ?? '';
    if (!$to || !$subject) {
        return array('ok' => false, 'error' => 'to and subject required');
    }

    // In production: connect to $smtp['host']:$smtp['port']
    // with $smtp['user'] / $smtp['pass'], send via STARTTLS
    $messageId = '<' . bin2hex(random_bytes(8)) . '@' . ($smtp['host'] ?? 'localhost') . '>';

    return array(
        'ok' => true,
        'messageId' => $messageId,
        'to' => $to,
        'subject' => $subject,
        'via' => $smtp['host'] . ':' . $smtp['port'],
        'processedBy' => Q_Config::get('Q', 'internal', 'fingerprint', 'unknown'),
    );
}
