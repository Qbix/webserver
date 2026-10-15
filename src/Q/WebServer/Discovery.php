<?php

/**
 * Discovery documents for AI agents and API clients.
 *
 * Every document here is generated per request from what this server and the host being asked
 * about actually have, so it stays correct without anyone editing it:
 *
 *   /.well-known/ai-catalog.json   domain-level catalog (AI Catalog); links to the MCP server card
 *                                  and the OpenAPI description
 *   /.well-known/api-catalog       RFC 9727 API catalog (a Linkset) linking to the OpenAPI description,
 *                                  the human documentation and the status endpoint
 *   /mcp/server-card               MCP Server Card (SEP-2127), the recommended location next to the
 *                                  Streamable HTTP endpoint; also at /.well-known/mcp/server-card
 *   /llms.txt, /llm.txt            plain text for an agent's context window (also under /.well-known/);
 *                                  an llms.txt the app ships itself takes precedence
 *
 * The older /.well-known/openapi.json and /.well-known/mcp.json keep working.
 *
 * @class Q_WebServer_Discovery
 */
class Q_WebServer_Discovery
{
	/** The Server Card schema this server's card declares. */
	const SERVER_CARD_SCHEMA = 'https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json';

	const TYPE_CATALOG = 'application/ai-catalog+json';
	const TYPE_CARD = 'application/mcp-server-card+json';
	const TYPE_LINKSET = 'application/linkset+json; profile="https://www.rfc-editor.org/info/rfc9727"';
	const TYPE_OPENAPI = 'application/openapi+json';

	/** True when this build has an MCP endpoint at /mcp. */
	static function hasMcp()
	{
		return is_file(__DIR__ . '/MCP.php');
	}

	/** The host being asked about, without the port. */
	static function hostName($parsed)
	{
		$h = $parsed['headers']['host'] ?? 'localhost';
		return preg_replace('/:\d+$/', '', $h);
	}

	/** Scheme and host (with port) for absolute URLs in the documents. */
	static function origin($parsed)
	{
		$h = $parsed['headers']['host'] ?? 'localhost';
		$name = self::hostName($parsed);
		$https = !empty($parsed['https'])
			|| strtolower($parsed['headers']['x-forwarded-proto'] ?? '') === 'https'
			|| (strpos($name, '.') !== false && !preg_match('/^\d+\.\d+\.\d+\.\d+$/', $name));
		return ($https ? 'https://' : 'http://') . $h;
	}

	/** The name this host goes by in the documents: its app, if it has one. */
	static function title($parsed)
	{
		$host = self::hostName($parsed);
		$app = Q_Config::get('Q', 'app', $host, null);
		return (is_string($app) && $app !== '' ? $app : 'Qbix Server') . ' on ' . $host;
	}

	/** A reverse-DNS server name, as the Server Card expects ("net.example/server"). */
	static function cardName($parsed)
	{
		$host = strtolower(self::hostName($parsed));
		if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host)
			|| preg_match('/^\d+\.\d+\.\d+\.\d+$/', $host)) {
			$host = 'localhost.local';
		}
		return implode('.', array_reverse(explode('.', $host))) . '/server';
	}

	private static function json($status, $doc, $type, $extra = array())
	{
		return array('status' => $status,
			'body' => json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			'headers' => array_merge(array(
				'Content-Type' => $type,
				'Access-Control-Allow-Origin' => '*',
				'Cache-Control' => 'public, max-age=300',
			), $extra));
	}

	/** GET /.well-known/ai-catalog.json */
	static function aiCatalog($parsed)
	{
		$o = self::origin($parsed);
		$host = self::hostName($parsed);
		$entries = array();
		if (self::hasMcp()) {
			$entries[] = array(
				'identifier' => 'urn:air:' . $host . ':mcp:server',
				'displayName' => self::title($parsed),
				'type' => self::TYPE_CARD,
				'url' => $o . '/mcp/server-card',
				'tags' => array('mcp'),
			);
		}
		$entries[] = array(
			'identifier' => 'urn:air:' . $host . ':api:openapi',
			'displayName' => self::title($parsed) . ' (HTTP API)',
			'type' => self::TYPE_OPENAPI,
			'url' => $o . '/.well-known/openapi.json',
			'tags' => array('openapi', 'http'),
		);
		$doc = array(
			'specVersion' => '1.0',
			'host' => array(
				'displayName' => self::title($parsed),
				'identifier' => 'did:web:' . $host,
				'documentationUrl' => $o . '/llms.txt',
			),
			'entries' => $entries,
		);
		return self::json(200, $doc, self::TYPE_CATALOG);
	}

	/** GET /.well-known/api-catalog (RFC 9727) */
	static function apiCatalog($parsed)
	{
		$o = self::origin($parsed);
		$item = array(
			'anchor' => $o . '/',
			'service-desc' => array(array('href' => $o . '/.well-known/openapi.json', 'type' => self::TYPE_OPENAPI)),
			'service-doc' => array(
				array('href' => $o . '/llms.txt', 'type' => 'text/plain'),
			),
			'status' => array(array('href' => $o . '/Q/health', 'type' => 'application/json')),
		);
		if (self::hasMcp()) {
			$item['service-meta'] = array(array('href' => $o . '/mcp/server-card', 'type' => self::TYPE_CARD));
		}
		return self::json(200, array('linkset' => array($item)), self::TYPE_LINKSET);
	}

	/** GET /mcp/server-card (also /.well-known/mcp/server-card) */
	static function serverCard($parsed)
	{
		if (!self::hasMcp()) {
			return array('status' => 404, 'body' => '{"error":"not found"}',
				'headers' => array('Content-Type' => 'application/json'));
		}
		require_once __DIR__ . '/MCP.php';
		$o = self::origin($parsed);
		$card = array(
			'$schema' => self::SERVER_CARD_SCHEMA,
			'name' => self::cardName($parsed),
			'title' => self::title($parsed),
			'description' => 'Read and edit this site, manage its branches and, with an administrator key, its configuration.',
			'version' => defined('QBIX_SERVER_VERSION') ? QBIX_SERVER_VERSION : '1.0.0',
			'websiteUrl' => $o,
			'remotes' => array(array(
				'type' => 'streamable-http',
				'url' => $o . '/mcp',
				'supportedProtocolVersions' => array(Q_WebServer_MCP::PROTOCOL_VERSION),
				'headers' => array(array(
					'name' => 'Authorization',
					'description' => 'Bearer <API key>. Create one in the Control Panel under API Keys: ' . $o . '/Q/panel#security',
					'isRequired' => true,
					'isSecret' => true,
				)),
			)),
			'_meta' => array('com.qbix/server' => array(
				'guide' => $o . '/llms.txt',
				'openapi' => $o . '/.well-known/openapi.json',
				'keys' => $o . '/Q/panel#security',
			)),
		);
		return self::json(200, $card, self::TYPE_CARD);
	}

	/** If this host is a branch, lines saying so and pointing to the main site. */
	static function branchLines($parsed)
	{
		$b = $parsed['_branchRecord'] ?? null;
		if (!$b || empty($b['name'])) return array();
		$main = $b['appHost'] ?? '';
		$l = array('> This address is a branch named "' . $b['name'] . '"' . ($main ? ' of https://' . $main . '/' : '')
			. ', an isolated copy for trying changes. Work here, not on the live site, and request a merge when it is ready.', '');
		return $l;
	}

	/**
	 * The discovery section for llms.txt, as lines, in the llmstxt.org shape: a heading, then a
	 * list of links each with a note, so an agent can follow the JSON files to the rest.
	 */
	static function llmsLines($parsed)
	{
		$o = self::origin($parsed);
		$l = array('## Discovery', '');
		$l[] = '- [AI catalog](' . $o . '/.well-known/ai-catalog.json): everything this host offers to agents; start here';
		$l[] = '- [API catalog](' . $o . '/.well-known/api-catalog): RFC 9727 links to the API description, guide and status';
		if (self::hasMcp()) {
			$l[] = '- [MCP server card](' . $o . '/mcp/server-card): how to connect to the MCP endpoint';
		}
		$l[] = '- [OpenAPI description](' . $o . '/.well-known/openapi.json): every HTTP endpoint, generated from the server and the app\'s handlers';
		$l[] = '';
		$l[] = '## Next steps';
		$l[] = '';
		if (self::hasMcp()) {
			$l[] = '- MCP endpoint: ' . $o . '/mcp (Streamable HTTP, `Authorization: Bearer <API key>`)';
		}
		$l[] = '- [Create an API key](' . $o . '/Q/panel#security): the site owner does this in the Control Panel, then gives the key to you';
		$l[] = '- [Health](' . $o . '/Q/health): is the server up';
		$l[] = '';
		return $l;
	}

	/** A short llms.txt, for builds without the MCP guide. */
	static function llmsTxt($parsed)
	{
		$lines = array('# ' . self::title($parsed), '',
			'> This site is served by Qbix Server. Its HTTP API is described by OpenAPI, generated from the server\'s endpoints and the app\'s handlers.', '');
		$lines = array_merge($lines, self::branchLines($parsed), self::llmsLines($parsed));
		return implode("\n", $lines);
	}

	/** True when the app has its own file at this path, which then wins over the generated one. */
	private static function appHasFile($path)
	{
		$root = class_exists('Q_WebServer', false) ? Q_WebServer::$rootDir : null;
		return $root && is_file(rtrim($root, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/'));
	}

	/**
	 * Route a request. Returns a response array, or null when the path is not ours.
	 * @param {string} $path
	 * @param {array} $parsed
	 */
	static function route($path, $parsed)
	{
		// Agents guess at several names for the llms.txt convention; all of them get the generated file,
		// unless the app ships its own llms.txt, which is then served as the static file it is.
		if (in_array($path, array('/llms.txt', '/llm.txt', '/.well-known/llms.txt', '/.well-known/llm.txt'), true)) {
			if (self::appHasFile($path)) return null;
			return array('status' => 200, 'body' => Q_WebServer::serveLlmsTxt($parsed),
				'headers' => array('Content-Type' => 'text/plain; charset=utf-8',
					'Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'public, max-age=300'));
		}
		switch ($path) {
			case '/.well-known/ai-catalog.json':
			case '/.well-known/ai-catalog':
				return self::aiCatalog($parsed);
			case '/.well-known/api-catalog':
				return self::apiCatalog($parsed);
			case '/mcp/server-card':
			case '/.well-known/mcp/server-card':
			case '/.well-known/mcp/server-card.json':
				return self::serverCard($parsed);
		}
		return null;
	}
}
