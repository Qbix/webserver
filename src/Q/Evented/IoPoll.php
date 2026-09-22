<?php
/**
 * Event loop driver using PHP 8.6's native Io\Poll API.
 *
 * Io\Poll wraps epoll (Linux), kqueue (macOS/BSD), event ports (Solaris),
 * and WSAPoll (Windows) natively — no PECL extensions needed.
 *
 * Performance vs stream_select:
 *   stream_select: O(n) — copies and scans all fds on every call
 *   epoll/kqueue:  O(1) — kernel tracks readiness, returns only ready fds
 *
 * At 100 connections the difference is negligible. At 1000+ connections,
 * stream_select spends milliseconds per tick just scanning; epoll returns
 * in microseconds regardless of total connection count.
 *
 * Also works on PHP 8.1–8.5 via symfony/polyfill-io-poll (backed by
 * stream_select, so no performance gain, but API compatibility).
 *
 * @class Q_Evented_IoPoll
 * @extends Q_Evented_Driver
 */
class Q_Evented_IoPoll extends Q_Evented_Driver
{
	protected $running = false;
	protected $nextId = 1;
	protected $poll = null;           // Io\Poll\Context
	protected $readers = array();     // id => [stream, callback, handle]
	protected $writers = array();     // id => [stream, callback, handle]
	protected $timers = array();      // id => [fireAt, interval, callback]
	protected $deferred = array();    // id => callback
	protected $signals = array();     // id => [signal, callback]
	protected $disabled = array();    // id => true
	protected $handleToReaderId = array();  // (int)stream => id
	protected $handleToWriterId = array();

	function __construct()
	{
		$this->poll = new \Io\Poll\Context();
	}

	function onReadable($stream, $callback)
	{
		$id = $this->nextId++;
		$handle = new \StreamPollHandle($stream);
		$this->readers[$id] = array($stream, $callback, $handle);
		$this->handleToReaderId[(int) $stream] = $id;
		$this->poll->add($handle, [\Io\Poll\Event::Read], ['id' => $id, 'type' => 'read']);
		return $id;
	}

	function onWritable($stream, $callback)
	{
		$id = $this->nextId++;
		$handle = new \StreamPollHandle($stream);
		$this->writers[$id] = array($stream, $callback, $handle);
		$this->handleToWriterId[(int) $stream] = $id;
		$this->poll->add($handle, [\Io\Poll\Event::Write], ['id' => $id, 'type' => 'write']);
		return $id;
	}

	function cancel($id)
	{
		if (isset($this->readers[$id])) {
			$entry = $this->readers[$id];
			if (is_resource($entry[0])) {
				try { $this->poll->remove($entry[2]); } catch (\Throwable $e) {}
			}
			unset($this->handleToReaderId[(int) $entry[0]]);
			unset($this->readers[$id]);
		}
		if (isset($this->writers[$id])) {
			$entry = $this->writers[$id];
			if (is_resource($entry[0])) {
				try { $this->poll->remove($entry[2]); } catch (\Throwable $e) {}
			}
			unset($this->handleToWriterId[(int) $entry[0]]);
			unset($this->writers[$id]);
		}
		unset($this->timers[$id], $this->deferred[$id],
			$this->signals[$id], $this->disabled[$id]);
	}

	function disable($id) { $this->disabled[$id] = true; }
	function enable($id) { unset($this->disabled[$id]); }

	function delay($seconds, $callback)
	{
		$id = $this->nextId++;
		$this->timers[$id] = array(microtime(true) + $seconds, 0, $callback);
		return $id;
	}

	function repeat($seconds, $callback)
	{
		$id = $this->nextId++;
		$this->timers[$id] = array(microtime(true) + $seconds, $seconds, $callback);
		return $id;
	}

	function defer($callback)
	{
		$id = $this->nextId++;
		$this->deferred[$id] = $callback;
		return $id;
	}

	function onSignal($signal, $callback)
	{
		$id = $this->nextId++;
		$this->signals[$id] = array($signal, $callback);
		if (function_exists('pcntl_signal')) {
			pcntl_signal($signal, function () use ($id) {
				if (isset($this->signals[$id])) ($this->signals[$id][1])();
			});
		}
		return $id;
	}

	function run()
	{
		$this->running = true;
		while ($this->running) {
			$this->tick();
		}
	}

	function stop() { $this->running = false; }
	function running() { return $this->running; }

	protected function tick()
	{
		// Deferred callbacks (run before I/O)
		if (!empty($this->deferred)) {
			$batch = $this->deferred;
			$this->deferred = array();
			foreach ($batch as $cb) { $cb(); }
		}

		// Signal dispatch
		if (function_exists('pcntl_signal_dispatch')) {
			pcntl_signal_dispatch();
		}

		// Timer processing
		$now = microtime(true);
		$nextTimer = null;
		foreach ($this->timers as $id => $t) {
			if ($now >= $t[0]) {
				($t[2])();
				if ($t[1] > 0) {
					$this->timers[$id][0] = $now + $t[1];
				} else {
					unset($this->timers[$id]);
				}
			} else {
				$wait = $t[0] - $now;
				if ($nextTimer === null || $wait < $nextTimer) {
					$nextTimer = $wait;
				}
			}
		}

		// Poll for I/O — timeout is next timer or 100ms
		$timeout = $nextTimer !== null ? min($nextTimer, 0.1) : 0.1;

		try {
			foreach ($this->poll->wait(timeoutSeconds: $timeout) as $watcher) {
				$data = $watcher->getData();
				$id = $data['id'] ?? null;
				if ($id === null || isset($this->disabled[$id])) continue;

				if ($data['type'] === 'read' && isset($this->readers[$id])) {
					($this->readers[$id][1])($this->readers[$id][0]);
				} elseif ($data['type'] === 'write' && isset($this->writers[$id])) {
					($this->writers[$id][1])($this->writers[$id][0]);
				}
			}
		} catch (\Throwable $e) {
			// Poll error (e.g. closed resource) — skip this tick
		}
	}
}
