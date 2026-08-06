<?php
// Q_WebServer_State is webserver-owned, so it exists in BOTH standalone and
// --app mode. Q::header()/Q_Response::header() exist only on the standalone
// shim, so a fixture using them passes standalone and 500s under --app.
Q_WebServer_State::header('X-Custom-Header: hello');
Q_WebServer_State::header('Cache-Control: public, max-age=300');
Q_WebServer_State::header('HTTP/1.1 201 Created');
echo 'created';
