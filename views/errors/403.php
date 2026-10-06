<?php
/** AgriSense - 403 Forbidden (role check failed). */
$GLOBALS['ERROR_CODE'] = '403';
$GLOBALS['ERROR_TITLE'] ??= 'Access denied';
$GLOBALS['ERROR_MESSAGE'] ??= 'You do not have permission to open this page.';
require __DIR__ . '/_error.php';
