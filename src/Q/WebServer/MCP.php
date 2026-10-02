<?php

/**
 * MCP Streamable HTTP Transport handler for the Qbix WebServer.
 *
 * Implements the Model Context Protocol (MCP) over streamable HTTP transport,
 * turning any Qbix-powered site into a live MCP server that ChatGPT plugins,
 * Claude, and other MCP clients can connect to.
 *
 * The transport accepts POST requests at /mcp with JSON-RPC 2.0 payloads
 * and dispatches tool calls to existing Qbix handlers via Q::event().
 *
 * @class Q_WebServer_MCP
 * @static
 */
class Q_WebServer_MCP
{
	const PROTOCOL_VERSION = '2025-03-26';

	static function handle($parsed)
	{
		$method = strtoupper($parsed['method'] ?? 'GET');

		if ($method === 'OPTIONS') {
			return self::corsResponse(204, '');
		}

		if ($method !== 'POST') {
			return self::corsResponse(405, json_encode(array(
				'jsonrpc' => '2.0',
				'error' => array('code' => -32600, 'message' => 'Only POST is accepted'),
				'id' => null
			)));
		}

		$body = $parsed['body'] ?? '';
		$decoded = json_decode($body, true);
		if (!$decoded) {
			return self::corsResponse(400, json_encode(array(
				'jsonrpc' => '2.0',
				'error' => array('code' => -32700, 'message' => 'Parse error'),
				'id' => null
			)));
		}

		// Batch or single request
		if (isset($decoded[0])) {
			$responses = array();
			foreach ($decoded as $req) {
				$resp = self::dispatch($req, $parsed);
				if ($resp !== null) {
					$responses[] = $resp;
				}
			}
			return self::corsResponse(200, json_encode($responses));
		}

		$response = self::dispatch($decoded, $parsed);
		if ($response === null) {
			return self::corsResponse(204, '');
		}
		return self::corsResponse(200, json_encode($response));
	}

	static function dispatch($request, $parsed)
	{
		$id = $request['id'] ?? null;
		$method = $request['method'] ?? '';
		$params = $request['params'] ?? array();
		$isNotification = !array_key_exists('id', $request);

		try {
			switch ($method) {
				case 'initialize':
					$result = self::handleInitialize($params, $parsed);
					break;
				case 'notifications/initialized':
					return null;
				case 'ping':
					$result = new \stdClass();
					break;
				case 'tools/list':
					$result = self::handleToolsList($params, $parsed);
					break;
				case 'tools/call':
					$result = self::handleToolsCall($params, $parsed);
					break;
				case 'resources/list':
					$result = array('resources' => array());
					break;
				case 'prompts/list':
					$result = array('prompts' => array());
					break;
				default:
					if ($isNotification) return null;
					return array(
						'jsonrpc' => '2.0',
						'error' => array(
							'code' => -32601,
							'message' => "Method not found: $method"
						),
						'id' => $id
					);
			}
		} catch (\Exception $e) {
			if ($isNotification) return null;
			return array(
				'jsonrpc' => '2.0',
				'error' => array(
					'code' => -32603,
					'message' => $e->getMessage()
				),
				'id' => $id
			);
		}

		if ($isNotification) return null;

		return array(
			'jsonrpc' => '2.0',
			'result' => $result,
			'id' => $id
		);
	}

	static function handleInitialize($params, $parsed)
	{
		$host = $parsed['headers']['host'] ?? 'localhost';
		return array(
			'protocolVersion' => self::PROTOCOL_VERSION,
			'serverInfo' => array(
				'name' => 'qbix-server',
				'version' => '1.0.0'
			),
			'capabilities' => array(
				'tools' => new \stdClass(),
			),
			'instructions' => self::getServerInstructions($host)
		);
	}

	static function handleToolsList($params, $parsed)
	{
		$tools = self::discoverTools($parsed);
		return array('tools' => $tools);
	}

	static function handleToolsCall($params, $parsed)
	{
		$toolName = $params['name'] ?? '';
		$arguments = $params['arguments'] ?? array();

		if (empty($toolName)) {
			throw new \Exception('Tool name is required');
		}

		// Built-in tools
		if ($toolName === 'health') {
			return self::toolResult(json_encode(array(
				'status' => 'ok',
				'uptime' => time()
			)));
		}

		// Branch collaboration tools
		if (in_array($toolName, array('branch_export', 'branch_push', 'branch_request_merge'))) {
			return self::handleBranchTool($toolName, $arguments, $parsed);
		}

		// Map tool name back to event name:
		// Websites_webpage_post → Websites/webpage/post
		$eventName = str_replace('_', '/', $toolName);

		// Validate the handler exists
		$handlersDir = (defined('APP_DIR') ? APP_DIR : dirname(Q_WebServer::$rootDir))
			. DIRECTORY_SEPARATOR . 'handlers';
		$handlerFile = $handlersDir . DIRECTORY_SEPARATOR
			. str_replace('/', DIRECTORY_SEPARATOR, $eventName) . '.php';

		if (!is_file($handlerFile)) {
			throw new \Exception("Unknown tool: $toolName");
		}

		$doc = Q_WebServer::parseHandlerDoc($handlerFile);
		if (!empty($doc['private'])) {
			throw new \Exception("Tool not available: $toolName");
		}

		self::authenticate($parsed);

		$result = null;
		try {
			Q::event($eventName, $arguments, false, false, $result);
		} catch (\Exception $e) {
			return self::toolResult(
				'Error: ' . $e->getMessage(),
				true
			);
		}

		if (is_array($result) || is_object($result)) {
			return self::toolResult(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		}
		if (is_string($result)) {
			return self::toolResult($result);
		}

		return self::toolResult(json_encode(array('success' => true)));
	}

	static function discoverTools($parsed)
	{
		$tools = array();

		$tools[] = array(
			'name' => 'health',
			'description' => 'Check server health and uptime',
			'inputSchema' => array(
				'type' => 'object',
				'properties' => new \stdClass()
			),
			'annotations' => array(
				'title' => 'Health Check',
				'readOnlyHint' => true,
				'destructiveHint' => false,
				'openWorldHint' => false
			)
		);

		// Branch collaboration tools
		$tools[] = array(
			'name' => 'branch_export',
			'description' => 'Export a branch or trunk as an archive with credentials scrubbed. Returns a single-use download URL, a manifest of placeholders, and framework metadata.',
			'inputSchema' => array(
				'type' => 'object',
				'properties' => array(
					'appHost' => array(
						'type' => 'string',
						'description' => 'The app hostname (e.g. myapp.example.com)',
					),
					'branchName' => array(
						'type' => 'string',
						'description' => 'Branch name to export (omit for trunk)',
					),
					'format' => array(
						'type' => 'string',
						'enum' => array('tar.gz', 'zip'),
						'description' => 'Archive format (default: tar.gz)',
					),
				),
				'required' => array('appHost'),
			),
			'annotations' => array(
				'title' => 'Branch Export',
				'readOnlyHint' => true,
				'destructiveHint' => false,
				'openWorldHint' => false,
			),
		);

		$tools[] = array(
			'name' => 'branch_push',
			'description' => 'Push file changes to a branch. Each file is validated against the caller\'s file-permission tier and config-key permissions before writing.',
			'inputSchema' => array(
				'type' => 'object',
				'properties' => array(
					'appHost' => array(
						'type' => 'string',
						'description' => 'The app hostname',
					),
					'branchName' => array(
						'type' => 'string',
						'description' => 'Branch name to push to',
					),
					'files' => array(
						'type' => 'array',
						'description' => 'Array of files to push',
						'items' => array(
							'type' => 'object',
							'properties' => array(
								'path' => array(
									'type' => 'string',
									'description' => 'Relative file path within the branch',
								),
								'content' => array(
									'type' => 'string',
									'description' => 'File content (base64-encoded by default)',
								),
								'encoding' => array(
									'type' => 'string',
									'enum' => array('base64', 'utf8'),
									'description' => 'Content encoding (default: base64)',
								),
							),
							'required' => array('path', 'content'),
						),
					),
				),
				'required' => array('appHost', 'branchName', 'files'),
			),
			'annotations' => array(
				'title' => 'Branch Push',
				'readOnlyHint' => false,
				'destructiveHint' => false,
				'openWorldHint' => false,
			),
		);

		$tools[] = array(
			'name' => 'branch_request_merge',
			'description' => 'Request that a branch be merged back to trunk. Creates a merge request record and notifies app admins.',
			'inputSchema' => array(
				'type' => 'object',
				'properties' => array(
					'appHost' => array(
						'type' => 'string',
						'description' => 'The app hostname',
					),
					'branchName' => array(
						'type' => 'string',
						'description' => 'Branch name to merge',
					),
					'title' => array(
						'type' => 'string',
						'description' => 'Merge request title',
					),
					'description' => array(
						'type' => 'string',
						'description' => 'Merge request description',
					),
				),
				'required' => array('appHost', 'branchName'),
			),
			'annotations' => array(
				'title' => 'Branch Merge Request',
				'readOnlyHint' => false,
				'destructiveHint' => false,
				'openWorldHint' => false,
			),
		);

		$handlersDir = (defined('APP_DIR') ? APP_DIR : dirname(Q_WebServer::$rootDir))
			. DIRECTORY_SEPARATOR . 'handlers';
		if (!is_dir($handlersDir)) {
			return $tools;
		}

		$hiddenPatterns = Q_Config::get('Q', 'api', 'discover', 'hidden', array());
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($handlersDir, \RecursiveDirectoryIterator::SKIP_DOTS)
		);

		foreach ($it as $file) {
			if ($file->getExtension() !== 'php') continue;

			$rel = str_replace(
				DIRECTORY_SEPARATOR, '/',
				substr($file->getPathname(), strlen($handlersDir) + 1)
			);
			$eventName = str_replace('.php', '', $rel);
			$doc = Q_WebServer::parseHandlerDoc($file->getPathname());

			if (!empty($doc['private'])) continue;
			if (Q_WebServer::isHandlerHidden($eventName, $hiddenPatterns)) continue;

			// Only API handlers (post, put, delete, get), not UI tools
			$basename = basename($rel, '.php');
			$httpMethods = array('post', 'put', 'delete', 'get');
			if (!in_array($basename, $httpMethods)
				&& !in_array($basename, array('validate', 'response'))) {
				$parts = explode('/', $eventName);
				$last = end($parts);
				if (!in_array($last, $httpMethods)) continue;
			}

			$properties = array();
			$required = array();
			foreach ($doc['params'] as $p) {
				$prop = array('description' => $p['description']);
				if ($p['type']) {
					$prop['type'] = Q_WebServer::phpTypeToJsonSchema($p['type']);
				}
				$properties[$p['name']] = $prop;
				if (!$p['optional']) {
					$required[] = $p['name'];
				}
			}

			$schema = array('type' => 'object');
			if ($properties) $schema['properties'] = $properties;
			if ($required) $schema['required'] = $required;

			$description = $doc['summary'] ?: "Handler: $eventName";
			if ($doc['description']) {
				$description .= ' — ' . $doc['description'];
			}

			$annotations = self::deriveAnnotations($eventName, $basename);

			$tool = array(
				'name' => str_replace('/', '_', $eventName),
				'description' => $description,
				'inputSchema' => $schema,
			);
			if ($annotations) {
				$tool['annotations'] = $annotations;
			}

			$tools[] = $tool;
		}

		return $tools;
	}

	static function deriveAnnotations($eventName, $httpMethod)
	{
		$parts = explode('/', $eventName);
		array_pop($parts);
		$title = implode(' ', array_map('ucfirst', $parts));

		switch (strtolower($httpMethod)) {
			case 'get':
				return array(
					'title' => $title,
					'readOnlyHint' => true,
					'destructiveHint' => false,
					'openWorldHint' => false
				);
			case 'post':
			case 'put':
				return array(
					'title' => $title,
					'readOnlyHint' => false,
					'destructiveHint' => false,
					'openWorldHint' => false
				);
			case 'delete':
				return array(
					'title' => $title,
					'readOnlyHint' => false,
					'destructiveHint' => true,
					'openWorldHint' => false
				);
			default:
				return array(
					'title' => $title,
					'readOnlyHint' => false,
					'destructiveHint' => false,
					'openWorldHint' => false
				);
		}
	}

	static function authenticate($parsed)
	{
		$authHeader = $parsed['headers']['authorization'] ?? '';
		$qToken = $parsed['headers']['x-q-token'] ?? '';

		if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
			$token = $m[1];
			$apiKeys = Q_Config::get('Q', 'mcp', 'apiKeys', array());
			if ($apiKeys && !in_array($token, $apiKeys)) {
				throw new \Exception('Invalid API key');
			}
			return;
		}

		if ($qToken) {
			return;
		}

		$allowAnonymous = Q_Config::get('Q', 'mcp', 'allowAnonymous', false);
		if ($allowAnonymous) {
			return;
		}

		throw new \Exception(
			'Authentication required. Provide a Bearer token in the Authorization header.'
		);
	}

	static function getServerInstructions($host)
	{
		$appName = Q_Config::get('Q', 'app', $host);
		return "This is a Qbix-powered server at $host"
			. ($appName ? " running the $appName application" : '')
			. ". Use the available tools to interact with the website's content. "
			. "The site uses a stream-based content model where pages contain sections, "
			. "and sections contain blocks. You can create, edit, and manage web content "
			. "through the provided tools.";
	}

	static function toolResult($text, $isError = false)
	{
		$result = array(
			'content' => array(
				array('type' => 'text', 'text' => $text)
			)
		);
		if ($isError) {
			$result['isError'] = true;
		}
		return $result;
	}

	/**
	 * Handle branch collaboration tool calls.
	 * Authenticates the caller against the branch and delegates to
	 * Q_WebServer_Branch API methods.
	 */
	static function handleBranchTool($toolName, $arguments, $parsed)
	{
		$appHost = $arguments['appHost'] ?? '';
		$branchName = $arguments['branchName'] ?? null;

		if (!$appHost) {
			return self::toolResult('Error: appHost is required', true);
		}

		// Resolve the branch record for authentication
		$branchRecord = null;
		if ($branchName) {
			$branchRecord = Q_WebServer_Branch::get($appHost, $branchName);
			if (!$branchRecord) {
				return self::toolResult("Error: Branch not found: $branchName", true);
			}
			$branchRecord['appHost'] = $appHost;
		} else {
			// Trunk access — build a minimal branch record for auth
			$branchRecord = array(
				'root' => Q_Config::get('Q', 'webserver', 'hosts', $appHost, 'root', ''),
				'appHost' => $appHost,
				'access' => Q_Config::get('Q', 'webserver', 'hosts', $appHost, 'access', array()),
				'tokens' => Q_Config::get('Q', 'webserver', 'hosts', $appHost, 'tokens', array()),
			);
		}

		// Authenticate
		$headers = $parsed['headers'] ?? array();
		$cookies = $parsed['cookies'] ?? array();
		$authResult = Q_WebServer_Branch::authenticateForBranch($branchRecord, $headers, $cookies);
		if (!$authResult) {
			return self::toolResult('Error: Authentication failed or access denied', true);
		}

		try {
			switch ($toolName) {
				case 'branch_export':
					$result = Q_WebServer_Branch::apiExport($arguments, $authResult);
					break;
				case 'branch_push':
					$result = Q_WebServer_Branch::apiPush($arguments, $authResult);
					break;
				case 'branch_request_merge':
					$result = Q_WebServer_Branch::apiRequestMerge($arguments, $authResult);
					break;
				default:
					return self::toolResult("Error: Unknown branch tool: $toolName", true);
			}
		} catch (\Exception $e) {
			return self::toolResult('Error: ' . $e->getMessage(), true);
		}

		if (isset($result['error'])) {
			return self::toolResult('Error: ' . $result['error'], true);
		}

		return self::toolResult(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}

	static function corsResponse($status, $body)
	{
		return array(
			'status' => $status,
			'body' => $body,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Access-Control-Allow-Origin' => '*',
				'Access-Control-Allow-Methods' => 'POST, OPTIONS',
				'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Q-Token',
				'Access-Control-Max-Age' => '86400'
			)
		);
	}
}
