<?php
/**
 * @module Q
 */

/**
 * Built-in event loop using stream_select(). Zero dependencies.
 * Handles stream watching, timers, deferred callbacks, signals.
 *
 * @class Q_Evented_StreamSelect
 * @extends Q_Evented_Driver
 */
class Q_Evented_StreamSelect extends Q_Evented_Driver
{
	protected $running = false;
	protected $nextId = 1;
	protected $readers = array();     // id => [stream, callback]
	protected $writers = array();     // id => [stream, callback]
	protected $timers = array();      // id => [fireAt, interval, callback]
	protected $deferred = array();    // id => callback
	protected $signals = array();     // id => [signal, callback]
	protected $disabled = array();    // id => true
	protected $streamToReaders = array();
	protected $streamToWriters = array();

	function onReadable($stream, callable $cb)
	{
		$id = 'r' . ($this->nextId++);
		$this->readers[$id] = array($stream, $cb);
		$this->streamToReaders[(int)$stream][$id] = true;
		return $id;
	}

	function onWritable($stream, callable $cb)
	{
		$id = 'w' . ($this->nextId++);
		$this->writers[$id] = array($stream, $cb);
		$this->streamToWriters[(int)$stream][$id] = true;
		return $id;
	}

	function delay($sec, callable $cb)
	{
		$id = 'd' . ($this->nextId++);
		$this->timers[$id] = array(
			'fireAt' => microtime(true) + $sec,
			'interval' => 0, 'callback' => $cb
		);
		return $id;
	}

	function repeat($sec, callable $cb)
	{
		$id = 't' . ($this->nextId++);
		$this->timers[$id] = array(
			'fireAt' => microtime(true) + $sec,
			'interval' => $sec, 'callback' => $cb
		);
		return $id;
	}

	function defer(callable $cb)
	{
		$id = 'f' . ($this->nextId++);
		$this->deferred[$id] = $cb;
		return $id;
	}

	function onSignal($sig, callable $cb)
	{
		if (!function_exists('pcntl_signal')) {
			throw new Exception("Signal handling requires pcntl extension");
		}
		$id = 's' . ($this->nextId++);
		$this->signals[$id] = array($sig, $cb);
		$signals = &$this->signals;
		$disabled = &$this->disabled;
		pcntl_signal($sig, function ($s) use (&$signals, &$disabled) {
			foreach ($signals as $sid => $entry) {
				if ($entry[0] === $s && empty($disabled[$sid])) {
					$entry[1]($s);
				}
			}
		});
		return $id;
	}

	function cancel($id)
	{
		if (isset($this->readers[$id])) {
			$key = (int)$this->readers[$id][0];
			unset($this->readers[$id], $this->streamToReaders[$key][$id]);
			if (empty($this->streamToReaders[$key])) unset($this->streamToReaders[$key]);
		}
		if (isset($this->writers[$id])) {
			$key = (int)$this->writers[$id][0];
			unset($this->writers[$id], $this->streamToWriters[$key][$id]);
			if (empty($this->streamToWriters[$key])) unset($this->streamToWriters[$key]);
		}
		unset($this->timers[$id], $this->deferred[$id],
			$this->signals[$id], $this->disabled[$id]);
	}

	function disable($id) { $this->disabled[$id] = true; }
	function enable($id) { unset($this->disabled[$id]); }
	function running() { return $this->running; }
	function stop() { $this->running = false; }

	function run()
	{
		$this->running = true;
		while ($this->running && $this->hasWatchers()) {
			$this->tick(null);
		}
		$this->running = false;
	}

	function tick($timeout = 0)
	{
		// 1. Deferred callbacks
		if (!empty($this->deferred)) {
			$batch = $this->deferred;
			$this->deferred = array();
			foreach ($batch as $id => $cb) {
				if (empty($this->disabled[$id])) $cb();
			}
		}

		// 2. Timers
		$now = microtime(true);
		$nextTimer = null;
		foreach ($this->timers as $id => $t) {
			if (!empty($this->disabled[$id])) continue;
			if ($now >= $t['fireAt']) {
				$t['callback']();
				if ($t['interval'] > 0) {
					$this->timers[$id]['fireAt'] = $now + $t['interval'];
				} else {
					unset($this->timers[$id]);
				}
			} else {
				$rem = $t['fireAt'] - $now;
				if ($nextTimer === null || $rem < $nextTimer) $nextTimer = $rem;
			}
		}

		// 3. Signals
		if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();

		// 4. Stream select
		$read = $write = array();
		foreach ($this->readers as $id => $e) {
			if (empty($this->disabled[$id])) $read[] = $e[0];
		}
		foreach ($this->writers as $id => $e) {
			if (empty($this->disabled[$id])) $write[] = $e[0];
		}

		if (empty($read) && empty($write)) {
			if ($nextTimer !== null) {
				$sleep = ($timeout !== null) ? min($nextTimer, $timeout) : $nextTimer;
				if ($sleep > 0) usleep((int)($sleep * 1000000));
			}
			return;
		}

		$wait = $timeout;
		if ($nextTimer !== null) {
			$wait = ($wait !== null) ? min($wait, $nextTimer) : $nextTimer;
		}
		$sec = ($wait !== null) ? (int)$wait : null;
		$usec = ($wait !== null) ? (int)(($wait - (int)$wait) * 1000000) : null;
		$except = null;
		$n = @stream_select($read, $write, $except, $sec, $usec);
		if ($n === false) return;

		foreach ($read as $stream) {
			$key = (int)$stream;
			if (!isset($this->streamToReaders[$key])) continue;
			foreach ($this->streamToReaders[$key] as $id => $_) {
				if (empty($this->disabled[$id]) && isset($this->readers[$id])) {
					$this->readers[$id][1]($stream);
				}
			}
		}
		foreach ($write as $stream) {
			$key = (int)$stream;
			if (!isset($this->streamToWriters[$key])) continue;
			foreach ($this->streamToWriters[$key] as $id => $_) {
				if (empty($this->disabled[$id]) && isset($this->writers[$id])) {
					$this->writers[$id][1]($stream);
				}
			}
		}
	}

	protected function hasWatchers()
	{
		return !empty($this->readers) || !empty($this->writers)
			|| !empty($this->timers) || !empty($this->deferred)
			|| !empty($this->signals);
	}
}
