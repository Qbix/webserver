<?php

/**
 * Q_Relay_Mobile
 *
 * Twilio SMS integration for the Qbix Server relay system.
 * Sends outbound SMS via Twilio's REST API and receives inbound SMS
 * via webhook. This is the mobile channel counterpart to the email relay.
 *
 * @package Q
 */
class Q_Relay_Mobile
{
	/**
	 * Send an SMS via the Twilio REST API.
	 *
	 * @param string $to Recipient phone number (E.164 format preferred)
	 * @param string $body The message body (max 1600 characters)
	 * @param array $options Optional overrides:
	 *   "from" => override the default From number
	 *   "statusCallback" => URL for delivery status webhook
	 *   "accountSid" => override account SID
	 *   "authToken" => override auth token
	 * @return array With keys 'sid' and 'status'
	 * @throws Exception if configuration is missing or the API call fails
	 */
	static function send($to, $body, $options = [])
	{
		$accountSid = isset($options['accountSid'])
			? $options['accountSid']
			: Q_Config::get('Q', 'relay', 'twilio', 'accountSid',
				Q_Config::get('Users', 'relay', 'twilio', 'accountSid', null)
			);
		$authToken = isset($options['authToken'])
			? $options['authToken']
			: Q_Config::get('Q', 'relay', 'twilio', 'authToken',
				Q_Config::get('Users', 'relay', 'twilio', 'authToken', null)
			);
		if (!$accountSid || !$authToken) {
			throw new Exception(
				"Q_Relay_Mobile::send: Twilio accountSid and authToken are required. "
				. "Set Q.relay.twilio.accountSid and Q.relay.twilio.authToken in config."
			);
		}

		$from = isset($options['from'])
			? $options['from']
			: Q_Config::get('Q', 'relay', 'twilio', 'fromNumber',
				Q_Config::get('Users', 'relay', 'twilio', 'fromNumber', null)
			);
		if (!$from) {
			throw new Exception(
				"Q_Relay_Mobile::send: No From number specified. "
				. "Set Q.relay.twilio.fromNumber in config or pass 'from' in options."
			);
		}

		// Normalize the recipient number
		$to = self::formatE164($to);

		// Check rate limiter if available
		if (class_exists('Q_Relay_RateLimiter')) {
			Q_Relay_RateLimiter::acquire('sms', $to);
		}

		// Build the POST payload
		$postData = array(
			'To' => $to,
			'From' => $from,
			'Body' => $body
		);

		$statusCallback = isset($options['statusCallback'])
			? $options['statusCallback']
			: Q_Config::get('Q', 'relay', 'twilio', 'statusCallback',
				Q_Config::get('Users', 'relay', 'twilio', 'statusCallback', null)
			);
		if ($statusCallback) {
			$postData['StatusCallback'] = $statusCallback;
		}

		$url = "https://api.twilio.com/2010-04-01/Accounts/"
			. urlencode($accountSid)
			. "/Messages.json";

		$response = self::_twilioRequest('POST', $url, $accountSid, $authToken, $postData);

		if (isset($response['error_code']) && $response['error_code']) {
			$errorMessage = isset($response['error_message'])
				? $response['error_message']
				: 'Unknown Twilio error';
			throw new Exception(
				"Q_Relay_Mobile::send: Twilio API error {$response['error_code']}: $errorMessage"
			);
		}

		if (!isset($response['sid'])) {
			throw new Exception(
				"Q_Relay_Mobile::send: Unexpected Twilio response, no message SID returned."
			);
		}

		$result = array(
			'sid' => $response['sid'],
			'status' => isset($response['status']) ? $response['status'] : 'unknown'
		);

		// Log the delivery
		if (class_exists('Q_Relay_Db')) {
			Q_Relay_Db::logDelivery('sms', 'outbound', array(
				'to' => $to,
				'from' => $from,
				'sid' => $result['sid'],
				'status' => $result['status'],
				'bodyLength' => strlen($body)
			));
		}

		return $result;
	}

	/**
	 * Send a templated SMS message.
	 *
	 * Loads a named template from config, replaces {{variable}} placeholders,
	 * and sends the result via send().
	 *
	 * @param string $to Recipient phone number
	 * @param string $templateName The template key under Q.relay.sms.templates
	 * @param array $variables Key-value pairs to substitute into the template
	 * @param array $options Passed through to send()
	 * @return array Result from send()
	 * @throws Exception if the template is not found
	 */
	static function sendTemplate($to, $templateName, $variables = [], $options = [])
	{
		$template = Q_Config::get('Q', 'relay', 'sms', 'templates', $templateName,
			Q_Config::get('Users', 'relay', 'sms', 'templates', $templateName, null)
		);
		if (!$template) {
			throw new Exception(
				"Q_Relay_Mobile::sendTemplate: Template '$templateName' not found. "
				. "Set Q.relay.sms.templates.$templateName in config."
			);
		}

		// Replace {{variable}} placeholders
		$body = $template;
		foreach ($variables as $key => $value) {
			$body = str_replace('{{' . $key . '}}', $value, $body);
		}

		// Fire event hook before sending
		if (class_exists('Q') && method_exists('Q', 'event')) {
			Q::event('Q/relay/sms/send', array(
				'to' => $to,
				'templateName' => $templateName,
				'variables' => $variables,
				'body' => &$body,
				'options' => &$options
			));
		}

		return self::send($to, $body, $options);
	}

	/**
	 * Process an inbound SMS received from a Twilio webhook.
	 *
	 * Validates the request signature (if configured), extracts message
	 * parameters, logs the delivery, fires an event, and returns a TwiML
	 * response string.
	 *
	 * @param array $params The webhook POST parameters from Twilio
	 * @return string TwiML response XML
	 */
	static function handleWebhook($params)
	{
		// Validate Twilio signature if auth token is configured
		$authToken = Q_Config::get('Q', 'relay', 'twilio', 'authToken',
			Q_Config::get('Users', 'relay', 'twilio', 'authToken', null)
		);
		if ($authToken) {
			$signature = isset($_SERVER['HTTP_X_TWILIO_SIGNATURE'])
				? $_SERVER['HTTP_X_TWILIO_SIGNATURE']
				: null;
			if ($signature) {
				// Reconstruct the request URL
				$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
					? 'https' : 'http';
				$url = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
				if (!self::validateSignature($url, $params, $signature, $authToken)) {
					throw new Exception(
						"Q_Relay_Mobile::handleWebhook: Invalid Twilio signature."
					);
				}
			}
		}

		// Extract standard fields
		$from = isset($params['From']) ? $params['From'] : null;
		$to = isset($params['To']) ? $params['To'] : null;
		$body = isset($params['Body']) ? $params['Body'] : '';
		$messageSid = isset($params['MessageSid']) ? $params['MessageSid'] : null;
		$numMedia = isset($params['NumMedia']) ? (int)$params['NumMedia'] : 0;

		// Collect any media URLs
		$mediaUrls = array();
		for ($i = 0; $i < $numMedia; $i++) {
			$key = "MediaUrl$i";
			if (isset($params[$key])) {
				$mediaUrls[] = $params[$key];
			}
		}

		// Log the inbound message
		if (class_exists('Q_Relay_Db')) {
			Q_Relay_Db::logDelivery('sms', 'inbound', array(
				'from' => $from,
				'to' => $to,
				'sid' => $messageSid,
				'body' => $body,
				'numMedia' => $numMedia,
				'mediaUrls' => $mediaUrls
			));
		}

		// Fire event for application-level handling
		if (class_exists('Q') && method_exists('Q', 'event')) {
			Q::event('Q/relay/sms/received', array(
				'from' => $from,
				'to' => $to,
				'body' => $body,
				'messageSid' => $messageSid,
				'numMedia' => $numMedia,
				'mediaUrls' => $mediaUrls,
				'params' => $params
			));
		}

		return '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
	}

	/**
	 * Validate a Twilio request signature.
	 *
	 * Twilio signs each request by computing HMAC-SHA1 of the request URL
	 * concatenated with the sorted POST parameter key/value pairs, using
	 * the auth token as the key, then base64-encoding the result.
	 *
	 * @param string $url The full request URL (including query string if any)
	 * @param array $params The POST parameters
	 * @param string $signature The value of the X-Twilio-Signature header
	 * @param string $authToken The Twilio auth token (HMAC key)
	 * @return bool Whether the signature is valid
	 */
	static function validateSignature($url, $params, $signature, $authToken)
	{
		// Sort parameters by key
		ksort($params);

		// Build the data string: URL followed by sorted key/value pairs
		$data = $url;
		foreach ($params as $key => $value) {
			$data .= $key . $value;
		}

		// Compute HMAC-SHA1 and base64-encode
		$expected = base64_encode(hash_hmac('sha1', $data, $authToken, true));

		// Constant-time comparison to prevent timing attacks
		return hash_equals($expected, $signature);
	}

	/**
	 * Normalize a phone number to E.164 format.
	 *
	 * Strips non-digit characters and applies basic country code logic.
	 * For US numbers: a 10-digit number gets +1 prepended.
	 * Numbers already starting with + are kept as-is after digit extraction.
	 *
	 * @param string $phone The input phone number
	 * @param string $defaultCountry Default country code (default: 'US')
	 * @return string The phone number in E.164 format
	 * @throws Exception if the number cannot be normalized
	 */
	static function formatE164($phone, $defaultCountry = 'US')
	{
		$original = $phone;
		$hasPlus = (strpos(trim($phone), '+') === 0);

		// Strip everything except digits
		$digits = preg_replace('/[^0-9]/', '', $phone);

		if (strlen($digits) === 0) {
			throw new Exception(
				"Q_Relay_Mobile::formatE164: Invalid phone number '$original', no digits found."
			);
		}

		if ($hasPlus) {
			// Already had a + prefix, treat digits as complete international number
			return '+' . $digits;
		}

		if ($defaultCountry === 'US') {
			if (strlen($digits) === 10) {
				// US 10-digit number, prepend +1
				return '+1' . $digits;
			}
			if (strlen($digits) === 11 && $digits[0] === '1') {
				// US number with leading 1
				return '+' . $digits;
			}
		}

		// For other cases, just prepend + and hope the caller
		// provided a full international number
		if (strlen($digits) < 7 || strlen($digits) > 15) {
			throw new Exception(
				"Q_Relay_Mobile::formatE164: Phone number '$original' "
				. "doesn't look like a valid E.164 number."
			);
		}

		return '+' . $digits;
	}

	/**
	 * Check the delivery status of a previously sent message.
	 *
	 * Queries the Twilio Messages resource to get the current status
	 * of a message by its SID.
	 *
	 * @param string $messageSid The Twilio message SID (e.g. "SM...")
	 * @param array $options Optional overrides for accountSid and authToken
	 * @return string The message status (e.g. "delivered", "sent", "failed")
	 * @throws Exception on API errors
	 */
	static function getDeliveryStatus($messageSid, $options = [])
	{
		$accountSid = isset($options['accountSid'])
			? $options['accountSid']
			: Q_Config::get('Q', 'relay', 'twilio', 'accountSid',
				Q_Config::get('Users', 'relay', 'twilio', 'accountSid', null)
			);
		$authToken = isset($options['authToken'])
			? $options['authToken']
			: Q_Config::get('Q', 'relay', 'twilio', 'authToken',
				Q_Config::get('Users', 'relay', 'twilio', 'authToken', null)
			);
		if (!$accountSid || !$authToken) {
			throw new Exception(
				"Q_Relay_Mobile::getDeliveryStatus: Twilio accountSid and authToken are required."
			);
		}

		$url = "https://api.twilio.com/2010-04-01/Accounts/"
			. urlencode($accountSid)
			. "/Messages/"
			. urlencode($messageSid)
			. ".json";

		$response = self::_twilioRequest('GET', $url, $accountSid, $authToken);

		if (!isset($response['status'])) {
			throw new Exception(
				"Q_Relay_Mobile::getDeliveryStatus: Unexpected response for SID '$messageSid'."
			);
		}

		return $response['status'];
	}

	/**
	 * Make an HTTP request to the Twilio REST API.
	 *
	 * Uses file_get_contents with a stream context for HTTP Basic auth.
	 *
	 * @param string $method 'GET' or 'POST'
	 * @param string $url The full API URL
	 * @param string $accountSid Used for Basic auth username
	 * @param string $authToken Used for Basic auth password
	 * @param array|null $postData POST parameters (URL-encoded form data)
	 * @return array Decoded JSON response
	 * @throws Exception on HTTP or JSON errors
	 */
	private static function _twilioRequest($method, $url, $accountSid, $authToken, $postData = null)
	{
		$auth = base64_encode($accountSid . ':' . $authToken);

		$headers = array(
			"Authorization: Basic $auth",
			"Accept: application/json"
		);

		$contextOptions = array(
			'http' => array(
				'method' => $method,
				'header' => implode("\r\n", $headers),
				'ignore_errors' => true,
				'timeout' => 30
			)
		);

		if ($method === 'POST' && $postData !== null) {
			$contextOptions['http']['content'] = http_build_query($postData);
			$contextOptions['http']['header'] .= "\r\nContent-Type: application/x-www-form-urlencoded";
		}

		$context = stream_context_create($contextOptions);
		$responseBody = file_get_contents($url, false, $context);

		if ($responseBody === false) {
			throw new Exception(
				"Q_Relay_Mobile: HTTP request to Twilio failed. URL: $url"
			);
		}

		$decoded = json_decode($responseBody, true);
		if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception(
				"Q_Relay_Mobile: Failed to decode Twilio JSON response: "
				. json_last_error_msg()
			);
		}

		return $decoded;
	}
}
