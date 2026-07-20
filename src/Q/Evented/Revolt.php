<?php
class Q_Evented_Revolt extends Q_Evented_Driver
{
	protected $running = false;
	function onReadable($s, callable $cb) {
		return \Revolt\EventLoop::onReadable($s, function($id,$s)use($cb){ $cb($s); });
	}
	function onWritable($s, callable $cb) {
		return \Revolt\EventLoop::onWritable($s, function($id,$s)use($cb){ $cb($s); });
	}
	function delay($sec, callable $cb) {
		return \Revolt\EventLoop::delay($sec, function()use($cb){ $cb(); });
	}
	function repeat($sec, callable $cb) {
		return \Revolt\EventLoop::repeat($sec, function()use($cb){ $cb(); });
	}
	function defer(callable $cb) {
		return \Revolt\EventLoop::defer(function()use($cb){ $cb(); });
	}
	function onSignal($sig, callable $cb) {
		return \Revolt\EventLoop::onSignal($sig, function($id,$s)use($cb){ $cb($s); });
	}
	function cancel($id) { \Revolt\EventLoop::cancel($id); }
	function disable($id) { \Revolt\EventLoop::disable($id); }
	function enable($id) { \Revolt\EventLoop::enable($id); }
	function run() { $this->running = true; \Revolt\EventLoop::run(); $this->running = false; }
	function tick($t = 0) { \Revolt\EventLoop::delay($t?:0.0,function(){}); \Revolt\EventLoop::run(); }
	function stop() { $this->running = false; }
	function running() { return $this->running; }
}
