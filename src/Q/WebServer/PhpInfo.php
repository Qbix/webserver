<?php

/**
 * Renders phpinfo() output as the HTML page people expect.
 *
 * phpinfo() only builds an HTML page under mod_php and fpm. Every
 * CLI-family SAPI — phpmicro included — emits plain text instead, and the
 * choice is a SAPI-level flag no ini setting reaches. This class parses
 * that text back into the same markup phpinfo() produces elsewhere: the
 * .e/.v/.h cell classes, the per-module tables, the anchored headings and
 * a stylesheet matching PHP's own, so the page looks like the one served
 * by an fpm or mod_php host.
 *
 * The text format is regular enough to parse reliably:
 *
 *   phpinfo()                                  first line, discarded
 *   PHP Version => 8.3.33                      becomes the heading
 *
 *   System => Linux ...                        two-column rows
 *   Additional .ini files parsed => a.ini,
 *   b.ini,                                     continuation of the value above
 *
 *   Configuration                              heading: alone between blanks
 *
 *   bcmath                                     module heading
 *
 *   BCMath support => enabled                  two-column rows
 *
 *   Directive => Local Value => Master Value   switches to three columns
 *   bcmath.scale => 0 => 0
 *
 * A line carrying no " => " is a heading when a blank line precedes it and
 * a continuation of the previous value otherwise — which is what keeps the
 * wrapped .ini list from being mistaken for a series of headings.
 *
 * The PHP logo the real page carries is left out: it is PHP's trademark,
 * shipped inside the binary, and not ours to embed.
 *
 * @class Q_WebServer_PhpInfo
 */
class Q_WebServer_PhpInfo
{
	/**
	 * Headings phpinfo() emits that name a section rather than an extension.
	 * phpinfo() renders these without a module anchor.
	 */
	private static $sections = array(
		'Configuration', 'Additional Modules', 'Environment',
		'PHP Variables', 'PHP Credits', 'PHP License'
	);

	/**
	 * Column headers phpinfo() puts above certain sections' tables. The text
	 * output carries them as an ordinary first line — "Variable => Value",
	 * or a bare "Module Name" — so they are recognised rather than injected,
	 * which would print them twice.
	 */
	private static $sectionHeaders = array(
		'Additional Modules' => array('Module Name'),
		'Environment'        => array('Variable', 'Value'),
		'PHP Variables'      => array('Variable', 'Value')
	);

	/**
	 * Turn phpinfo() output into a full HTML document.
	 *
	 * Output that already contains markup is returned untouched, so this is
	 * safe to call whatever the SAPI produced.
	 *
	 * @method render
	 * @static
	 * @param {string} $text Raw phpinfo() output
	 * @return {string} A complete HTML document
	 */
	static function render($text)
	{
		$text = (string) $text;
		if (stripos($text, '<table') !== false or stripos($text, '<!DOCTYPE') !== false) {
			return $text;
		}

		$version = '';
		$body = self::parse($text, $version);
		$title = $version === ''
			? 'phpinfo()'
			: 'PHP ' . $version . ' - phpinfo()';

		$head = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"'
			. ' "DTD/xhtml1-transitional.dtd">' . "\n"
			. '<html xmlns="http://www.w3.org/1999/xhtml"><head>' . "\n"
			. '<style type="text/css">' . "\n" . self::css() . '</style>' . "\n"
			. '<title>' . self::esc($title) . '</title>'
			. '<meta name="ROBOTS" content="NOINDEX,NOFOLLOW,NOARCHIVE" />'
			. '<meta name="viewport" content="width=device-width,initial-scale=1" />'
			. '</head>' . "\n";

		$header = '';
		if ($version !== '') {
			$header = "<table>\n<tr class=\"h\"><td>\n"
				. '<h1 class="p">PHP Version ' . self::esc($version) . '</h1>'
				. "\n</td></tr>\n</table>\n";
		}

		return $head . '<body><div class="center">' . "\n"
			. $header . $body . "</div></body></html>";
	}

	/**
	 * Parse the text body into phpinfo's headings and tables.
	 * @method parse
	 * @static
	 * @protected
	 * @param {string} $text Raw phpinfo() output
	 * @param {string} &$version Receives the PHP version when found
	 * @return {string} HTML fragment
	 */
	protected static function parse($text, &$version)
	{
		$lines = preg_split("/\r\n|\n|\r/", $text);
		$out = '';
		$rows = array();      // buffered rows for the open table
		$cols = 2;            // arity of the open table
		$blank = true;        // did a blank line just go by?
		$gotVersion = false;
		$prose = false;       // inside the trailing licence text
		$para = array();
		$pendingHeader = null;
		$credits = false;     // inside the PHP Credits section

		$flush = function () use (&$out, &$rows, &$cols) {
			if (!$rows) return;
			$out .= "<table>\n" . implode("\n", $rows) . "\n</table>\n";
			$rows = array();
			$cols = 2;
		};

		$total = count($lines);
		for ($ln = 0; $ln < $total; $ln++) {
			$line = rtrim($lines[$ln], "\r\n");

			// Everything after the licence heading is prose, not key/value
			// rows: paragraphs of running text with blank lines between them.
			// Parsed as rows it turns into a heading per paragraph.
			if ($prose) {
				if (trim($line) === '') {
					if ($para) {
						$out .= '<p>' . implode(' ', array_map(
							array(__CLASS__, 'esc'), $para
						)) . "</p>\n";
						$para = array();
					}
				} else {
					$para[] = $line;
				}
				continue;
			}

			if (trim($line) === '') {
				if ($credits) $flush();
				$blank = true;
				continue;
			}
			if (preg_match('/^_{10,}$/', trim($line))) {
				// The text draws its section rule with underscores.
				$flush();
				$out .= "<hr />\n";
				$blank = true;
				continue;
			}

			// ── PHP Credits ──────────────────────────────
			// Blocks of "title, then the people", plus wider blocks that
			// carry their own "Contribution => Authors" header. Centred
			// titles are the two-column ones.
			if ($credits and !(in_array($line, self::$sections, true) and $blank)) {
				$trimmed = trim($line);
				if (strpos($line, ' => ') === false) {
					if (!$rows) {
						$span = ($line !== $trimmed and $trimmed !== '')
							? ' colspan="2"' : '';
						$rows[] = '<tr class="h"><th' . $span . '>'
							. self::esc($trimmed) . '</th></tr>';
					} else {
						$rows[] = '<tr><td class="e">' . self::esc($trimmed)
							. ' </td></tr>';
					}
				} else {
					$parts = explode(' => ', $line, 2);
					if (count($rows) === 1 and $parts[0] === 'Contribution') {
						$rows[] = '<tr class="h"><th>' . self::esc($parts[0])
							. '</th><th>' . self::esc($parts[1]) . '</th></tr>';
					} else {
						$rows[] = '<tr><td class="e">' . self::esc($parts[0])
							. ' </td><td class="v">' . self::esc($parts[1])
							. ' </td></tr>';
					}
				}
				$blank = false;
				continue;
			}
			if ($credits and in_array($line, self::$sections, true) and $blank) {
				$flush();
				$credits = false;
			}

			if (!$gotVersion and strpos($line, 'PHP Version => ') === 0) {
				// The first one is the page heading. Later ones (the Core
				// module repeats it) stay in their table.
				$version = trim(substr($line, strlen('PHP Version => ')));
				$gotVersion = true;
				$blank = false;
				continue;
			}
			if ($line === 'phpinfo()') {
				$blank = false;
				continue;
			}

			if (strpos($line, ' => ') === false) {
				if ($pendingHeader !== null and !$rows
				and $pendingHeader === array($line)) {
					// "Module Name" — the section's column header, which the
					// text prints on a line of its own.
					$rows[] = '<tr class="h"><th>' . self::esc($line) . '</th></tr>';
					$pendingHeader = null;
					$blank = false;
					continue;
				}
				// A heading always has a blank line after it. Free-standing
				// prose inside a module -- Phar's credits are the usual case --
				// runs straight into the next line, and taking it for a heading
				// produces one with the whole sentence as its anchor.
				$next = isset($lines[$ln + 1]) ? trim($lines[$ln + 1]) : '';
				if ($blank and $next !== ''
				and !in_array($line, self::$sections, true)) {
					$flush();
					$text = array();
					for (; $ln < $total; $ln++) {
						$l = rtrim($lines[$ln], "\r\n");
						if (trim($l) === '' or strpos($l, ' => ') !== false) {
							$ln--;
							break;
						}
						$text[] = self::esc(trim($l));
					}
					$out .= "<table>\n<tr class=\"v\"><td>\n"
						. implode("<br />", $text) . "\n</td></tr>\n</table>\n";
					$blank = false;
					continue;
				}
				if ($blank and !self::isModuleName($line)) {
					// A module may also print a standalone notice — mbstring's
					// licence line is one. phpinfo gives those a header row,
					// not a heading, and the text format does not distinguish
					// them, so tell them apart by shape.
					$flush();
					$out .= "<table>\n<tr class=\"h\"><th>" . self::esc($line)
						. "</th></tr>\n</table>\n";
					$blank = false;
					continue;
				}
				if ($blank) {
					// Heading — close the table that came before it.
					$flush();
					$out .= self::heading($line);
					if ($line === 'PHP License') {
						$prose = true;
						$out .= "<table>\n<tr class=\"v\"><td>\n";
					}
					if ($line === 'PHP Credits') $credits = true;
					$pendingHeader = isset(self::$sectionHeaders[$line])
						? self::$sectionHeaders[$line]
						: null;
				} else {
					// Continuation of the value on the previous row.
					$n = count($rows);
					if ($n) {
						// phpinfo keeps a wrapped value in one cell, the
						// lines separated by a newline, with its trailing
						// pad space left at the very end.
						$rows[$n - 1] = preg_replace(
							'/( ?<\/td><\/tr>)$/',
							"\n" . self::esc($line) . '$1',
							$rows[$n - 1],
							1
						);
					}
				}
				$blank = false;
				continue;
			}

			if ($line === 'Directive => Local Value => Master Value') {
				$flush();
				$cols = 3;
				$rows[] = '<tr class="h"><th>Directive</th>'
					. '<th>Local Value</th><th>Master Value</th></tr>';
				$pendingHeader = null;
				$blank = false;
				continue;
			}

			// Split to the arity of the open table, so a value containing
			// " => " itself stays in one cell.
			$parts = explode(' => ', $line, $cols);
			if ($pendingHeader !== null and !$rows and $parts === $pendingHeader) {
				// "Variable => Value" — the section's column header.
				$th = '';
				foreach ($pendingHeader as $h) {
					$th .= '<th>' . self::esc($h) . '</th>';
				}
				$rows[] = '<tr class="h">' . $th . '</tr>';
				$pendingHeader = null;
				$blank = false;
				continue;
			}
			$pendingHeader = null;
			// phpinfo pads the two-column tables with a trailing space and
			// leaves the directive tables tight.
			$pad = $cols === 2 ? ' ' : '';
			$cells = '<td class="e">' . self::esc($parts[0]) . $pad . '</td>';
			for ($i = 1; $i < $cols; $i++) {
				$v = isset($parts[$i]) ? $parts[$i] : '';
				$cells .= '<td class="v">' . self::value($v) . $pad . '</td>';
			}
			$rows[] = '<tr>' . $cells . '</tr>';
			$blank = false;
		}

		if ($para) {
			$out .= '<p>' . implode(' ', array_map(
				array(__CLASS__, 'esc'), $para
			)) . "</p>\n";
		}
		if ($prose) {
			$out .= "</td></tr>\n</table>\n";
		}
		$flush();
		return $out;
	}

	/**
	 * Does this bare line name an extension, or is it a notice a module
	 * printed for itself? Extension names are short identifiers -- the
	 * longest in a stock build is "Zend OPcache" -- while notices are
	 * sentences, so length and sentence punctuation separate them.
	 * @method isModuleName
	 * @static
	 * @protected
	 * @param {string} $line
	 * @return {boolean}
	 */
	protected static function isModuleName($line)
	{
		if (in_array($line, self::$sections, true)) {
			return true;
		}
		if ($line === '' or strlen($line) > 40) {
			return false;
		}
		if (strpbrk($line, '",;:()') !== false) {
			return false;
		}
		return substr($line, -1) !== '.';
	}

	/**
	 * Render one heading the way phpinfo() does: Configuration opens with a
	 * rule and an h1, the other named sections are plain h2, and anything
	 * else is an extension and gets the anchored h2 that the module index
	 * links to.
	 * @method heading
	 * @static
	 * @protected
	 * @param {string} $name
	 * @return {string}
	 */
	protected static function heading($name)
	{
		if ($name === 'Configuration' or $name === 'PHP Credits') {
			return '<h1>' . self::esc($name) . "</h1>\n";
		}
		if (in_array($name, self::$sections, true)) {
			return '<h2>' . self::esc($name) . "</h2>\n";
		}
		$anchor = 'module_' . $name;
		return '<h2><a name="' . self::esc($anchor) . '" href="#'
			. self::esc($anchor) . '">' . self::esc($name) . "</a></h2>\n";
	}

	/**
	 * A value cell. phpinfo() italicises the "no value" placeholder.
	 * @method value
	 * @static
	 * @protected
	 * @param {string} $v
	 * @return {string}
	 */
	protected static function value($v)
	{
		$v = (string) $v;
		if ($v === 'no value') {
			return '<i>no value</i>';
		}
		return self::esc($v);
	}

	/**
	 * @method esc
	 * @static
	 * @protected
	 * @param {string} $s
	 * @return {string}
	 */
	protected static function esc($s)
	{
		return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/**
	 * phpinfo()'s stylesheet, so the page matches what an fpm or mod_php
	 * host serves — same cell colours, same table width, same dark mode.
	 * The table width is relaxed to a max so the page still works on a
	 * phone, which the original's fixed 934px does not.
	 * @method css
	 * @static
	 * @protected
	 * @return {string}
	 */
	protected static function css()
	{
		return "body {background-color: #fff; color: #222; font-family: sans-serif;}\n"
			. "pre {margin: 0; font-family: monospace;}\n"
			. "a:link {color: #009; text-decoration: none; background-color: #fff;}\n"
			. "a:hover {text-decoration: underline;}\n"
			. "table {border-collapse: collapse; border: 0; width: 934px; max-width: 100%;"
			. " box-shadow: 1px 2px 3px rgba(0, 0, 0, 0.2);}\n"
			. ".center {text-align: center;}\n"
			. ".center table {margin: 1em auto; text-align: left;}\n"
			. ".center th {text-align: center !important;}\n"
			. "td, th {border: 1px solid #666; font-size: 75%; vertical-align: baseline;"
			. " padding: 4px 5px;}\n"
			. "th {position: sticky; top: 0; background: inherit;}\n"
			. "h1 {font-size: 150%;}\n"
			. "h2 {font-size: 125%;}\n"
			. "h2 a:link, h2 a:visited {color: inherit; background: inherit;}\n"
			. ".p {text-align: left;}\n"
			. ".e {background-color: #ccf; width: 300px; font-weight: bold;}\n"
			. ".h {background-color: #99c; font-weight: bold;}\n"
			. ".v {background-color: #ddd; max-width: 300px; overflow-x: auto;"
			. " word-wrap: break-word;}\n"
			. ".v i {color: #999;}\n"
			. "img {float: right; border: 0;}\n"
			. "hr {width: 934px; max-width: 100%; background-color: #ccc; border: 0;"
			. " height: 1px;}\n"
			. ":root {--php-dark-grey: #333; --php-dark-blue: #4F5B93;"
			. " --php-medium-blue: #8892BF; --php-light-blue: #E2E4EF;"
			. " --php-accent-purple: #793862}\n"
			. "@media (prefers-color-scheme: dark) {\n"
			. "  body {background: var(--php-dark-grey); color: var(--php-light-blue)}\n"
			. "  .h td, td.e, th {border-color: #606A90}\n"
			. "  td {border-color: #505153}\n"
			. "  .e {background-color: #404A77}\n"
			. "  .h {background-color: var(--php-dark-blue)}\n"
			. "  .v {background-color: var(--php-dark-grey)}\n"
			. "  hr {background-color: #505153}\n"
			. "}\n";
	}
}
