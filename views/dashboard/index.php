<?php
/**
 * AgriSense - Main dashboard (requirements 10, 11, 12)
 *
 * Every value on this page comes from the database via the snapshot the
 * controller built; the JavaScript then keeps them current by polling
 * /api/dashboard. Nothing here is hard coded.
 *
 * @var array $devices
 * @var array $device
 * @var array $snapshot
 * @var array $series
 * @var array $activity
 * @var array $recentAlerts
 * @var array $recentCycles
 * @var bool  $canControlPump
 * @var int   $demoRows
 */
$GLOBALS['PAGE_SCRIPTS'][] = 'js/dashboard.js';

$r        = $snapshot['readings'];
$pump     = $snapshot['pump'];
$dev      = $snapshot['device'];
$settings = $snapshot['settings'];
$pumpOn   = $pump['status'] === 'ON';

$config = [
    'deviceId'        => $dev['id'],
    'readingInterval' => $settings['reading_interval'],
    'settings'        => $settings,
];
?>

<div id="agDashboard" data-config="<?= e(json_encode($config)) ?>">

    <!-- ============ Toolbar ============ -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <?php require APP_ROOT . '/views/layout/device_select.php'; ?>

        <div class="d-flex align-items-center gap-3">
            <span class="ag-live" id="agLiveIndicator">
                <span class="ag-live-dot"></span>
                <span id="agLiveText">Live</span>
            </span>
            <a class="btn btn-sm btn-outline-ag" href="<?= e(url('monitor', ['device' => $dev['id']])) ?>">
                <?= icon('monitor', 16) ?> Monitoring
            </a>
        </div>
    </div>

    <?php if ($demoRows > 0): ?>
        <div class="ag-demo-badge mb-3 d-flex flex-wrap align-items-center gap-2">
            <?= icon('info', 16) ?>
            <span>
                This database still contains <strong><?= e(number_format($demoRows)) ?></strong> sample rows
                from the installer, mixed in with any live data.
            </span>
            <?php if (Auth::isAdmin()): ?>
                <a class="ms-auto small fw-semibold" href="<?= e(url('logs')) ?>">Remove sample data</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!$dev['online']): ?>
        <div class="alert alert-warning d-flex gap-2 align-items-start" role="alert">
            <?= icon('warning', 18) ?>
            <div>
                <strong><?= e($dev['name']) ?> is offline.</strong>
                It last reported <?= e($dev['last_seen_h']) ?>.
                The readings below are the most recent ones on record; irrigation cannot be
                controlled until the device reconnects.
            </div>
        </div>
    <?php endif; ?>

    <!-- ============ Summary cards ============ -->
    <div class="row g-3 mb-3">

        <div class="col-6 col-xl-2">
            <div class="ag-metric is-moisture">
                <div class="ag-metric-label"><?= icon('water', 14) ?> Soil moisture</div>
                <div class="ag-metric-value<?= $r['soil_moisture']['stale'] ? ' ag-stale' : '' ?>" id="liveMoisture">
                    <?php if ($r['soil_moisture']['value'] === null): ?>--<?php else: ?>
                        <?= e(number_format($r['soil_moisture']['value'], 1)) ?><small>%</small>
                    <?php endif; ?>
                </div>
                <div class="ag-progress mt-2">
                    <div id="liveMoistureBar" style="width: <?= e((string) max(0, min(100, (float) ($r['soil_moisture']['value'] ?? 0)))) ?>%;"></div>
                </div>
                <?php if ($dev['probe_count'] >= 2): ?>
                    <div class="ag-probe-row" id="liveProbes">
                        <span class="ag-probe-chip">
                            <span class="ag-probe-label">P1</span>
                            <span class="ag-probe-value<?= $r['soil_moisture_1']['value'] === null ? ' text-danger' : '' ?>"
                                  id="liveProbe1"><?= e(fmt_num($r['soil_moisture_1']['value'], 1, '%')) ?></span>
                        </span>
                        <span class="ag-probe-chip">
                            <span class="ag-probe-label">P2</span>
                            <span class="ag-probe-value<?= $r['soil_moisture_2']['value'] === null ? ' text-danger' : '' ?>"
                                  id="liveProbe2"><?= e(fmt_num($r['soil_moisture_2']['value'], 1, '%')) ?></span>
                        </span>
                    </div>
                <?php endif; ?>
                <div class="ag-metric-note" id="liveMoistureNote">
                    <?php if ($r['soil_moisture']['value'] === null): ?>
                        No reading recorded yet
                    <?php elseif ($r['soil_moisture']['stale']): ?>
                        Last good reading - the sensor is not responding
                    <?php else: ?>
                        Updated <?= e(time_ago($r['soil_moisture']['recorded_at'])) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($dev['show_temperature']): ?>
        <div class="col-6 col-xl-2">
            <div class="ag-metric is-temp">
                <div class="ag-metric-label">
                    <?= icon('thermo', 14) ?> <?= $dev['show_leaf'] ? 'Air temperature' : 'Temperature' ?>
                </div>
                <div class="ag-metric-value<?= $r['temperature']['stale'] ? ' ag-stale' : '' ?>" id="liveTemp">
                    <?php if ($r['temperature']['value'] === null): ?>--<?php else: ?>
                        <?= e(number_format($r['temperature']['value'], 1)) ?><small>&deg;C</small>
                    <?php endif; ?>
                </div>
                <?php if ($dev['show_leaf']): ?>
                    <?php
                    // Canopy sits inside the temperature card rather than in a
                    // card of its own: on its own the number means little, and
                    // what matters is how far it is above the air beside it.
                    $leafValue = $r['leaf_temperature']['value'];
                    $delta     = $r['leaf_delta'];
                    ?>
                    <div class="ag-metric-note<?= $r['leaf_stress'] ? ' text-danger fw-semibold' : '' ?>"
                         id="liveLeaf">
                        <?php if ($leafValue === null): ?>
                            Canopy --
                        <?php else: ?>
                            Canopy <?= e(number_format($leafValue, 1)) ?>&deg;C<?php
                            if ($delta !== null): ?>
                                (<?= $delta >= 0 ? '+' : '' ?><?= e(number_format($delta, 1)) ?>)
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="ag-metric-note" id="liveTempNote">
                    <?php if ($r['temperature']['value'] === null): ?>
                        No reading recorded yet
                    <?php elseif ($r['temperature']['stale']): ?>
                        Last good reading - the sensor is not responding
                    <?php else: ?>
                        Updated <?= e(time_ago($r['temperature']['recorded_at'])) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($dev['show_humidity']): ?>
        <div class="col-6 col-xl-2">
            <div class="ag-metric is-humidity">
                <div class="ag-metric-label"><?= icon('humidity', 14) ?> Humidity</div>
                <div class="ag-metric-value<?= $r['humidity']['stale'] ? ' ag-stale' : '' ?>" id="liveHumidity">
                    <?php if ($r['humidity']['value'] === null): ?>--<?php else: ?>
                        <?= e(number_format($r['humidity']['value'], 1)) ?><small>%</small>
                    <?php endif; ?>
                </div>
                <div class="ag-metric-note" id="liveHumidityNote">
                    <?php if ($r['humidity']['value'] === null): ?>
                        No reading recorded yet
                    <?php elseif ($r['humidity']['stale']): ?>
                        Last good reading - DHT22 not responding
                    <?php else: ?>
                        Updated <?= e(time_ago($r['humidity']['recorded_at'])) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; /* show_humidity */ ?>

        <div class="col-6 col-xl-2">
            <div class="ag-metric is-pump<?= $pumpOn ? ' ag-pulse' : '' ?>">
                <div class="ag-metric-label"><?= icon('plug', 14) ?> Pump</div>
                <div class="ag-metric-value<?= $pumpOn ? ' text-success' : '' ?>" id="livePump">
                    <?= e($pump['status']) ?>
                </div>
                <div class="mt-1">
                    <span class="ag-pill <?= $pumpOn ? 'ag-pill-on' : 'ag-pill-off' ?>" id="livePumpPill">
                        <span class="ag-pill-dot"></span><?= $pumpOn ? 'Running' : 'Idle' ?>
                    </span>
                </div>
                <div class="ag-metric-note" id="livePumpNote">
                    <?php if ($pump['running_cycle']): ?>
                        <?= e($pump['running_cycle']['trigger_type']) ?> cycle,
                        running <?= e(fmt_duration($pump['running_cycle']['elapsed'])) ?>
                    <?php else: ?>
                        <?= e((string) $snapshot['today']['cycles']) ?> cycle(s) today,
                        <?= e($snapshot['today']['total_h']) ?> total
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-2">
            <div class="ag-metric is-device<?= $dev['online'] ? '' : ' is-critical' ?>" id="liveDeviceCard">
                <div class="ag-metric-label"><?= icon('wifi', 14) ?> Device</div>
                <div class="ag-metric-value<?= $dev['online'] ? '' : ' text-danger' ?>" id="liveDevice">
                    <?= $dev['online'] ? 'ONLINE' : 'OFFLINE' ?>
                </div>
                <div class="mt-1">
                    <span class="ag-pill <?= $dev['online'] ? 'ag-pill-online' : 'ag-pill-offline' ?>"
                          id="liveDevicePill">
                        <span class="ag-pill-dot"></span><?= $dev['online'] ? 'Connected' : 'No signal' ?>
                    </span>
                </div>
                <div class="ag-metric-note">
                    Seen <span id="liveLastSeen"><?= e($dev['last_seen_h']) ?></span>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-2">
            <div class="ag-metric">
                <div class="ag-metric-label"><?= icon('clock', 14) ?> Last update</div>
                <div class="ag-metric-value" style="font-size:1.35rem;" id="liveUpdated">
                    <?= e($r['recorded_h']) ?>
                </div>
                <div class="mt-1">
                    <span class="ag-pill <?= $pump['mode'] === 'AUTO' ? 'ag-pill-auto' : 'ag-pill-manual' ?>"
                          id="liveMode">
                        <?= $pump['mode'] === 'AUTO' ? 'Automatic' : 'Manual' ?>
                    </span>
                </div>
                <div class="ag-metric-note">
                    ON below <?= e(number_format($settings['moisture_threshold'], 0)) ?>%,
                    OFF at <?= e(number_format($settings['target_moisture'], 0)) ?>%
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">

        <!-- ============ Charts ============ -->
        <div class="col-12 col-xxl-8">
            <div class="ag-card mb-3">
                <div class="ag-card-head">
                    <h2 class="ag-card-title"><?= icon('water', 16) ?> Soil moisture</h2>
                    <div class="btn-group btn-group-sm ms-auto ag-no-print" role="group" aria-label="Chart period">
                        <button class="btn btn-outline-secondary" data-chart-range="today" type="button">Today</button>
                        <button class="btn btn-outline-secondary" data-chart-range="yesterday" type="button">Yesterday</button>
                        <button class="btn btn-outline-secondary active" data-chart-range="7days" type="button">7 days</button>
                        <button class="btn btn-outline-secondary" data-chart-range="30days" type="button">30 days</button>
                    </div>
                </div>
                <div class="ag-card-body">
                    <div class="ag-chart"><canvas id="chartMoisture"></canvas></div>
                </div>
            </div>

            <div class="row g-3 mb-3<?= $dev['show_temperature'] || $dev['show_humidity'] ? '' : ' d-none' ?>">
                <?php if ($dev['show_temperature']): ?>
                    <!-- Full width when there is no humidity chart beside it. -->
                    <div class="<?= $dev['show_humidity'] ? 'col-md-6' : 'col-12' ?>">
                        <div class="ag-card h-100">
                            <div class="ag-card-head">
                                <h2 class="ag-card-title">
                                    <?= icon('thermo', 16) ?>
                                    <?= $dev['show_leaf'] ? 'Air and canopy temperature' : 'Temperature' ?>
                                </h2>
                            </div>
                            <div class="ag-card-body">
                                <div class="ag-chart ag-chart-sm"><canvas id="chartTemperature"></canvas></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($dev['show_humidity']): ?>
                    <div class="col-md-6">
                        <div class="ag-card h-100">
                            <div class="ag-card-head">
                                <h2 class="ag-card-title"><?= icon('humidity', 16) ?> Humidity</h2>
                            </div>
                            <div class="ag-card-body">
                                <div class="ag-chart ag-chart-sm"><canvas id="chartHumidity"></canvas></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="ag-card">
                <div class="ag-card-head">
                    <h2 class="ag-card-title"><?= icon('plug', 16) ?> Irrigation activity</h2>
                    <a class="ms-auto small" href="<?= e(url('irrigation/history', ['device' => $dev['id']])) ?>">
                        Full history
                    </a>
                </div>
                <div class="ag-card-body">
                    <div class="ag-chart"><canvas id="chartActivity"></canvas></div>
                </div>
            </div>
        </div>

        <!-- ============ Side column ============ -->
        <div class="col-12 col-xxl-4">

            <?php if ($canControlPump): ?>
                <div class="ag-card mb-3">
                    <div class="ag-card-head">
                        <h2 class="ag-card-title"><?= icon('power', 16) ?> Pump control</h2>
                        <span class="ag-pill ms-auto <?= $pump['mode'] === 'AUTO' ? 'ag-pill-auto' : 'ag-pill-manual' ?>">
                            <?= $pump['mode'] === 'AUTO' ? 'Automatic' : 'Manual' ?>
                        </span>
                    </div>
                    <div class="ag-card-body">
                        <div class="alert alert-info d-none" id="agCommandStatus" role="status"></div>

                        <div class="alert alert-warning py-2 small d-none" id="agPendingCommand" role="status">
                            A command is waiting for the device to collect it.
                        </div>

                        <p class="ag-field-help mb-3">
                            In automatic mode the device starts the pump below
                            <strong><?= e(number_format($settings['moisture_threshold'], 0)) ?>%</strong>
                            and stops it at
                            <strong><?= e(number_format($settings['target_moisture'], 0)) ?>%</strong>.
                            Switch to manual mode to operate it yourself.
                        </p>

                        <div class="row g-2 ag-pump-controls">
                            <div class="col-6">
                                <button class="btn btn-ag w-100" id="btnPumpOn" type="button"
                                        data-pump-command="PUMP_ON">
                                    <?= icon('power', 16) ?> Pump ON
                                </button>
                            </div>
                            <div class="col-6">
                                <button class="btn btn-danger w-100" id="btnPumpOff" type="button"
                                        data-pump-command="PUMP_OFF">
                                    <?= icon('x', 16) ?> Pump OFF
                                </button>
                            </div>
                            <div class="col-6">
                                <button class="btn btn-outline-secondary w-100" id="btnModeAuto" type="button"
                                        data-pump-command="MODE_AUTO">Automatic mode</button>
                            </div>
                            <div class="col-6">
                                <button class="btn btn-outline-secondary w-100" id="btnModeManual" type="button"
                                        data-pump-command="MODE_MANUAL">Manual mode</button>
                            </div>
                        </div>

                        <p class="ag-field-help mt-3 mb-0">
                            Safety cut-off: the pump stops automatically after
                            <?= e(fmt_duration($settings['max_pump_runtime'])) ?>.
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Recent alerts -->
            <div class="ag-card mb-3">
                <div class="ag-card-head">
                    <h2 class="ag-card-title"><?= icon('bell', 16) ?> Recent alerts</h2>
                    <a class="ms-auto small" href="<?= e(url('alerts')) ?>">View all</a>
                </div>
                <?php if (!$recentAlerts): ?>
                    <div class="ag-empty">Nothing to report. The system is running normally.</div>
                <?php else: ?>
                    <?php foreach ($recentAlerts as $a): ?>
                        <div class="ag-alert-item<?= (int) $a['is_read'] === 0 ? ' is-unread' : '' ?>">
                            <span class="ag-alert-bar sev-<?= e($a['severity']) ?>"></span>
                            <div class="small flex-grow-1">
                                <div class="fw-semibold"><?= e(Alert::label($a['alert_type'])) ?></div>
                                <div class="text-muted"><?= e($a['message']) ?></div>
                                <div class="text-muted mt-1" style="font-size:.72rem;">
                                    <?= e(time_ago($a['created_at'])) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Recent irrigation -->
            <div class="ag-card">
                <div class="ag-card-head">
                    <h2 class="ag-card-title"><?= icon('water', 16) ?> Latest irrigation</h2>
                    <a class="ms-auto small" href="<?= e(url('irrigation/history', ['device' => $dev['id']])) ?>">
                        View all
                    </a>
                </div>
                <?php if (!$recentCycles): ?>
                    <div class="ag-empty">No irrigation has been recorded for this device yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table ag-table table-sm mb-0">
                            <thead>
                            <tr>
                                <th>Started</th>
                                <th>Trigger</th>
                                <th class="num">Moisture</th>
                                <th class="num">Duration</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentCycles as $c): ?>
                                <tr>
                                    <td class="small"><?= e(fmt_datetime($c['started_at'], 'd M, g:i A')) ?></td>
                                    <td>
                                        <span class="ag-pill <?= $c['trigger_type'] === 'manual' ? 'ag-pill-manual' : 'ag-pill-auto' ?>">
                                            <?= e(ucfirst($c['trigger_type'])) ?>
                                        </span>
                                    </td>
                                    <td class="num small"><?= e(fmt_num($c['soil_moisture'], 1, '%')) ?></td>
                                    <td class="num small">
                                        <?php if ($c['ended_at'] === null): ?>
                                            <span class="text-success fw-semibold">running</span>
                                        <?php else: ?>
                                            <?= e(fmt_duration((int) $c['duration_seconds'])) ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Initial chart data, replaced by /api/chart-data when the range changes -->
    <script type="application/json" id="agChartData"><?= json_encode([
        'series'   => $series,
        'activity' => $activity,
    ], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</div>

<!-- Confirmation before a physical pump start (requirement 8) -->
<div class="modal fade" id="agPumpConfirm" tabindex="-1" aria-hidden="true" aria-labelledby="agPumpConfirmTitle">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="agPumpConfirmTitle">Start the pump?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">
                    This will switch on the water pump at
                    <strong><?= e($dev['name']) ?></strong><?= $dev['location'] ? ' (' . e($dev['location']) . ')' : '' ?>.
                </p>
                <p class="mb-0 ag-field-help">
                    Make sure the water source is open and the hose is in place.
                    The pump stops automatically after
                    <?= e(fmt_duration($settings['max_pump_runtime'])) ?>, or when you press Pump OFF.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-ag" id="agPumpConfirmYes">Yes, start the pump</button>
            </div>
        </div>
    </div>
</div>
