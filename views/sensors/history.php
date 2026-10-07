<?php
/**
 * AgriSense - Sensor history (requirement 14)
 *
 * @var array $devices
 * @var array $device
 * @var array $rows
 * @var array $meta
 * @var array $filters
 * @var array $range
 * @var array $series
 * @var array $stats
 */

/** Build a sort link that keeps the current filters and flips the direction. */
$sortLink = static function (string $column, string $label) use ($filters): string {
    $query = $_GET;
    $query['sort'] = $column;
    $query['dir']  = ($filters['sort'] === $column && $filters['dir'] === 'asc') ? 'desc' : 'asc';
    unset($query['page'], $query['r']);

    $arrow = '';
    if ($filters['sort'] === $column) {
        $arrow = $filters['dir'] === 'asc' ? ' &uarr;' : ' &darr;';
    }

    return '<a href="' . e(url('sensors/history', $query)) . '">' . e($label) . $arrow . '</a>';
};

$exportQuery = array_merge($_GET, ['device' => (int) $device['id']]);
unset($exportQuery['page'], $exportQuery['r']);
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <?php require APP_ROOT . '/views/layout/device_select.php'; ?>
    <a class="btn btn-sm btn-outline-ag" href="<?= e(url('sensors/export', $exportQuery)) ?>">
        <?= icon('download', 16) ?> Export CSV
    </a>
</div>

<!-- ============ Summary ============ -->
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="ag-metric is-moisture">
            <div class="ag-metric-label">Soil moisture</div>
            <div class="ag-metric-value" style="font-size:1.6rem;">
                <?= e(fmt_num($stats['avg_moisture'] ?? null, 1, '%')) ?>
            </div>
            <div class="ag-metric-note">
                avg &middot; min <?= e(fmt_num($stats['min_moisture'] ?? null, 1, '%')) ?>
                &middot; max <?= e(fmt_num($stats['max_moisture'] ?? null, 1, '%')) ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ag-metric is-temp">
            <div class="ag-metric-label">Temperature</div>
            <div class="ag-metric-value" style="font-size:1.6rem;">
                <?= e(fmt_num($stats['avg_temperature'] ?? null, 1, '°C')) ?>
            </div>
            <div class="ag-metric-note">
                avg &middot; min <?= e(fmt_num($stats['min_temperature'] ?? null, 1, '°C')) ?>
                &middot; max <?= e(fmt_num($stats['max_temperature'] ?? null, 1, '°C')) ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <?php if ((int) ($device['has_mlx90614'] ?? 0) === 1): ?>
            <!--
                Canopy takes this slot on an MLX90614 board. The average gap
                over the range says more than the average canopy temperature
                does: it is the part that tracks how thirsty the crop was.
            -->
            <div class="ag-metric is-temp">
                <div class="ag-metric-label">Canopy temperature</div>
                <div class="ag-metric-value" style="font-size:1.6rem;">
                    <?= e(fmt_num($stats['avg_leaf_temperature'] ?? null, 1, '°C')) ?>
                </div>
                <div class="ag-metric-note">
                    avg &middot; above air by
                    <?= e(fmt_num($stats['avg_leaf_delta'] ?? null, 1, '°C')) ?>
                    &middot; peak gap <?= e(fmt_num($stats['max_leaf_delta'] ?? null, 1, '°C')) ?>
                </div>
            </div>
        <?php else: ?>
            <div class="ag-metric is-humidity">
                <div class="ag-metric-label">Humidity</div>
                <div class="ag-metric-value" style="font-size:1.6rem;">
                    <?= e(fmt_num($stats['avg_humidity'] ?? null, 1, '%')) ?>
                </div>
                <div class="ag-metric-note">
                    avg &middot; min <?= e(fmt_num($stats['min_humidity'] ?? null, 1, '%')) ?>
                    &middot; max <?= e(fmt_num($stats['max_humidity'] ?? null, 1, '%')) ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ag-metric<?= (int) ($stats['failed_reads'] ?? 0) > 0 ? ' is-critical' : '' ?>">
            <div class="ag-metric-label">Readings</div>
            <div class="ag-metric-value" style="font-size:1.6rem;">
                <?= e(number_format((int) ($stats['samples'] ?? 0))) ?>
            </div>
            <div class="ag-metric-note">
                <?php $failed = (int) ($stats['failed_reads'] ?? 0); ?>
                <?= $failed > 0
                    ? e(number_format($failed)) . ' with a failed sensor read'
                    : 'all sensors reported successfully' ?>
            </div>
        </div>
    </div>
</div>

<!-- ============ Chart ============ -->
<div class="ag-card mb-3">
    <div class="ag-card-head">
        <h2 class="ag-card-title"><?= icon('sensor', 16) ?> Trends for the selected period</h2>
    </div>
    <div class="ag-card-body">
        <?php if (!$series): ?>
            <div class="ag-empty">No readings in this period.</div>
        <?php else: ?>
            <div class="ag-chart ag-chart-lg"><canvas id="chartSensors"></canvas></div>
            <?php if (count($series) < 2): ?>
                <p class="text-muted small mt-2 mb-0">
                    Only one reading falls in this period, so there is no trend to draw yet.
                    Pick a longer period, or wait for the device to report again.
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ============ Filters + table ============ -->
<div class="ag-card">
    <div class="ag-card-head">
        <h2 class="ag-card-title"><?= icon('logs', 16) ?> Reading log</h2>
    </div>

    <div class="ag-card-body border-bottom ag-no-print">
        <form method="get" action="<?= e(url('sensors/history')) ?>" class="row g-2 align-items-end">
            <input type="hidden" name="device" value="<?= e((string) $device['id']) ?>">
            <input type="hidden" name="sort" value="<?= e($filters['sort']) ?>">
            <input type="hidden" name="dir" value="<?= e($filters['dir']) ?>">

            <div class="col-12 col-md">
                <label class="form-label" for="searchInput">Search</label>
                <input type="search" class="form-control form-control-sm" id="searchInput" name="search"
                       value="<?= e($filters['search']) ?>" placeholder="Device or date, e.g. 2026-09-05">
            </div>

            <?php require APP_ROOT . '/views/layout/range_filter.php'; ?>

            <div class="col-sm-auto">
                <label class="form-label" for="pumpFilter">Pump</label>
                <select class="form-select form-select-sm" id="pumpFilter" name="pump_status">
                    <option value="">Any</option>
                    <option value="ON"  <?= $filters['pump_status'] === 'ON' ? 'selected' : '' ?>>Running</option>
                    <option value="OFF" <?= $filters['pump_status'] === 'OFF' ? 'selected' : '' ?>>Idle</option>
                </select>
            </div>

            <div class="col-sm-auto">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" value="1" id="onlyFailed"
                           name="only_failed" <?= $filters['only_failed'] ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="onlyFailed">Failed reads only</label>
                </div>
            </div>

            <div class="col-sm-auto d-flex gap-2">
                <button class="btn btn-sm btn-ag" type="submit"><?= icon('search', 15) ?> Apply</button>
                <a class="btn btn-sm btn-outline-secondary"
                   href="<?= e(url('sensors/history', ['device' => (int) $device['id']])) ?>">Reset</a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table ag-table table-hover mb-0">
            <thead>
            <?php
            // Which columns this board can fill. Air temperature comes from
            // either sensor; humidity needs the DHT22, canopy the MLX90614.
            $twoProbes = (int) ($device['probe_count'] ?? 1) >= 2;
            $hasDht    = (int) ($device['has_dht22'] ?? 1) === 1;
            $hasMlx    = (int) ($device['has_mlx90614'] ?? 0) === 1;
            $showTemp  = $hasDht || $hasMlx;
            $columns   = 5 + ($twoProbes ? 2 : 0) + ($showTemp ? 1 : 0)
                           + ($hasMlx ? 1 : 0) + ($hasDht ? 1 : 0);
            ?>
            <tr>
                <th><?= $sortLink('recorded_at', 'Date / time') ?></th>
                <th class="num"><?= $sortLink('soil_moisture', $twoProbes ? 'Soil (avg)' : 'Soil moisture') ?></th>
                <?php if ($twoProbes): ?>
                    <th class="num"><?= $sortLink('soil_moisture_1', 'Probe 1') ?></th>
                    <th class="num"><?= $sortLink('soil_moisture_2', 'Probe 2') ?></th>
                <?php endif; ?>
                <th class="num">Raw ADC</th>
                <?php if ($showTemp): ?>
                    <th class="num"><?= $sortLink('temperature', $hasMlx ? 'Air' : 'Temperature') ?></th>
                <?php endif; ?>
                <?php if ($hasMlx): ?>
                    <th class="num"><?= $sortLink('leaf_temperature', 'Canopy') ?></th>
                <?php endif; ?>
                <?php if ($hasDht): ?>
                    <th class="num"><?= $sortLink('humidity', 'Humidity') ?></th>
                <?php endif; ?>
                <th>Pump</th>
                <th>Mode</th>
                <th>Source</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="<?= e((string) $columns) ?>">
                        <div class="ag-empty">
                            No readings match these filters.<br>
                            <span class="small">Try widening the date range, or clear the filters.</span>
                        </div>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="text-nowrap"><?= e(fmt_datetime($row['recorded_at'], 'd M Y, g:i:s A')) ?></td>
                        <td class="num<?= $row['soil_moisture'] === null ? ' text-danger' : '' ?>">
                            <?= $row['soil_moisture'] === null ? 'failed' : e(fmt_num($row['soil_moisture'], 1, '%')) ?>
                        </td>
                        <?php if ($twoProbes): ?>
                            <td class="num<?= $row['soil_moisture_1'] === null ? ' text-danger' : '' ?>">
                                <?= $row['soil_moisture_1'] === null ? 'failed' : e(fmt_num($row['soil_moisture_1'], 1, '%')) ?>
                            </td>
                            <td class="num<?= $row['soil_moisture_2'] === null ? ' text-danger' : '' ?>">
                                <?= $row['soil_moisture_2'] === null ? 'failed' : e(fmt_num($row['soil_moisture_2'], 1, '%')) ?>
                            </td>
                        <?php endif; ?>
                        <td class="num text-muted">
                            <?= e($row['soil_raw'] ?? '--') ?><?php if ($twoProbes): ?>
                                <span class="small">/ <?= e($row['soil_raw_2'] ?? '--') ?></span>
                            <?php endif; ?>
                        </td>
                        <?php if ($showTemp): ?>
                            <td class="num<?= $row['temperature'] === null ? ' text-danger' : '' ?>">
                                <?= $row['temperature'] === null ? 'failed' : e(fmt_num($row['temperature'], 1, '°C')) ?>
                            </td>
                        <?php endif; ?>
                        <?php if ($hasMlx): ?>
                            <?php
                            // A canopy well above the air is the reading that
                            // matters, so the row carries the gap and marks it
                            // when it crosses the stress threshold.
                            $leafDelta = ($row['leaf_temperature'] !== null && $row['temperature'] !== null)
                                ? (float) $row['leaf_temperature'] - (float) $row['temperature']
                                : null;
                            ?>
                            <td class="num<?= $row['leaf_temperature'] === null ? ' text-danger'
                                : ($leafDelta !== null && $leafDelta >= LEAF_STRESS_DELTA ? ' text-warning-emphasis fw-semibold' : '') ?>">
                                <?php if ($row['leaf_temperature'] === null): ?>
                                    failed
                                <?php else: ?>
                                    <?= e(fmt_num($row['leaf_temperature'], 1, '°C')) ?>
                                    <?php if ($leafDelta !== null): ?>
                                        <span class="small text-muted">
                                            <?= $leafDelta >= 0 ? '+' : '' ?><?= e(number_format($leafDelta, 1)) ?>
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <?php if ($hasDht): ?>
                            <td class="num<?= $row['humidity'] === null ? ' text-danger' : '' ?>">
                                <?= $row['humidity'] === null ? 'failed' : e(fmt_num($row['humidity'], 1, '%')) ?>
                            </td>
                        <?php endif; ?>
                        <td>
                            <span class="ag-pill <?= $row['pump_status'] === 'ON' ? 'ag-pill-on' : 'ag-pill-off' ?>">
                                <?= e($row['pump_status']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="ag-pill <?= $row['mode'] === 'AUTO' ? 'ag-pill-auto' : 'ag-pill-manual' ?>">
                                <?= e($row['mode']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($row['source'] === 'demo'): ?>
                                <span class="badge text-bg-warning">sample</span>
                            <?php else: ?>
                                <span class="text-muted small">device</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require APP_ROOT . '/views/layout/pagination.php'; ?>
</div>

<?php if ($series): ?>
<script type="application/json" id="sensorSeries"><?= json_encode($series, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script <?= csp_attr() ?>>
    // One combined chart: moisture and humidity on the left axis (both are
    // percentages), air and canopy temperature together on the right, so the
    // gap between those two lines stays readable.
    document.addEventListener('DOMContentLoaded', function () {
        var rows = JSON.parse(document.getElementById('sensorSeries').textContent);
        var span = rows.length > 1
            ? (new Date(rows[rows.length - 1].bucket.replace(' ', 'T')) - new Date(rows[0].bucket.replace(' ', 'T'))) / 86400000
            : 1;

        // Time alone is enough inside one day; beyond that every label carries
        // its date, otherwise "02:00 PM" repeats with no way to tell the days apart.
        var labels = rows.map(function (r) {
            var d = new Date(String(r.bucket).replace(' ', 'T'));
            if (isNaN(d)) return r.bucket;
            var time = d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
            if (span <= 1) return time;
            var day = d.toLocaleDateString(undefined, { day: '2-digit', month: 'short' });
            return span > 8 ? day : day + ' ' + time;
        });

        // Few readings: draw the points too, so the line is easy to follow.
        var sparse = rows.length <= 60;
        var dot = sparse ? 3 : 0;

        var p = window.AgriSense.charts.palette;
        var twoProbes = <?= (int) ($device['probe_count'] ?? 1) >= 2 ? 'true' : 'false' ?>;
        var hasDht    = <?= (int) ($device['has_dht22'] ?? 1) === 1 ? 'true' : 'false' ?>;
        var hasMlx    = <?= (int) ($device['has_mlx90614'] ?? 0) === 1 ? 'true' : 'false' ?>;
        var showTemp  = hasDht || hasMlx;

        var datasets = [
            {
                label: twoProbes ? 'Soil moisture, average (%)' : 'Soil moisture (%)',
                data: rows.map(function (r) { return r.soil_moisture === null ? null : Number(r.soil_moisture); }),
                borderColor: p.moisture, backgroundColor: p.moisture,
                borderWidth: 2, tension: .32, pointRadius: dot, spanGaps: true, yAxisID: 'y'
            }
        ];

        // Each probe as a thin line, so a drifting or dead probe stands out
        // against the average the pump actually acts on.
        if (twoProbes) {
            datasets.push({
                label: 'Probe 1 (%)',
                data: rows.map(function (r) { return r.soil_moisture_1 === null ? null : Number(r.soil_moisture_1); }),
                borderColor: 'rgba(2,136,209,.45)', backgroundColor: 'rgba(2,136,209,.45)',
                borderWidth: 1, tension: .32, pointRadius: dot, spanGaps: true, yAxisID: 'y'
            }, {
                label: 'Probe 2 (%)',
                data: rows.map(function (r) { return r.soil_moisture_2 === null ? null : Number(r.soil_moisture_2); }),
                borderColor: 'rgba(141,110,79,.55)', backgroundColor: 'rgba(141,110,79,.55)',
                borderWidth: 1, tension: .32, pointRadius: dot, spanGaps: true, yAxisID: 'y'
            });
        }

        // Humidity needs a DHT22; it shares the left percentage axis.
        if (hasDht) {
            datasets.push({
                label: 'Humidity (%)',
                data: rows.map(function (r) { return r.humidity === null ? null : Number(r.humidity); }),
                borderColor: p.humidity, backgroundColor: p.humidity,
                borderWidth: 2, tension: .32, pointRadius: dot, spanGaps: true, borderDash: [5, 3], yAxisID: 'y'
            });
        }

        // Air temperature from whichever sensor is fitted, on the right axis.
        if (showTemp) {
            datasets.push({
                label: hasMlx ? 'Air (°C)' : 'Temperature (°C)',
                data: rows.map(function (r) { return r.temperature === null ? null : Number(r.temperature); }),
                borderColor: p.temp, backgroundColor: p.temp,
                borderWidth: 2, tension: .32, pointRadius: dot, spanGaps: true, yAxisID: 'y1'
            });
        }

        // Canopy shares the air temperature axis - the distance between the
        // two lines is the reading worth having.
        if (hasMlx) {
            datasets.push({
                label: 'Canopy (°C)',
                data: rows.map(function (r) { return r.leaf_temperature === null ? null : Number(r.leaf_temperature); }),
                borderColor: p.leaf, backgroundColor: p.leaf,
                borderWidth: 2, tension: .32, pointRadius: dot, spanGaps: true, borderDash: [2, 2], yAxisID: 'y1'
            });
        }

        new Chart(document.getElementById('chartSensors'), {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', align: 'end',
                        labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, font: { size: 11 } } }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 10, maxRotation: 0, autoSkip: true, font: { size: 10 } } },
                    y:  { position: 'left', min: 0, max: 100,
                          title: { display: true, text: 'Percent', font: { size: 10 } } },
                    y1: { position: 'right', grid: { drawOnChartArea: false },
                          title: { display: true, text: '°C', font: { size: 10 } } }
                },
                elements: { point: { hitRadius: 12, hoverRadius: 4 } }
            }
        });
    });
</script>
<?php endif; ?>
