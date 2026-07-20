<?php
/**
 * @module Q
 */
/**
 * Non-blocking event loop for Q. Timers, stream watchers,
 * deferred callbacks, signal handling. Built-in stream_select
 * driver. Optional Revolt driver when amphp/ReactPHP installed.
 * @class Q_Evented
 */
class Q_Evented
{
	static function onReadable($stream, callable $cb) { return self::driver()->onReadable($stream, $cb); }
	static function onWritable($stream, callable $cb) { return self::driver()->onWritable($stream, $cb); }
	static function delay($sec, callable $cb) { return self::driver()->delay($sec, $cb); }
	static function repeat($sec, callable $cb) { return self::driver()->repeat($sec, $cb); }
	static function defer(callable $cb) { return self::driver()->defer($cb); }
	static function onSignal($sig, callable $cb) { return self::driver()->onSignal($sig, $cb); }
	static function cancel($id) { self::driver()->cancel($id); }
	static function disable($id) { self::driver()->disable($id); }
	static function enable($id) { self::driver()->enable($id); }
	static function run() { self::driver()->run(); }
	static function tick($timeout = 0) { self::driver()->tick($timeout); }
	static function stop() { self::driver()->stop(); }
	static function running() { return self::driver()->running(); }

	static function driver()
	{
		if (!self::$driver) {
			self::$driver = class_exists('Revolt\\EventLoop')
				? new Q_Evented_Revolt()
				: new Q_Evented_StreamSelect();
		}
		return self::$driver;
	}
	static function setDriver(Q_Evented_Driver $d) { self::$driver = $d; }
	protected static $driver = null;
}
