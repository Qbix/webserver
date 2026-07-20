<?php
/**
 * @module Q
 */

use Revolt\EventLoop;

/**
 * Event loop driver backed by Revolt (amphp's event loop).
 * Install via: composer require revolt/event-loop
 *
 * @class Q_Evented_Revolt
 * @extends Q_Evented_Driver
 */
class Q_Evented_Revolt extends Q_Evented_Driver
{
	protected $running = false;

	function onReadable($stream, callable $cb)
	{
		return EventLoop::onReadable($stream, function ($id, $s) use ($cb) {
			$cb($s);
		});
	}

	function onWritable($stream, callable $cb)
	{
		return EventLoop::onWritable($stream, function ($id, $s) use ($cb) {
			$cb($s);
		});
	}

	function delay($seconds, callable $cb)
	{
		return EventLoop::delay($seconds, function () use ($cb) {
			$cb();
		});
	}

	function repeat($seconds, callable $cb)
	{
		return EventLoop::repeat($seconds, function () use ($cb) {
			$cb();
		});
	}

	function defer(callable $cb)
	{
		return EventLoop::defer(function () use ($cb) {
			$cb();
		});
	}

	function onSignal($signal, callable $cb)
	{
		return EventLoop::onSignal($signal, function ($id, $s) use ($cb) {
			$cb($s);
		});
	}

	function cancel($id)  { EventLoop::cancel($id); }
	function disable($id) { EventLoop::disable($id); }
	function enable($id)  { EventLoop::enable($id); }

	function run()
	{
		$this->running = true;
		EventLoop::run();
		$this->running = false;
	}

	function tick($timeout = 0)
	{
		EventLoop::delay($timeout ?: 0.0, function () {});
		EventLoop::run();
	}

	function stop()
	{
		$this->running = false;
	}

	function running()
	{
		return $this->running;
	}
}
