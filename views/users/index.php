<?php
/**
 * AgriSense - User management (administrators only)
 *
 * @var array  $users
 * @var string $search
 * @var int    $selfId
 */
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <form method="get" action="<?= e(url('users')) ?>" class="d-flex gap-2">
        <input type="search" class="form-control form-control-sm" name="search" style="min-width: 15rem;"
               value="<?= e($search) ?>" placeholder="Search by name or username">
        <button class="btn btn-sm btn-outline-ag" type="submit"><?= icon('search', 15) ?></button>
        <?php if ($search !== ''): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('users')) ?>">Clear</a>
        <?php endif; ?>
    </form>

    <a class="btn btn-sm btn-ag" href="<?= e(url('users/create')) ?>">
        <?= icon('plus', 16) ?> Add user
    </a>
</div>

<div class="ag-card">
    <div class="table-responsive">
        <table class="table ag-table table-hover mb-0">
            <thead>
            <tr>
                <th>Name</th>
                <th>Username</th>
                <th>Role</th>
                <th>Pump control</th>
                <th>Status</th>
                <th>Last sign-in</th>
                <th>Created</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$users): ?>
                <tr>
                    <td colspan="8"><div class="ag-empty">No users match that search.</div></td>
                </tr>
            <?php else: ?>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td class="fw-semibold">
                            <?= e($u['name']) ?>
                            <?php if ((int) $u['id'] === $selfId): ?>
                                <span class="badge text-bg-light border ms-1">you</span>
                            <?php endif; ?>
                        </td>
                        <td><code class="small">@<?= e($u['username']) ?></code></td>
                        <td>
                            <span class="badge text-bg-<?= $u['role'] === 'admin' ? 'dark' : 'secondary' ?>">
                                <?= e($u['role'] === 'admin' ? 'Administrator' : 'Farmer') ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($u['role'] === 'admin'): ?>
                                <span class="text-muted small">always allowed</span>
                            <?php elseif ((int) $u['can_control_pump'] === 1): ?>
                                <span class="ag-pill ag-pill-on"><?= icon('check', 12) ?> Allowed</span>
                            <?php else: ?>
                                <span class="ag-pill ag-pill-off">View only</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge text-bg-<?= $u['status'] === 'active' ? 'success' : 'secondary' ?>">
                                <?= e(ucfirst($u['status'])) ?>
                            </span>
                        </td>
                        <td class="small text-nowrap"><?= e($u['last_login'] ? time_ago($u['last_login']) : 'never') ?></td>
                        <td class="small text-nowrap"><?= e(fmt_date($u['created_at'])) ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary"
                               href="<?= e(url('users/edit', ['id' => (int) $u['id']])) ?>"
                               aria-label="Edit <?= e($u['name']) ?>"><?= icon('edit', 14) ?></a>

                            <?php if ((int) $u['id'] !== $selfId): ?>
                                <form method="post" action="<?= e(url('users/delete')) ?>" class="d-inline m-0"
                                      data-confirm="Delete the account for <?= e($u['name']) ?>? This cannot be undone.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= e((string) $u['id']) ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit"
                                            aria-label="Delete <?= e($u['name']) ?>"><?= icon('trash', 14) ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="ag-card mt-3">
    <div class="ag-card-head">
        <h2 class="ag-card-title"><?= icon('info', 16) ?> What each role can do</h2>
    </div>
    <div class="ag-card-body">
        <div class="row g-3 small">
            <div class="col-md-6">
                <h3 class="h6 fw-bold">Administrator</h3>
                <ul class="text-muted mb-0">
                    <li>Manage users and devices</li>
                    <li>Configure irrigation thresholds and calibration</li>
                    <li>View all sensor data, irrigation logs and alerts</li>
                    <li>Generate and export reports</li>
                    <li>Control pumps and read the system audit log</li>
                </ul>
            </div>
            <div class="col-md-6">
                <h3 class="h6 fw-bold">Farmer</h3>
                <ul class="text-muted mb-0">
                    <li>View the dashboard and live readings</li>
                    <li>View pump status, irrigation history and alerts</li>
                    <li>View basic reports and export them</li>
                    <li>Control the pump <em>only</em> when the pump-control
                        permission is enabled on their account</li>
                </ul>
            </div>
        </div>
    </div>
</div>
