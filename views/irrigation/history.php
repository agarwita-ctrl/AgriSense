<?php
/**
 * AgriSense - Irrigation history (requirement 13)
 *
 * @var array $devices
 * @var array $device
 * @var array $rows
 * @var array $meta
 * @var array $filters
 * @var array $range
 * @var array $stats
 * @var array $activity
 */
$exportQuery = array_merge($_GET, ['device' => (int) $device['id']]);
unset($exportQuery['page'], $exportQuery['r']);
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <?php require APP_ROOT . '/views/layout/device_select.php'; ?>
    <a class="btn btn-sm btn-outline-ag" href="<?= e(url('irrigation/export', $exportQuery)) ?>">
        <?= icon('download', 16) ?> Export CSV
    </a>
</div>

<!-- ============ Summary ============ -->
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="ag-metric is-pump">
            <div class="ag-metric-label">Irrigation cycles</div>
            <div class="ag-metric-value" style="font-size:1.6rem;"><?= e(number_format($stats['cycles'])) ?></div>
            <div class="ag-metric-note">
                <?= e(number_format($stats['automatic_cycles'])) ?> automatic,
                <?= e(number_format($stats['manual_cycles'])) ?> manual
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ag-metric is-moisture">
            <div class="ag-metric-label">Total pump runtime</div>
            <div class="ag-metric-value" style="font-size:1.6rem;"><?= e(fmt_duration($stats['total_seconds'])) ?></div>
            <div class="ag-metric-note">across the selected period</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ag-metric">
            <div class="ag-metric-label">Average cycle</div>
            <div class="ag-metric-value" style="font-size:1.6rem;"><?= e(fmt_duration($stats['avg_seconds'])) ?></div>
            <div class="ag-metric-note">per irrigation event</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ag-metric">
            <div class="ag-metric-label">Longest cycle</div>
            <div class="ag-metric-value" style="font-size:1.6rem;"><?= e(fmt_duration($stats['longest_seconds'])) ?></div>
            <div class="ag-metric-note">single run</div>
        </div>
    </div>
</div>

<!-- ============ Activity chart ============ -->
<div class="ag-card mb-3">
    <div class="ag-card-head">
        <h2 class="ag-card-title"><?= icon('water', 16) ?> Irrigation activity</h2>
    </div>
    <div class="ag-card-body">
        <?php if (!$activity): ?>
            <div class="ag-empty">No irrigation happened in this period.</div>
        <?php else: ?>
            <div class="ag-chart"><canvas id="chartActivity"></canvas></div>
        <?php endif; ?>
    </div>
</div>

<!-- ============ Filters + table ============ -->
<div class="ag-card">
    <div class="ag-card-head">
        <h2 class="ag-card-title"><?= icon('logs', 16) ?> Irrigation log</h2>
    </div>

    <div class="ag-card-body border-bottom ag-no-print">
        <form method="get" action="<?= e(url('irrigation/history')) ?>" class="row g-2 align-items-end">
            <input type="hidden" name="device" value="<?= e((string) $device['id']) ?>">

            <div class="col-12 col-md">
                <label class="form-label" for="searchInput">Search</label>
                <input type="search" class="form-control form-control-sm" id="searchInput" name="search"
                       value="<?= e($filters['search']) ?>" placeholder="Operator, device or stop reason">
            </div>

            <?php require APP_ROOT . '/views/layout/range_filter.php'; ?>

            <div class="col-sm-auto">
                <label class="form-label" for="triggerFilter">Trigger</label>
                <select class="form-select form-select-sm" id="triggerFilter" name="trigger_type">
                    <option value="">Any</option>
                    <option value="automatic" <?= $filters['trigger_type'] === 'automatic' ? 'selected' : '' ?>>Automatic</option>
                    <option value="manual"    <?= $filters['trigger_type'] === 'manual' ? 'selected' : '' ?>>Manual</option>
                </select>
            </div>

            <div class="col-sm-auto">
                <label class="form-label" for="reasonFilter">Stopped by</label>
                <select class="form-select form-select-sm" id="reasonFilter" name="stop_reason">
                    <option value="">Any</option>
                    <?php foreach (IrrigationLog::STOP_REASONS as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $filters['stop_reason'] === $key ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-sm-auto d-flex gap-2">
                <button class="btn btn-sm btn-ag" type="submit"><?= icon('search', 15) ?> Apply</button>
                <a class="btn btn-sm btn-outline-secondary"
                   href="<?= e(url('irrigation/history', ['device' => (int) $device['id']])) ?>">Reset</a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table ag-table table-hover mb-0">
            <thead>
            <tr>
                <th>ID</th>
                <th>Date</th>
                <th>Start</th>
                <th>End</th>
                <th class="num">Duration</th>
                <th>Trigger</th>
                <th class="num">Moisture</th>
                <th class="num">Threshold</th>
                <th>Status</th>
                <th>Operator</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="10">
                        <div class="ag-empty">
                            No irrigation records match these filters.<br>
                            <span class="small">Cycles appear here as soon as the pump runs.</span>
                        </div>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <?php $running = $row['ended_at'] === null; ?>
                    <tr<?= $running ? ' class="table-success"' : '' ?>>
                        <td class="text-muted small">#<?= e((string) $row['id']) ?></td>
                        <td class="text-nowrap"><?= e(fmt_date($row['started_at'])) ?></td>
                        <td class="text-nowrap"><?= e(fmt_time($row['started_at'])) ?></td>
                        <td class="text-nowrap">
                            <?= $running ? '<span class="text-success fw-semibold">running</span>' : e(fmt_time($row['ended_at'])) ?>
                        </td>
                        <td class="num">
                            <?= $running ? '--' : e(fmt_duration((int) $row['duration_seconds'])) ?>
                        </td>
                        <td>
                            <span class="ag-pill <?= $row['trigger_type'] === 'manual' ? 'ag-pill-manual' : 'ag-pill-auto' ?>">
                                <?= e(ucfirst($row['trigger_type'])) ?>
                            </span>
                        </td>
                        <td class="num">
                            <?= e(fmt_num($row['soil_moisture'], 1, '%')) ?>
                            <?php if ($row['end_moisture'] !== null): ?>
                                <span class="text-muted small">&rarr; <?= e(fmt_num($row['end_moisture'], 1, '%')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="num text-muted"><?= e(fmt_num($row['threshold'], 1, '%')) ?></td>
                        <td>
                            <?php if ($running): ?>
                                <span class="ag-pill ag-pill-on"><span class="ag-pill-dot"></span>Pump ON</span>
                            <?php else: ?>
                                <span class="ag-pill ag-pill-off">Completed</span>
                                <?php if ($row['stop_reason']): ?>
                                    <div class="text-muted" style="font-size:.72rem;">
                                        <?= e(IrrigationLog::STOP_REASONS[$row['stop_reason']] ?? $row['stop_reason']) ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= e($row['operator'] ?? '--') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require APP_ROOT . '/views/layout/pagination.php'; ?>
</div>

<?php if ($activity): ?>
<script type="application/json" id="activityData"><?= json_encode($activity, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script <?= csp_attr() ?>>
    document.addEventListener('DOMContentLoaded', function () {
        window.AgriSense.charts.activity(
            document.getElementById('chartActivity'),
            JSON.parse(document.getElementById('activityData').textContent)
        );
    });
</script>
<?php endif; ?>
