<?php
/**
 * AgriSense - Shared error page body.
 *
 * Standalone (it must render even when the session or the database is
 * unavailable) and deliberately vague about internals: the detail block only
 * appears while APP_DEBUG is on.
 *
 * Globals: ERROR_TITLE, ERROR_MESSAGE, ERROR_DETAIL, ERROR_CODE
 */
$title   = $GLOBALS['ERROR_TITLE']   ?? 'Something went wrong';
$message = $GLOBALS['ERROR_MESSAGE'] ?? 'The request could not be completed.';
$detail  = $GLOBALS['ERROR_DETAIL']  ?? null;
$code    = $GLOBALS['ERROR_CODE']    ?? '';

$escape = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$base   = defined('BASE_PATH') ? BASE_PATH : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($title) ?> &middot; AgriSense</title>
    <link rel="icon" type="image/svg+xml" href="<?= $escape($base) ?>/assets/images/favicon.svg">
    <link rel="stylesheet" href="<?= $escape($base) ?>/assets/vendor/bootstrap.min.css">
    <link rel="stylesheet" href="<?= $escape($base) ?>/assets/css/app.css">
</head>
<body>
<div class="container" style="max-width: 34rem;">
    <div class="text-center py-5">

        <?php if ($code !== ''): ?>
            <div class="display-4 fw-bold text-muted mb-1"><?= $escape($code) ?></div>
        <?php endif; ?>

        <h1 class="h4 fw-bold mb-2"><?= $escape($title) ?></h1>
        <p class="text-muted"><?= $escape($message) ?></p>

        <?php if ($detail): ?>
            <div class="ag-card text-start mt-4">
                <div class="ag-card-head">
                    <h2 class="ag-card-title text-danger">Developer detail</h2>
                    <span class="ms-auto badge text-bg-warning">APP_DEBUG is on</span>
                </div>
                <div class="ag-card-body">
                    <pre class="small mb-0" style="white-space: pre-wrap;"><?= $escape($detail) ?></pre>
                </div>
            </div>
            <p class="text-muted small mt-3 mb-0">
                Set <code>APP_DEBUG</code> to <code>false</code> in <code>config/config.php</code>
                before putting this system into service.
            </p>
        <?php endif; ?>

        <div class="mt-4 d-flex gap-2 justify-content-center">
            <a class="btn btn-ag" href="<?= $escape($base) ?>/dashboard">Back to the dashboard</a>
            <button class="btn btn-outline-secondary" type="button" onclick="history.back()">Go back</button>
        </div>
    </div>
</div>
</body>
</html>
