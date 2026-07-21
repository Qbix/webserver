<?php
Q::header('X-Custom-Header: hello');
Q::header('Cache-Control: public, max-age=300');
Q::header('HTTP/1.1 201 Created', true, 201);
echo 'created';
