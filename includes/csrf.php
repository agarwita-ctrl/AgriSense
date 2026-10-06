<?php
/**
 * AgriSense - CSRF protection (requirement 21)
 *
 * One token per session. Every state changing request - HTML form post or
 * fetch() call from the dashboard - must present it, either in the
 * `_token` field or in the X-CSRF-Token header.
 */

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf'];
}

/** Hidden input to drop inside every <form method="post">. */
function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

/** Constant time check of the submitted token. */
function csrf_valid(): bool
{
    $sent = $_POST['_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';

    return is_string($sent)
        && $sent !== ''
        && !empty($_SESSION['_csrf'])
        && hash_equals($_SESSION['_csrf'], $sent);
}

/**
 * Enforce the token on a POST request. Answers JSON or an HTML error page
 * depending on what the caller asked for.
 */
function csrf_guard(): void
{
    if (!is_post() || csrf_valid()) {
        return;
    }

    if (wants_json()) {
        // 403 rather than a custom code: Apache refuses to emit status codes
        // it does not recognise and turns them into a 500.
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error'   => 'Your session token expired. Please reload the page and try again.',
        ]);
        exit;
    }

    flash('danger', 'Your session expired for security reasons. Please try that again.');

    // Several actions live on POST-only routes, so bounce to a page the user
    // can actually see rather than back to the route that just failed.
    redirect('dashboard');
}
