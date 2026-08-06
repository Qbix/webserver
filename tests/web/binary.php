<?php
// Binary-safe output: null bytes and high bytes must survive intact.
Q_WebServer_State::header('Content-Type: application/octet-stream');
echo "\x00\x01\x02\xff\xfe binary-ok \x00";
