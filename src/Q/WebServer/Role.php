<?php
/**
 * @module Q
 */

/**
 * Thrown in a freshly forked child to unwind to the top level of
 * qbixserver.php, which then includes $file there. Code in that file runs at
 * global scope, as the front controller of a PHP application expects to.
 *
 * It extends Error, not Exception, so `catch (Exception $e)` blocks on the way
 * up do not stop it. Catch-alls for Throwable on the path rethrow it.
 *
 * @class Q_WebServer_Role
 */
class Q_WebServer_Role extends \Error
{
	/** @var string File to include at global scope (Error::$file is taken) */
	public $role;

	function __construct($file)
	{
		parent::__construct('Q_WebServer_Role: ' . basename($file));
		$this->role = $file;
	}
}
