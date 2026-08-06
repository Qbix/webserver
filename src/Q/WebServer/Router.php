<?php

/**
 * Route resolution that works the same in standalone and --app mode.
 *
 * The webserver ships its own Q_Uri with a small route matcher (fromPath()).
 * The PLATFORM also defines Q_Uri, and in --app mode the Platform's class wins —
 * and it has no fromPath(), so calling it there is a fatal. Both, however, read
 * the SAME config key (Q/routes) and agree on the same route grammar
 * ("Module/action/:param" => array('module'=>..., 'action'=>...)), so the two
 * only differ in the entry point, not the meaning.
 *
 * This adapter picks whichever entry point actually exists:
 *
 *   1. Q_Uri::fromPath()  — our own matcher, used standalone.
 *   2. --app mode         — returns null on purpose and lets the Platform's
 *                           front controller route, because the Platform owns
 *                           the whole dispatch pipeline, not just matching.
 *
 * Note the Platform's Q_Uri::from() does NOT route a bare path: anything
 * Q_Valid::url() rejects goes to fromString(), which parses "Module/action"
 * and never reads Q/routes. Only fromUrl() consults the route table. That is
 * a second reason not to call it here with a path.
 *
 * tests/routing-parity.php checks the two matchers agree on the same table.
 *
 * @class Q_WebServer_Router
 */
class Q_WebServer_Router
{
	/**
	 * Resolve a URL path to a routed URI, or null when nothing matches.
	 *
	 * @method resolve
	 * @static
	 * @param {string} $path e.g. "/AI/webhook/slack/ingest"
	 * @return {Q_Uri|null}
	 */
	static function resolve($path)
	{
		if (!self::enabled()) {
			return null;
		}
		// Standalone: our own Q_Uri, which caches compiled patterns.
		if (method_exists('Q_Uri', 'fromPath')) {
			$uri = Q_Uri::fromPath($path);
			return self::usable($uri) ? $uri : null;
		}
		// --app mode: DO NOT route here.
		//
		// If our Q_Uri is not the loaded one, the Platform is present — and the
		// Platform owns routing, exactly as it owns class loading. Its front
		// controller (index.php -> Q_Dispatcher) reads the same Q/routes table and
		// then runs the app's handlers, event hooks, session and access checks.
		// Our handler-based dispatch is a STANDALONE mechanism that knows none of
		// that, so intercepting here bypassed the Platform's pipeline: '/' resolved
		// to MyApp/welcome and came back 403 while '/index.php' served fine.
		//
		// Returning null makes the request fall through to index.php, which is the
		// correct entry point whenever a Platform is loaded.
		return null;
	}

	/**
	 * Whether routing should be attempted at all: a Q/routes table must exist
	 * and some Q_Uri must be loadable. Same condition in both modes.
	 *
	 * @method enabled
	 * @static
	 * @return {boolean}
	 */
	static function enabled()
	{
		static $enabled = null;
		if ($enabled === null) {
			$enabled = class_exists('Q_Config', true)
				&& Q_Config::get('Q', 'routes', null) !== null
				&& class_exists('Q_Uri', true);
		}
		return $enabled;
	}

	/**
	 * A routed URI is only useful to the webserver if it names a module and an
	 * action; anything else falls through to the front controller.
	 *
	 * @method usable
	 * @static
	 * @param {mixed} $uri
	 * @return {boolean}
	 */
	static function usable($uri)
	{
		if (empty($uri)) {
			return false;
		}
		$module = is_object($uri) ? (isset($uri->module) ? $uri->module : null)
			: (isset($uri['module']) ? $uri['module'] : null);
		$action = is_object($uri) ? (isset($uri->action) ? $uri->action : null)
			: (isset($uri['action']) ? $uri['action'] : null);
		return !empty($module) && !empty($action);
	}
}
