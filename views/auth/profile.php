<?php
/**
 * AgriSense - The signed-in user's own account page.
 *
 * @var array $user
 * @var array $errors
 */
$err     = static fn (string $f): string => isset($errors[$f])
    ? '<div class="invalid-feedback d-block">' . e($errors[$f]) . '</div>' : '';
$invalid = static fn (string $f): string => isset($errors[$f]) ? ' is-invalid' : '';
?>

<div class="row g-3">
    <div class="col-12 col-lg-5">
        <div class="ag-card">
            <div class="ag-card-head">
                <h2 class="ag-card-title"><?= icon('user', 16) ?> My account</h2>
            </div>
            <div class="ag-card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted fw-normal">Name</dt>
                    <dd class="col-7 fw-semibold"><?= e($user['name']) ?></dd>

                    <dt class="col-5 text-muted fw-normal">Username</dt>
                    <dd class="col-7"><code>@<?= e($user['username']) ?></code></dd>

                    <dt class="col-5 text-muted fw-normal">Role</dt>
                    <dd class="col-7">
                        <span class="badge text-bg-<?= $user['role'] === 'admin' ? 'dark' : 'secondary' ?>">
                            <?= e($user['role'] === 'admin' ? 'Administrator' : 'Farmer') ?>
                        </span>
                    </dd>

                    <dt class="col-5 text-muted fw-normal">Pump control</dt>
                    <dd class="col-7">
                        <?= Auth::canControlPump()
                            ? '<span class="ag-pill ag-pill-on">Allowed</span>'
                            : '<span class="ag-pill ag-pill-off">View only</span>' ?>
                    </dd>

                    <dt class="col-5 text-muted fw-normal">Last sign-in</dt>
                    <dd class="col-7"><?= e($user['last_login'] ? fmt_datetime($user['last_login']) : 'first session') ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-7">
        <form method="post" action="<?= e(url('profile')) ?>" novalidate autocomplete="off">
            <?= csrf_field() ?>
            <div class="ag-card">
                <div class="ag-card-head">
                    <h2 class="ag-card-title"><?= icon('key', 16) ?> Change my password</h2>
                </div>
                <div class="ag-card-body">
                    <div class="mb-3">
                        <label class="form-label" for="current_password">Current password</label>
                        <input type="password" class="form-control<?= $invalid('current_password') ?>"
                               id="current_password" name="current_password" required
                               autocomplete="current-password">
                        <?= $err('current_password') ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="new_password">New password</label>
                        <input type="password" class="form-control<?= $invalid('new_password') ?>"
                               id="new_password" name="new_password" required autocomplete="new-password">
                        <div class="ag-field-help">
                            At least 8 characters, including a letter and a number.
                        </div>
                        <?= $err('new_password') ?>
                    </div>

                    <div class="mb-0">
                        <label class="form-label" for="confirm_password">Confirm new password</label>
                        <input type="password" class="form-control<?= $invalid('confirm_password') ?>"
                               id="confirm_password" name="confirm_password" required autocomplete="new-password">
                        <?= $err('confirm_password') ?>
                    </div>
                </div>
                <div class="ag-card-body border-top">
                    <button class="btn btn-ag" type="submit"><?= icon('check', 16) ?> Update password</button>
                </div>
            </div>
        </form>
    </div>
</div>
