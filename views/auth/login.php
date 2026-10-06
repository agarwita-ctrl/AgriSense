<?php
/**
 * AgriSense - Sign in
 *
 * Rendered without the application shell (there is no navigation to show
 * before authentication).
 *
 * @var string      $username
 * @var string|null $error
 */
require_once APP_ROOT . '/views/layout/icons.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in &middot; <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e(asset('images/favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= e(BASE_PATH) ?>/assets/vendor/bootstrap.min.css">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <style>
        body {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 1.5rem;
            background:
                radial-gradient(900px 420px at 12% -10%, rgba(67,160,71,.22), transparent 60%),
                radial-gradient(700px 380px at 105% 110%, rgba(2,136,209,.16), transparent 60%),
                var(--ag-canvas);
        }
        .login-wrap { width: 100%; max-width: 25.5rem; }
        .login-mark {
            width: 62px; height: 62px; border-radius: 18px;
            background: linear-gradient(160deg, var(--ag-green-500), var(--ag-green-700));
            display: grid; place-items: center; margin: 0 auto 1rem;
            box-shadow: 0 10px 24px rgba(30,107,44,.28);
        }
    </style>
</head>
<body>
<div class="login-wrap">

    <div class="text-center mb-4">
        <div class="login-mark">
            <img src="<?= e(asset('images/logo.svg')) ?>" width="38" height="38" alt="">
        </div>
        <h1 class="h4 fw-bold mb-1"><?= e(APP_NAME) ?></h1>
        <p class="text-muted small mb-0"><?= e(APP_TAGLINE) ?></p>
    </div>

    <div class="ag-card">
        <div class="ag-card-body">

            <?php foreach (take_flashes() as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?> py-2 small" role="alert">
                    <?= e($flash['message']) ?>
                </div>
            <?php endforeach; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger py-2 small d-flex gap-2 align-items-start" role="alert">
                    <?= icon('warning', 16) ?>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('login')) ?>" novalidate>
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label class="form-label" for="username">Username</label>
                    <input type="text" class="form-control" id="username" name="username"
                           value="<?= e($username) ?>" required autofocus
                           autocomplete="username" autocapitalize="none" spellcheck="false">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="password" name="password"
                               required autocomplete="current-password">
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword"
                                aria-label="Show password"><?= icon('user', 16) ?></button>
                    </div>
                </div>

                <button class="btn btn-ag w-100 py-2 fw-semibold" type="submit">Sign in</button>
            </form>
        </div>
    </div>

    <p class="text-center text-muted mt-4 mb-0" style="font-size:.78rem;">
        <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?><br>
        Contact your administrator if you cannot sign in.
    </p>
</div>

<script src="<?= e(BASE_PATH) ?>/assets/vendor/bootstrap.bundle.min.js"></script>
<script <?= csp_attr() ?>>
    // Small convenience: reveal the password while it is held down.
    (function () {
        var toggle = document.getElementById('togglePassword');
        var field = document.getElementById('password');
        toggle.addEventListener('click', function () {
            var show = field.type === 'password';
            field.type = show ? 'text' : 'password';
            toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            field.focus();
        });
    })();
</script>
</body>
</html>
