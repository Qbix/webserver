<?php
/**
 * Boot master. Included at the top level of qbixserver.php, so the
 * framework's own bootstrap files (wp-load.php, wp-config.php, plugins) run at
 * global scope and their top-level variables are real globals.
 */
Q_WebServer_Boot::masterStart();
foreach (Q_WebServer_Boot::$adapter->globalIncludes() as $__qbix_file) {
	require_once $__qbix_file;
}
unset($__qbix_file);
Q_WebServer_Boot::masterBooted();
Q_WebServer_Boot::masterLoop();   // forks booted workers; never returns in the master
