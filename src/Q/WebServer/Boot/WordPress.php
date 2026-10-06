<?php
/**
 * @module Q
 */

/**
 * WordPress: wp-load.php (core, plugins, theme, 'init', 'wp_loaded') runs
 * once in the boot master. Each worker runs wp() and the template loader.
 *
 * Plugins act on the request while WordPress loads: they read cookies to
 * find the user, and many handle form posts or query arguments on 'init'.
 * The master boots as an anonymous GET to /, so a booted worker serves only
 * requests that look like that: GET or HEAD, no login, comment or cart
 * cookies, and query arguments that WordPress core parses itself in wp().
 * Everything else — wp-admin, wp-login.php, POSTs, logged-in visitors,
 * plugin-specific query arguments — goes to the ordinary pool and loads
 * WordPress from scratch, as before. Anonymous page views are most traffic.
 *
 * Options, widgets and menus loaded during boot can change in the admin.
 * The master boots again as soon as an admin, REST or XML-RPC write
 * finishes, immediately when plugins, themes or wp-config.php change on
 * disk, and every 30 seconds regardless (Q.webserver.boot.ttl) to catch
 * changes made outside the server, such as by WP-CLI or cron.
 * Multisite installs are not booted yet.
 *
 * @class Q_WebServer_Boot_WordPress
 */
class Q_WebServer_Boot_WordPress extends Q_WebServer_Boot_Adapter
{
	/** Query arguments WordPress core handles in wp(), after 'init'. */
	static $queryArgs = array(
		'p', 'page_id', 'paged', 'page', 'cpage', 's', 'cat', 'tag', 'm', 'w',
		'author', 'author_name', 'name', 'pagename', 'year', 'monthnum', 'day',
		'hour', 'minute', 'second', 'feed', 'post_type', 'category_name',
		'attachment', 'attachment_id', 'subpost', 'subpost_id', 'orderby',
		'order', 'taxonomy', 'term', 'rest_route', 'sitemap', 'sitemap-subtype',
		'sitemap-stylesheet', 'embed', 'withcomments', 'withoutcomments',
		'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
		'fbclid', 'gclid', 'ver',
	);

	/** Cookies that mean the page depends on who is asking. */
	static $cookiePrefixes = array(
		'wordpress_logged_in', 'wordpress_sec', 'wordpress_', 'wp-postpass',
		'comment_author', 'woocommerce', 'wp_woocommerce', 'wp-settings',
		'edd_', 'pmpro', 'wpml_', 'wp_lang',
	);

	function name()
	{
		return 'WordPress';
	}

	function detect($root)
	{
		foreach (array('wp-load.php', 'wp-settings.php', 'wp-blog-header.php', 'index.php') as $f) {
			if (!is_file("$root/$f")) return false;
		}
		$config = is_file("$root/wp-config.php") ? "$root/wp-config.php"
			: (is_file(dirname($root) . '/wp-config.php') ? dirname($root) . '/wp-config.php' : null);
		if (!$config) return false;
		$src = (string) @file_get_contents($config);
		if (preg_match("/define\\(\\s*['\"](MULTISITE|WP_ALLOW_MULTISITE)['\"]\\s*,\\s*true/i", $src)) {
			return false;
		}
		$this->root = $root;
		$this->project = dirname($config);
		$this->front = "$root/index.php";
		return true;
	}

	function boot($root)
	{
		$this->fakeBootRequest();
		if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', true);
	}

	/** wp-load.php runs at global scope, as it does from index.php. */
	function globalIncludes()
	{
		return array($this->root . '/wp-load.php');
	}

	function afterBoot()
	{
		if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()
			&& function_exists('wp_cache_close')
		) {
			wp_cache_close();
		}
		if (isset($GLOBALS['wpdb']) && method_exists($GLOBALS['wpdb'], 'close')) {
			$GLOBALS['wpdb']->close();
		}
	}

	function afterFork()
	{
		if (isset($GLOBALS['wpdb']) && method_exists($GLOBALS['wpdb'], 'db_connect')) {
			$GLOBALS['wpdb']->db_connect(false);
		}
		if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()
			&& function_exists('wp_cache_init')
		) {
			wp_cache_init();
		}
	}

	function handles($scriptPath, $parsed)
	{
		if (!$this->samePath($scriptPath, $this->front)) return false;
		if (!$this->isSafeMethod($parsed)) return false;
		if ($this->hasCookie($parsed, self::$cookiePrefixes)) return false;
		if (!empty($parsed['headers']['authorization'])) return false;
		if (($parsed['query'] ?? '') !== '') {
			parse_str($parsed['query'], $q);
			foreach (array_keys($q) as $k) {
				if (!in_array($k, self::$queryArgs, true)) return false;
			}
		}
		return true;
	}

	/**
	 * Writes that change what the booted master loaded: admin screens, admin
	 * AJAX (except the heartbeat, which saves nothing), REST and XML-RPC
	 * writes. Comments and form posts on the front end change only content
	 * that wp() queries per request, so they do not trigger a re-boot.
	 */
	function invalidatedBy($scriptPath, $parsed)
	{
		if ($this->isSafeMethod($parsed)) return false;
		$rel = str_replace('\\', '/', substr((string) realpath($scriptPath), strlen((string) realpath($this->root))));
		$path = $parsed['path'] ?? '';
		if (strpos($rel, '/wp-admin/admin-ajax.php') === 0) {
			parse_str((string) ($parsed['body'] ?? ''), $body);
			parse_str((string) ($parsed['query'] ?? ''), $q);
			$action = $body['action'] ?? $q['action'] ?? '';
			return $action !== 'heartbeat';
		}
		if (strpos($rel, '/wp-admin/') === 0 || $rel === '/xmlrpc.php') return true;
		if (strpos($path, '/wp-json/') !== false || strpos((string) ($parsed['query'] ?? ''), 'rest_route=') !== false) {
			return true;
		}
		return false;
	}

	/** wp() and the template loader run at global scope, like wp-blog-header.php. */
	function handleFile()
	{
		return __DIR__ . '/wordpress-handle.php';
	}

	function handle($req)
	{
		require $this->handleFile();
	}

	function watchPaths($root)
	{
		$c = $this->project;
		$content = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : "$root/wp-content";
		$paths = array("$c/wp-config.php", "$root/wp-config.php", $content,
			"$content/plugins", "$content/mu-plugins", "$content/themes",
			"$content/object-cache.php", "$content/advanced-cache.php", "$root/.htaccess");
		if (function_exists('get_stylesheet_directory')) {
			$paths[] = get_stylesheet_directory();
			$paths[] = get_stylesheet_directory() . '/functions.php';
		}
		return $this->existing($paths);
	}

	function defaultTtl()
	{
		return 30;
	}
}
