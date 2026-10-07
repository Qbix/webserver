<?php
/**
 * Worker loop. Included at the top level of qbixserver.php, so scripts
 * included here run at global scope. See Q_WebServer_Role.
 */
Q_WebServer_Pool::workerStart();
while (Q_WebServer_Pool::receive()) {
	// Suspend the compat wrapper during request handling ONLY when a boot
	// adapter's callable is serving the request ($scriptFile === null).
	// In that case all framework code is already loaded (boot master compiled
	// everything), so the wrapper's source transform isn't needed — suspending
	// avoids 130-180 unwrap/rewrap cycles from file_exists/is_file/stat calls
	// that frameworks make every request (~5ms saving).
	// When $scriptFile is set (no-boot / front-controller mode), the wrapper
	// MUST stay active so that define(), require_once, header() etc. in the
	// included script are rewritten to their Compat shims.
	$__qbix_compat = Q_WebServer_Pool::$roleOctane
		&& Q_WebServer_Pool::$scriptFile === null
		&& class_exists('Q_WebServer_Compat', false)
		&& Q_WebServer_Compat::isEnabled();
	if ($__qbix_compat) {
		if (class_exists('Q_WebServer_CompatFileWrapper', false)) {
			Q_WebServer_CompatFileWrapper::$__openCount = 0;
			Q_WebServer_CompatFileWrapper::$__statCount = 0;
			if (method_exists('Q_WebServer_CompatFileWrapper', 'clearStatCache')) {
				Q_WebServer_CompatFileWrapper::clearStatCache();
			}
		}
		@stream_wrapper_restore('file');
	}
	try {
		if (Q_WebServer_Pool::$scriptFile !== null) {
			(include Q_WebServer_Pool::$scriptFile);
		} else {
			call_user_func(Q_WebServer_Pool::$scriptRunner, Q_WebServer_Pool::$req);
		}
	} catch (\Throwable $__qbix_e) {
		if ($__qbix_e instanceof Q_WebServer_Role) throw $__qbix_e;
		Q_WebServer_Pool::fail($__qbix_e);
		unset($__qbix_e);
	}
	if ($__qbix_compat) {
		@stream_wrapper_unregister('file');
		@stream_wrapper_register('file', 'Q_WebServer_CompatFileWrapper');
	}
	if (!Q_WebServer_Pool::respond()) break;
}
Q_WebServer_Pool::runShutdownCallbacks();
exit(0);
