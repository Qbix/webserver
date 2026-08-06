<?php

/**
 * Stream wrapper that serves THIS request's body for php://input.
 *
 * A forking server reads the request off the socket itself, so the real
 * php://input is already consumed and stays empty for the life of the
 * process. Registering this over the `php` wrapper makes
 * file_get_contents('php://input') return the current body.
 *
 * This duplicates Q_PhpInputStream from src/Q.php ON PURPOSE: that file is
 * the STANDALONE shim and is not loaded in --app mode, where the Platform's
 * Q wins. Without a webserver-owned copy, php://input silently returned an
 * empty string for every request under --app.
 *
 * @class Q_WebServer_PhpInput
 */
class Q_WebServer_PhpInput
{
	static $data = '';
	private $pos = 0;
	private $path = '';
	private $memory = '';

	function stream_open($path, $mode, $options, &$openedPath)
	{
		$this->path = str_replace('php://', '', $path);
		$this->pos = 0;
		$this->memory = '';
		return true;
	}

	function stream_read($count)
	{
		if ($this->path === 'input') {
			$chunk = substr(self::$data, $this->pos, $count);
			$this->pos += strlen($chunk);
			return $chunk;
		}
		if ($this->path === 'memory' || $this->path === 'temp') {
			$chunk = substr($this->memory, $this->pos, $count);
			$this->pos += strlen($chunk);
			return $chunk;
		}
		return false;
	}

	function stream_write($data)
	{
		if ($this->path === 'output') {
			echo $data;
			return strlen($data);
		}
		if ($this->path === 'memory' || $this->path === 'temp') {
			$this->memory .= $data;
			$this->pos += strlen($data);
			return strlen($data);
		}
		return 0;
	}

	function stream_eof()
	{
		if ($this->path === 'input') return $this->pos >= strlen(self::$data);
		if ($this->path === 'memory' || $this->path === 'temp') return $this->pos >= strlen($this->memory);
		return true;
	}

	function stream_stat() {
		if ($this->path === 'input') {
			return array('size' => strlen(self::$data));
		}
		if ($this->path === 'memory' || $this->path === 'temp') {
			return array('size' => strlen($this->memory));
		}
		return array();
	}
	function url_stat($path, $flags) {
		return array('size' => strlen(self::$data));
	}
	function stream_tell() { return $this->pos; }
	function stream_seek($offset, $whence) {
		if ($whence === SEEK_SET) $this->pos = $offset;
		elseif ($whence === SEEK_CUR) $this->pos += $offset;
		return true;
	}
}
