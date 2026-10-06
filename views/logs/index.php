<?php
/**
 * AgriSense - System audit log and data maintenance (administrators only)
 *
 * @var array $rows
 * @var array $meta
 * @var array $filters
 * @var array $range
 * @var array $actions
 * @var array $users
 */
$exportQuery = $_GET;
unset($exportQuery['page'], $exportQuery['r']);

$demoReadings = SensorReading::demoCount();
$demoCycles   = IrrigationLog::demoCount();
$demoAlerts   = (int) Database::scalar("SELECT COUNT(*) FROM alerts WHERE source = 'demo'");
$demoTotal    = $demoReadings + $demoCycles + $demoAlerts;
?>

<?php if ($demoTotal > 0): ?>
    <div class="ag-card mb-3">
        <div class="ag-card-head">
            <h2 class="ag-card-title"><?= icon('warning', 16) ?> Sample data is still installed</h2>
        </div>
        <div class="ag-card-body">
            <p class="small text-muted">
                The installer seeded example rows so the dashboard and charts had something to show
                before any hardware was connected. They are tagged <code>source = 'demo'</code> and are
                clearly marked as <span class="badge text-bg-warning">sample</span> in the history
                tables. Remove them once your ESP32 is sending real readings.
            </p>
            <ul class="small text-muted">
                <li><?= e(number_format($demoReadings)) ?> sensor readings</li>
                <li><?= e(number_format($demoCycles)) ?> irrigation cycles</li>
                <li><?= e(number_format($demoAlerts)) ?> alerts</li>
            </ul>
            <form method="post" action="<?= e(url('logs/purge-demo')) ?>" class="m-0"
                  data-confirm="Delete all sample data? Readings and logs recorded by real devices are not affected.">
                <?= csrf_field() ?>
                <button class="btn btn-sm btn-outline-danger" type="submit">
                    <?= icon('trash', 15) ?> Remove sample data
                </button>
                <span class="ag-field-help ms-2">Live device data is left untouched.</span>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="ag-card">
    <div class="ag-card-head">
        <h2 class="ag-card-title"><?= icon('logs', 16) ?> Audit trail</h2>
        <a class="btn btn-sm btn-outline-ag ms-auto" href="<?= e(url('logs/export', $exportQuery)) ?>">
            <?= icon('download', 15) ?> Export CSV
        </a>
    </div>

    <div class="ag-card-body border-bottom ag-no-print">
        <form method="get" action="<?= e(url('logs')) ?>" class="row g-2 align-items-end">

            <div class="col-12 col-md">
                <label class="form-label" for="searchInput">Search</label>
                <input type="search" class="form-control form-control-sm" id="searchInput" name="search"
                       value="<?= e($filters['search']) ?>" placeholder="Description, action or user">
            </div>

            <?php require APP_ROOT . '/views/layout/range_filter.php'; ?>

            <div class="col-sm-auto">
                <label class="form-label" for="actionFilter">Action</label>
                <select class="form-select form-select-sm" id="actionFilter" name="action_filter">
                    <option value="">Any</option>
                    <?php foreach ($actions as $a): ?>
                        <option value="<?= e($a) ?>" <?= $filters['action'] === $a ? 'selected' : '' ?>>
                            <?= e(str_replace('_', ' ', $a)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-sm-auto">
                <label class="form-label" for="userFilter">User</label>
                <select class="form-select form-select-sm" id="userFilter" name="user_id">
                    <option value="">Anyone</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= e((string) $u['id']) ?>"
                            <?= (int) ($filters['user_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>>
                            <?= e($u['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-sm-auto d-flex gap-2">
                <button class="btn btn-sm btn-ag" type="submit"><?= icon('search', 15) ?> Apply</button>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('logs')) ?>">Reset</a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table ag-table table-hover mb-0">
            <thead>
            <tr>
                <th>Date / time</th>
                <th>User</th>
                <th>Action</th>
                <th>Description</th>
                <th>IP address</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="5"><div class="ag-empty">No log entries match these filters.</div></td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    // Security-relevant actions are worth spotting at a glance.
                    $flagged = in_array($row['action'], [
                        'login_failed', 'access_denied', 'api_unauthorized',
                        'api_forbidden', 'login_blocked', 'device_delete', 'user_delete',
                    ], true);
                    ?>
                    <tr>
                        <td class="text-nowrap small"><?= e(fmt_datetime($row['created_at'], 'd M Y, g:i:s A')) ?></td>
                        <td class="small">
                            <?php if ($row['user_name']): ?>
                                <?= e($row['user_name']) ?>
                                <span class="text-muted">@<?= e($row['username']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">system / anonymous</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge text-bg-<?= $flagged ? 'danger' : 'light' ?> border">
                                <?= e(str_replace('_', ' ', $row['action'])) ?>
                            </span>
                        </td>
                        <td class="small"><?= e($row['description'] ?? '') ?></td>
                        <td class="small text-muted"><?= e($row['ip_address'] ?? '--') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require APP_ROOT . '/views/layout/pagination.php'; ?>
</div>
