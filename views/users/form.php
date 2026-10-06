<?php
/**
 * AgriSense - Add / edit a user account
 *
 * @var array $user
 * @var array $errors
 * @var bool  $isNew
 */
$err     = static fn (string $f): string => isset($errors[$f])
    ? '<div class="invalid-feedback d-block">' . e($errors[$f]) . '</div>' : '';
$invalid = static fn (string $f): string => isset($errors[$f]) ? ' is-invalid' : '';

$action = $isNew ? url('users/create') : url('users/edit', ['id' => (int) $user['id']]);
?>

<div class="mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('users')) ?>">&larr; Back to users</a>
</div>

<div class="row">
    <div class="col-12 col-lg-7 col-xl-6">
        <form method="post" action="<?= e($action) ?>" novalidate autocomplete="off">
            <?= csrf_field() ?>
            <?php if (!$isNew): ?>
                <input type="hidden" name="id" value="<?= e((string) $user['id']) ?>">
            <?php endif; ?>

            <div class="ag-card">
                <div class="ag-card-head">
                    <h2 class="ag-card-title">
                        <?= icon('users', 16) ?> <?= $isNew ? 'New user account' : 'Edit account' ?>
                    </h2>
                </div>
                <div class="ag-card-body">

                    <div class="mb-3">
                        <label class="form-label" for="name">Full name</label>
                        <input type="text" class="form-control<?= $invalid('name') ?>" id="name" name="name"
                               maxlength="100" required value="<?= e($user['name']) ?>">
                        <?= $err('name') ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="username">Username</label>
                        <input type="text" class="form-control<?= $invalid('username') ?>" id="username"
                               name="username" maxlength="50" required value="<?= e($user['username']) ?>"
                               autocapitalize="none" spellcheck="false" autocomplete="off">
                        <div class="ag-field-help">3&ndash;50 letters, numbers, dots, dashes or underscores.</div>
                        <?= $err('username') ?>
                    </div>

                    <hr>

                    <div class="mb-3">
                        <label class="form-label" for="password">
                            <?= $isNew ? 'Password' : 'New password' ?>
                            <?php if (!$isNew): ?>
                                <span class="text-muted fw-normal">(leave blank to keep the current one)</span>
                            <?php endif; ?>
                        </label>
                        <input type="password" class="form-control<?= $invalid('password') ?>" id="password"
                               name="password" <?= $isNew ? 'required' : '' ?> autocomplete="new-password">
                        <div class="ag-field-help">
                            At least 8 characters, including a letter and a number.
                        </div>
                        <?= $err('password') ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="password_confirm">Confirm password</label>
                        <input type="password" class="form-control<?= $invalid('password_confirm') ?>"
                               id="password_confirm" name="password_confirm"
                               <?= $isNew ? 'required' : '' ?> autocomplete="new-password">
                        <?= $err('password_confirm') ?>
                    </div>

                    <hr>

                    <div class="mb-3">
                        <label class="form-label" for="role">Role</label>
                        <select class="form-select<?= $invalid('role') ?>" id="role" name="role">
                            <option value="farmer" <?= $user['role'] === 'farmer' ? 'selected' : '' ?>>
                                Farmer &mdash; monitoring and history
                            </option>
                            <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>
                                Administrator &mdash; full access
                            </option>
                        </select>
                        <?= $err('role') ?>
                    </div>

                    <div class="mb-3" id="pumpPermissionRow">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" value="1"
                                   id="can_control_pump" name="can_control_pump"
                                   <?= (int) ($user['can_control_pump'] ?? 0) === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="can_control_pump">
                                Allow this farmer to operate the pump
                            </label>
                        </div>
                        <div class="ag-field-help">
                            Without this, the account can watch the system but not switch the pump.
                            Administrators always have pump control.
                        </div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select<?= $invalid('status') ?>" id="status" name="status">
                            <option value="active"   <?= $user['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $user['status'] === 'inactive' ? 'selected' : '' ?>>Inactive &mdash; cannot sign in</option>
                        </select>
                        <?= $err('status') ?>
                    </div>
                </div>

                <div class="ag-card-body border-top d-flex gap-2">
                    <button class="btn btn-ag" type="submit">
                        <?= icon('check', 16) ?> <?= $isNew ? 'Create account' : 'Save changes' ?>
                    </button>
                    <a class="btn btn-outline-secondary" href="<?= e(url('users')) ?>">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>

<script <?= csp_attr() ?>>
    // The pump permission only means something for farmers.
    (function () {
        var role = document.getElementById('role');
        var row = document.getElementById('pumpPermissionRow');
        var sync = function () { row.style.display = role.value === 'admin' ? 'none' : ''; };
        role.addEventListener('change', sync);
        sync();
    })();
</script>
