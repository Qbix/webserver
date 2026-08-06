<?php
// Sessions must survive the fork and be written before the response is
// assembled, or the Set-Cookie is lost and every request looks like a new user.
session_start();
$_SESSION['n'] = ($_SESSION['n'] ?? 0) + 1;
echo json_encode(array('n' => $_SESSION['n'], 'id' => session_id() !== ''));
