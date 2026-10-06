<?php
/** AgriSense - 500 / 503 unexpected failure. */
$GLOBALS['ERROR_CODE'] = (string) (http_response_code() ?: 500);
$GLOBALS['ERROR_TITLE'] ??= 'Unexpected error';
$GLOBALS['ERROR_MESSAGE'] ??= 'Something went wrong while processing your request.';
require __DIR__ . '/_error.php';
