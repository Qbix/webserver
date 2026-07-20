<?php
/**
 * @module Q
 */

/**
 * Maintains a versioned, diffable cache of hashed content.
 *
 * Provides the scan → hash → snapshot → diff lifecycle used by
 * scripts/Q/urls.php (static-file cache-busting) and by the
 * IndieWeb plugin (feed generation from rendered HTML), but is
 * generic enough for any workflow that needs to detect content
 * changes, store snapshots, and compute incremental diffs.
 *
 * Directory structure it manages:
 *
 *   $configDir/
 *     $name.php              ← var_export array for fast include()
 *     $name/
 *       entries/
 *         {timestamp}.json   ← permanent snapshots
 *         latest.json        ← copy of most recent snapshot
 *       diffs/
 *         {timestamp}.json   ← diff from that snapshot to current
 *
 * @class Q_Snapshot
 */
class Q_Snapshot
{
	/**
	 * @property $name
	 * @type string
	 */
	public $name;

	/**
	 * @property $configDir
	 * @type string
	 */
	public $configDir;

	/**
	 * @property $webDir
	 * @type string|null
	 */
	public $webDir;

	/**
	 * @property $time
	 * @type integer
	 */
	public $time;

	/**
	 * @property $earliest
	 * @type integer
	 */
	public $earliest;

	/**
	 * @property $previous
	 * @type array|null
	 */
	public $previous;

	protected $entriesDir;
	protected $diffsDir;
	protected $parentDir;

	/**
	 * @method __construct
	 * @param {string} $name Identifier like 'urls' or 'feeds'
	 * @param {string} $configDir Where to store snapshots
	 * @param {string|null} [$webDir=null] Symlink target for web access
	 */
	function __construct($name, $configDir, $webDir = null)
	{
		$this->name = $name;
		$this->configDir = $configDir;
		$this->webDir = $webDir;
		$this->time = time();

		$this->parentDir = $configDir . DS . $name;
		$this->entriesDir = $this->parentDir . DS . 'entries';
		$this->diffsDir = $this->parentDir . DS . 'diffs';

		foreach (array(
			$configDir, $this->parentDir,
			$this->entriesDir, $this->diffsDir
		) as $dir) {
			if (!file_exists($dir)) {
				mkdir($dir, 0755, true);
			}
		}

		if ($webDir && is_dir($this->parentDir) && !file_exists($webDir)) {
			Q_Utils::symlink($this->parentDir, $webDir);
		}

		$this->earliest = $this->time;
		$this->previous = null;
		$json = file_get_contents($this->entriesDir . DS . 'latest.json');
		if ($json !== false) {
			$this->previous = Q::json_decode($json, true);
			if (!empty($this->previous['@earliest'])) {
				$this->earliest = $this->previous['@earliest'];
			}
		}
	}

	/**
	 * @method hash
	 * @static
	 * @param {string} $content
	 * @param {string} [$algo='sha256']
	 * @return {string} base64-encoded hash
	 */
	static function hash($content, $algo = 'sha256')
	{
		return base64_encode(hash($algo, $content, true));
	}

	/**
	 * Check whether content has changed since last snapshot.
	 * @method changed
	 * @param {string} $key Path into the tree
	 * @param {string} $hash base64 hash of current content
	 * @param {integer|null} [$mtime=null] File mtime for fast skip
	 * @return {boolean}
	 */
	function changed($key, $hash, $mtime = null)
	{
		if ($mtime !== null && $mtime <= $this->earliest) {
			return false;
		}
		if ($this->previous) {
			$parts = is_array($key) ? $key : explode(DS, $key);
			$prev = $this->previous;
			foreach ($parts as $part) {
				if (!isset($prev[$part])) return true;
				$prev = $prev[$part];
			}
			if (is_array($prev) && isset($prev['h']) && $prev['h'] === $hash) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Save a result tree as the current snapshot.
	 * @method save
	 * @param {array} $result
	 * @return {Q_Snapshot}
	 */
	function save(array $result)
	{
		$result['@timestamp'] = $this->time;
		if (empty($result['@earliest'])) {
			$result['@earliest'] = $this->earliest;
		}
		$json = Q::json_encode($result);
		file_put_contents($this->entriesDir . DS . $this->time . '.json', $json);
		file_put_contents($this->entriesDir . DS . 'latest.json', $json);
		$export = Q::var_export($result);
		file_put_contents($this->configDir . DS . $this->name . '.php', "<?php\nreturn $export;");
		$this->previous = $result;
		return $this;
	}

	/**
	 * Generate diff files from every historical snapshot to current.
	 * @method diffs
	 * @param {array} $currentResult
	 * @return {integer} Number of diffs generated
	 */
	function diffs(array $currentResult)
	{
		$files = glob($this->diffsDir . DS . '*');
		foreach ($files as $file) {
			if (is_file($file)) unlink($file);
		}
		$currentTree = new Q_Tree($currentResult);
		$filenames = glob($this->entriesDir . DS . '*');
		$i = 0;
		$n = count($filenames) - 1;
		foreach ($filenames as $g) {
			$b = basename($g);
			if ($b === 'latest.json') continue;
			$t = new Q_Tree();
			$t->load($g);
			$diff = $t->diff($currentTree, false);
			$diff->set('@timestamp', $this->time);
			$diff->save($this->diffsDir . DS . $b);
			++$i;
			echo "\033[100D";
			echo "Generated $i of $n diff files    ";
		}
		return $i;
	}

	/**
	 * Load cached snapshot from the PHP file (for runtime).
	 * @method load
	 * @return {array|null}
	 */
	function load()
	{
		$f = $this->configDir . DS . $this->name . '.php';
		return file_exists($f) ? include($f) : null;
	}

	/**
	 * Update a single key without full rescan.
	 * @method update
	 * @param {string} $key
	 * @param {string} $hash
	 * @param {array} [$metadata=array()]
	 * @return {boolean}
	 */
	function update($key, $hash, array $metadata = array())
	{
		if (!$this->changed($key, $hash)) return false;
		$cached = $this->load() ?: array();
		$value = array_merge(array('t' => $this->time, 'h' => $hash), $metadata);
		$tree = new Q_Tree($cached);
		$parts = explode(DS, $key);
		$parts[] = $value;
		call_user_func_array(array($tree, 'set'), $parts);
		$this->save($tree->getAll());
		return true;
	}

	function entriesDir() { return $this->entriesDir; }
	function diffsDir() { return $this->diffsDir; }
}
