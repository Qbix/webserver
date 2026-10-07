<?php
/**
 * One WordPress request in a booted worker, at global scope: the part of
 * wp-blog-header.php that follows wp-load.php. See Q_WebServer_Boot_WordPress.
 */
// wp-settings.php adds slashes to request input and fills PHP_AUTH_*; the
// superglobals are new for this request, so do it again.
if (function_exists('wp_magic_quotes')) wp_magic_quotes();
if (function_exists('wp_populate_basic_auth_from_authorization_header')) {
	wp_populate_basic_auth_from_authorization_header();
}
if (function_exists('timer_start')) timer_start();
$wp_did_header = true;
wp();
require ABSPATH . WPINC . '/template-loader.php';
