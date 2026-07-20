<?php
abstract class Q_Evented_Driver
{
	abstract function onReadable($stream, callable $cb);
	abstract function onWritable($stream, callable $cb);
	abstract function delay($sec, callable $cb);
	abstract function repeat($sec, callable $cb);
	abstract function defer(callable $cb);
	abstract function onSignal($sig, callable $cb);
	abstract function cancel($id);
	abstract function disable($id);
	abstract function enable($id);
	abstract function run();
	abstract function tick($timeout = 0);
	abstract function stop();
	abstract function running();
}
