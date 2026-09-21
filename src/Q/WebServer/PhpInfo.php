<?php

/**
 * Renders phpinfo() output as the HTML page people expect.
 *
 * phpinfo() only builds an HTML page under mod_php and fpm. Every
 * CLI-family SAPI — phpmicro included — emits plain text instead, and the
 * choice is a SAPI-level flag no ini setting reaches. This class parses
 * that text back into the familiar tables.
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
 * @class Q_WebServer_PhpInfo
 */
class Q_WebServer_PhpInfo
{
	/**
	 * Section names phpinfo() emits that are not extension names.
	 */
	private static $sections = array(
		'Configuration', 'Additional Modules', 'Environment',
		'PHP Variables', 'PHP License'
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
		$title = $version === '' ? 'phpinfo()' : 'PHP ' . $version;

		return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>' . self::esc($title) . '</title>'
			. '<style>' . self::css() . '</style></head><body><div class="wrap">'
			. ($version === '' ? '' : '<h1>PHP Version ' . self::esc($version) . '</h1>')
			. $body
			. '</div></body></html>';
	}

	/**
	 * Parse the text body into HTML headings and tables.
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

		$flush = function () use (&$out, &$rows, &$cols) {
			if (!$rows) return;
			$out .= '<table>';
			foreach ($rows as $row) {
				$out .= $row;
			}
			$out .= '</table>';
			$rows = array();
			$cols = 2;
		};

		foreach ($lines as $line) {
			$line = rtrim($line, "\r\n");

			// Everything after the licence heading is prose, not key/value
			// rows: paragraphs of running text with blank lines between them.
			// Parsed as rows it turns into a heading per paragraph.
			if ($prose) {
				if (trim($line) === '') {
					if ($para) {
						$out .= '<p>' . implode('<br>', array_map(
							array(__CLASS__, 'esc'), $para
						)) . '</p>';
						$para = array();
					}
				} else {
					$para[] = $line;
				}
				continue;
			}

			if (trim($line) === '') {
				$blank = true;
				continue;
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
				if ($blank) {
					// Heading — close the table that came before it.
					$flush();
					$cls = in_array($line, self::$sections, true) ? ' class="section"' : '';
					$out .= '<h2' . $cls . '>' . self::esc($line) . '</h2>';
					if ($line === 'PHP License') $prose = true;
				} else {
					// Continuation of the value on the previous row.
					$n = count($rows);
					if ($n) {
						$rows[$n - 1] = preg_replace(
							'/(<\/td><\/tr>)$/',
							'<br>' . self::esc($line) . '$1',
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
				$rows[] = '<tr class="head"><th>Directive</th>'
					. '<th>Local Value</th><th>Master Value</th></tr>';
				$blank = false;
				continue;
			}

			// Split to the arity of the open table, so a value containing
			// " => " itself stays in one cell.
			$parts = explode(' => ', $line, $cols);
			$cells = '<td class="k">' . self::esc($parts[0]) . '</td>';
			for ($i = 1; $i < $cols; $i++) {
				$v = isset($parts[$i]) ? $parts[$i] : '';
				$cells .= '<td class="v">' . self::esc($v) . '</td>';
			}
			$rows[] = '<tr>' . $cells . '</tr>';
			$blank = false;
		}

		if ($para) {
			$out .= '<p>' . implode('<br>', array_map(
				array(__CLASS__, 'esc'), $para
			)) . '</p>';
		}
		$flush();
		return $out;
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
	 * Stylesheet for the rendered page. Follows phpinfo's familiar layout
	 * without copying its 1998 colours, and honours the dark-mode preference.
	 * @method css
	 * @static
	 * @protected
	 * @return {string}
	 */
	protected static function css()
	{
		return ':root{--bg:#fff;--fg:#1c1e22;--line:#d8dbe0;--k:#f2f4f7;'
			. '--v:#fff;--head:#5b6fb8;--headfg:#fff;--h2:#eef0f6;--muted:#6b7280}'
			. '@media(prefers-color-scheme:dark){:root{--bg:#16181d;--fg:#d6d9e0;'
			. '--line:#2c3038;--k:#1d2027;--v:#16181d;--head:#3d4a7a;--headfg:#e8ebf2;'
			. '--h2:#20242c;--muted:#8b93a1}}'
			. '*{box-sizing:border-box}'
			. 'body{margin:0;padding:24px 16px;background:var(--bg);color:var(--fg);'
			. 'font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}'
			. '.wrap{max-width:960px;margin:0 auto}'
			. 'h1{font-size:22px;margin:0 0 20px;padding-bottom:12px;'
			. 'border-bottom:2px solid var(--head)}'
			. 'h2{font-size:15px;margin:28px 0 8px;padding:7px 10px;'
			. 'background:var(--h2);border-left:3px solid var(--head);border-radius:3px}'
			. 'h2.section{font-size:17px;margin-top:36px;background:var(--head);'
			. 'color:var(--headfg);border-left-color:var(--head)}'
			. 'table{width:100%;border-collapse:collapse;margin:0 0 6px;'
			. 'border:1px solid var(--line);table-layout:fixed}'
			. 'th,td{padding:6px 10px;border:1px solid var(--line);text-align:left;'
			. 'vertical-align:top;word-break:break-word;font-size:13px}'
			. 'tr.head th{background:var(--head);color:var(--headfg);font-weight:600}'
			. 'td.k{background:var(--k);font-weight:600;width:34%}'
			. 'td.v{background:var(--v);font-family:ui-monospace,SFMono-Regular,'
			. 'Menlo,Consolas,monospace;color:var(--muted)}'
			. 'p{margin:10px 0;color:var(--muted);max-width:70ch}'
			. '@media(max-width:640px){td.k{width:40%}'
			. 'th,td{padding:5px 7px;font-size:12px}}';
	}
}
