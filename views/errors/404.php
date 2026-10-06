<?php
/** AgriSense - 404 Not Found. */
$GLOBALS['ERROR_CODE'] = '404';
$GLOBALS['ERROR_TITLE'] ??= 'Page not found';
$GLOBALS['ERROR_MESSAGE'] ??= 'The page you asked for does not exist.';
require __DIR__ . '/_error.php';
