<?php
/**
 * AgriSense - Reports (requirement 24)
 *
 * Three report types, all filterable by device and date range, all
 * printable and exportable to CSV.
 *
 * @var array      $devices
 * @var array|null $device
 * @var string     $type
 * @var array      $range
 * @var array      $report   ['rows' => ..., 'summary' => ...]
 * @var bool       $print
 */
$rows    = $report['rows'];
$summary = $report['summary'];

$baseQuery = array_filter([
    'type'   => $type,
    'range'  => $range['range'],
    'from'   => $range['range'] === 'custom' ? $range['from'] : null,
    'to'     => $range['range'] === 'custom' ? $range['to'] : null,
    'device' => $device ? (int) $device['id'] : null,
]);

$rangeLabel = match ($range['range']) {
    'today'     => 'Today',
    'yesterday' => 'Yesterday',
    '30days'    => 'Last 30 days',
    'custom'    => fmt_date($range['from']) . ' to ' . fmt_date($range['to']),
    default     => 'Last 7 days',
};
?>

<!-- Only visible on paper -->
<div class="ag-print-header mb-3">
    <h2 class="h5 mb-1"><?= e(APP_NAME) ?> &mdash; <?= e(ReportController::TYPES[$type]) ?></h2>
    <p class="small mb-0">
        Period: <?= e($rangeLabel) ?>
        (<?= e(fmt_datetime($range['start'])) ?> &ndash; <?= e(fmt_datetime($range['end'])) ?>)<br>
        <?php if ($device): ?>
            Device: <?= e($device['device_name']) ?> (<?= e($device['device_code']) ?>)<br>
        <?php endif; ?>
        Generated <?= e(fmt_datetime(date('Y-m-d H:i:s'))) ?> by <?= e(Auth::user()['name']) ?>
    </p>
    <hr>
</div>

<!-- ============ Controls ============ -->
<div class="ag-card mb-3 ag-no-print">
    <div class="ag-card-body">
        <form method="get" action="<?= e(url('reports')) ?>" class="row g-2 align-items-end">

            <div class="col-12 col-md-auto">
                <label class="form-label" for="typeSelect">Report</label>
                <select class="form-select form-select-sm" id="typeSelect" name="type" data-autosubmit>
                    <?php foreach (ReportController::TYPES as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $type === $key ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($type !== 'device'): ?>
                <div class="col-12 col-md-auto">
                    <label class="form-label" for="deviceSelectReport">Device</label>
                    <select class="form-select form-select-sm" id="deviceSelectReport" name="device">
                        <?php foreach ($devices as $d): ?>
                            <option value="<?= e((string) $d['id']) ?>"
                                <?= (int) $d['id'] === (int) ($device['id'] ?? 0) ? 'selected' : '' ?>>
                                <?= e($d['device_name']) ?> (<?= e($d['device_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php require APP_ROOT . '/views/layout/range_filter.php'; ?>
            <?php endif; ?>

            <div class="col-12 col-md-auto d-flex gap-2">
                <button class="btn btn-sm btn-ag" type="submit"><?= icon('refresh', 15) ?> Generate</button>
                <a class="btn btn-sm btn-outline-ag" href="<?= e(url('reports/export', $baseQuery)) ?>">
                    <?= icon('download', 15) ?> CSV
                </a>
                <button class="btn btn-sm btn-outline-secondary" type="button" onclick="window.print()">
                    <?= icon('print', 15) ?> Print
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============ Report body ============ -->
<?php if ($type === 'sensor'): ?>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="ag-metric is-moisture">
                <div class="ag-metric-label">Average soil moisture</div>
                <div class="ag-metric-value" style="font-size:1.6rem;">
                    <?= e(fmt_num($summary['avg_moisture'] ?? null, 1, '%')) ?>
                </div>
                <div class="ag-metric-note">
                    range <?= e(fmt_num($summary['min_moisture'] ?? null, 1)) ?>
                    &ndash; <?= e(fmt_num($summary['max_moisture'] ?? null, 1, '%')) ?>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="ag-metric is-temp">
                <div class="ag-metric-label">Average temperature</div>
                <div class="ag-metric-value" style="font-size:1.6rem;">
                    <?= e(fmt_num($summary['avg_temperature'] ?? null, 1, '°C')) ?>
                </div>
                <div class="ag-metric-note">
                    range <?= e(fmt_num($summary['min_temperature'] ?? null, 1)) ?>
                    &ndash; <?= e(fmt_num($summary['max_temperature'] ?? null, 1, '°C')) ?>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <?php if ((int) ($device['has_mlx90614'] ?? 0) === 1): ?>
                <!--
                    An MLX90614 board reports no humidity at all, so this tile
                    carries the canopy instead of a permanently blank figure.
                -->
                <div class="ag-metric is-temp">
                    <div class="ag-metric-label">Average canopy temperature</div>
                    <div class="ag-metric-value" style="font-size:1.6rem;">
                        <?= e(fmt_num($summary['avg_leaf_temperature'] ?? null, 1, '°C')) ?>
                    </div>
                    <div class="ag-metric-note">
                        above air by <?= e(fmt_num($summary['avg_leaf_delta'] ?? null, 1, '°C')) ?>
                        &middot; peak gap <?= e(fmt_num($summary['max_leaf_delta'] ?? null, 1, '°C')) ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="ag-metric is-humidity">
                    <div class="ag-metric-label">Average humidity</div>
                    <div class="ag-metric-value" style="font-size:1.6rem;">
                        <?= e(fmt_num($summary['avg_humidity'] ?? null, 1, '%')) ?>
                    </div>
                    <div class="ag-metric-note">
                        range <?= e(fmt_num($summary['min_humidity'] ?? null, 1)) ?>
                        &ndash; <?= e(fmt_num($summary['max_humidity'] ?? null, 1, '%')) ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <div class="col-6 col-lg-3">
            <div class="ag-metric">
                <div class="ag-metric-label">Readings taken</div>
                <div class="ag-metric-value" style="font-size:1.6rem;">
                    <?= e(number_format((int) ($summary['samples'] ?? 0))) ?>
                </div>
                <div class="ag-metric-note">
                    <?= e(number_format((int) ($summary['failed_reads'] ?? 0))) ?> with a failed sensor read
                </div>
            </div>
        </div>
    </div>

    <div class="ag-card mb-3">
        <div class="ag-card-head"><h2 class="ag-card-title">Trend</h2></div>
        <div class="ag-card-body">
            <?php if (!$rows): ?>
                <div class="ag-empty">No readings in this period.</div>
            <?php else: ?>
                <div class="ag-chart"><canvas id="chartReport"></canvas></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="ag-card">
        <div class="ag-card-head"><h2 class="ag-card-title">Readings by period</h2></div>
        <div class="table-responsive">
            <table class="table ag-table table-sm mb-0">
                <thead>
                <tr>
                    <th>Period</th>
                    <th class="num">Soil moisture</th>
                    <th class="num">Temperature</th>
                    <th class="num">Humidity</th>
                    <th class="num">Samples</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="5"><div class="ag-empty">Nothing to report for this period.</div></td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="text-nowrap"><?= e(fmt_datetime($row['bucket'], 'd M Y, g:i A')) ?></td>
                            <td class="num"><?= e(fmt_num($row['soil_moisture'], 1, '%')) ?></td>
                            <td class="num"><?= e(fmt_num($row['temperature'], 1, '°C')) ?></td>
                            <td class="num"><?= e(fmt_num($row['humidity'], 1, '%')) ?></td>
                            <td class="num text-muted"><?= e((string) $row['samples']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($type === 'irrigation'): ?>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="ag-metric is-pump">
                <div class="ag-metric-label">Irrigation cycles</div>
                <div class="ag-metric-value" style="font-size:1.6rem;"><?= e(number_format($summary['cycles'])) ?></div>
                <div class="ag-metric-note">
                    <?= e((string) $summary['automatic_cycles']) ?> automatic,
                    <?= e((string) $summary['manual_cycles']) ?> manual
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="ag-metric is-moisture">
                <div class="ag-metric-label">Total runtime</div>
                <div class="ag-metric-value" style="font-size:1.6rem;"><?= e(fmt_duration($summary['total_seconds'])) ?></div>
                <div class="ag-metric-note">pump on time</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="ag-metric">
                <div class="ag-metric-label">Average cycle</div>
                <div class="ag-metric-value" style="font-size:1.6rem;"><?= e(fmt_duration($summary['avg_seconds'])) ?></div>
                <div class="ag-metric-note">per event</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="ag-metric">
                <div class="ag-metric-label">Longest cycle</div>
                <div class="ag-metric-value" style="font-size:1.6rem;"><?= e(fmt_duration($summary['longest_seconds'])) ?></div>
                <div class="ag-metric-note">single run</div>
            </div>
        </div>
    </div>

    <div class="ag-card">
        <div class="ag-card-head"><h2 class="ag-card-title">Irrigation events</h2></div>
        <div class="table-responsive">
            <table class="table ag-table table-sm mb-0">
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Start</th>
                    <th>End</th>
                    <th class="num">Duration</th>
                    <th>Trigger</th>
                    <th class="num">Soil moisture</th>
                    <th class="num">Threshold</th>
                    <th>Stopped by</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="8"><div class="ag-empty">No irrigation in this period.</div></td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="text-nowrap"><?= e(fmt_date($row['started_at'])) ?></td>
                            <td class="text-nowrap"><?= e(fmt_time($row['started_at'])) ?></td>
                            <td class="text-nowrap">
                                <?= $row['ended_at'] ? e(fmt_time($row['ended_at'])) : '<em>running</em>' ?>
                            </td>
                            <td class="num">
                                <?= $row['duration_seconds'] === null ? '--' : e(fmt_duration((int) $row['duration_seconds'])) ?>
                            </td>
                            <td><?= e(ucfirst($row['trigger_type'])) ?></td>
                            <td class="num"><?= e(fmt_num($row['soil_moisture'], 1, '%')) ?></td>
                            <td class="num"><?= e(fmt_num($row['threshold'], 1, '%')) ?></td>
                            <td class="small">
                                <?= e($row['stop_reason']
                                    ? (IrrigationLog::STOP_REASONS[$row['stop_reason']] ?? $row['stop_reason'])
                                    : '--') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php else: ?>

    <div class="row g-3 mb-3">
        <div class="col-4">
            <div class="ag-metric is-device">
                <div class="ag-metric-label">Devices</div>
                <div class="ag-metric-value" style="font-size:1.6rem;"><?= e((string) $summary['devices']) ?></div>
                <div class="ag-metric-note">registered</div>
            </div>
        </div>
        <div class="col-4">
            <div class="ag-metric is-pump">
                <div class="ag-metric-label">Online</div>
                <div class="ag-metric-value" style="font-size:1.6rem;"><?= e((string) $summary['online']) ?></div>
                <div class="ag-metric-note">reporting now</div>
            </div>
        </div>
        <div class="col-4">
            <div class="ag-metric<?= $summary['offline'] > 0 ? ' is-critical' : '' ?>">
                <div class="ag-metric-label">Offline</div>
                <div class="ag-metric-value" style="font-size:1.6rem;"><?= e((string) $summary['offline']) ?></div>
                <div class="ag-metric-note">need attention</div>
            </div>
        </div>
    </div>

    <div class="ag-card">
        <div class="ag-card-head"><h2 class="ag-card-title">Device inventory</h2></div>
        <div class="table-responsive">
            <table class="table ag-table table-sm mb-0">
                <thead>
                <tr>
                    <th>Device</th>
                    <th>Code</th>
                    <th>Location</th>
                    <th>Status</th>
                    <th>Connectivity</th>
                    <th>Last seen</th>
                    <th class="num">Readings</th>
                    <th class="num">Cycles</th>
                    <th class="num">Unread alerts</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="9"><div class="ag-empty">No devices registered.</div></td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($row['device_name']) ?></td>
                            <td><code class="small"><?= e($row['device_code']) ?></code></td>
                            <td class="small"><?= e($row['location'] ?: '--') ?></td>
                            <td><?= e(ucfirst($row['status'])) ?></td>
                            <td>
                                <span class="ag-pill <?= (int) $row['is_online'] === 1 ? 'ag-pill-online' : 'ag-pill-offline' ?>">
                                    <?= (int) $row['is_online'] === 1 ? 'Online' : 'Offline' ?>
                                </span>
                            </td>
                            <td class="small text-nowrap"><?= e(time_ago($row['last_seen'])) ?></td>
                            <td class="num"><?= e(number_format((int) $row['readings'])) ?></td>
                            <td class="num"><?= e(number_format((int) $row['cycles'])) ?></td>
                            <td class="num"><?= e(number_format((int) $row['unread_alerts'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>

<?php if ($type === 'sensor' && $rows): ?>
<script type="application/json" id="reportSeries"><?= json_encode($rows, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script <?= csp_attr() ?>>
    document.addEventListener('DOMContentLoaded', function () {
        var rows = JSON.parse(document.getElementById('reportSeries').textContent);
        var p = window.AgriSense.charts.palette;
        var labels = rows.map(function (r) {
            var d = new Date(String(r.bucket).replace(' ', 'T'));
            return isNaN(d) ? r.bucket
                : d.toLocaleDateString(undefined, { day: '2-digit', month: 'short' })
                  + ' ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
        });

        new Chart(document.getElementById('chartReport'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Soil moisture (%)', borderColor: p.moisture, backgroundColor: p.moisture,
                      data: rows.map(function (r) { return r.soil_moisture === null ? null : Number(r.soil_moisture); }),
                      borderWidth: 2, tension: .3, pointRadius: 0, yAxisID: 'y' },
                    { label: 'Humidity (%)', borderColor: p.humidity, backgroundColor: p.humidity,
                      data: rows.map(function (r) { return r.humidity === null ? null : Number(r.humidity); }),
                      borderWidth: 2, tension: .3, pointRadius: 0, borderDash: [5, 3], yAxisID: 'y' },
                    { label: 'Temperature (°C)', borderColor: p.temp, backgroundColor: p.temp,
                      data: rows.map(function (r) { return r.temperature === null ? null : Number(r.temperature); }),
                      borderWidth: 2, tension: .3, pointRadius: 0, yAxisID: 'y1' }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false, animation: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'top', align: 'end',
                    labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, font: { size: 11 } } } },
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 10, font: { size: 10 } } },
                    y: { position: 'left', min: 0, max: 100 },
                    y1: { position: 'right', grid: { drawOnChartArea: false } }
                }
            }
        });
    });
</script>
<?php endif; ?>

<?php if ($print): ?>
<script <?= csp_attr() ?>>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
<?php endif; ?>
