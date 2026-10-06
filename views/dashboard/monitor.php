<?php
/**
 * AgriSense - Real-time monitoring (requirement 11)
 *
 * A focused, large-type view of one device, intended for a phone held in
 * the field or a screen left open in the shed.
 *
 * @var array $devices
 * @var array $device
 * @var array $snapshot
 * @var array $series
 * @var array $commands
 * @var bool  $canControlPump
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

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <?php require APP_ROOT . '/views/layout/device_select.php'; ?>
        <span class="ag-live" id="agLiveIndicator">
            <span class="ag-live-dot"></span>
            <span id="agLiveText">Live</span>
        </span>
    </div>

    <div class="row g-3">

        <!-- ============ Live readings ============ -->
        <div class="col-12 col-lg-7">
            <div class="ag-card mb-3">
                <div class="ag-card-head">
                    <h2 class="ag-card-title"><?= icon('monitor', 16) ?> Current conditions</h2>
                    <span class="ms-auto ag-pill <?= $dev['online'] ? 'ag-pill-online' : 'ag-pill-offline' ?>"
                          id="liveDevicePill">
                        <span class="ag-pill-dot"></span><?= $dev['online'] ? 'Connected' : 'No signal' ?>
                    </span>
                </div>
                <div class="ag-card-body">
                    <div class="row g-3 text-center">

                        <div class="col-12">
                            <div class="ag-metric-label justify-content-center">
                                <?= icon('water', 14) ?> Soil moisture
                            </div>
                            <div class="ag-metric-value<?= $r['soil_moisture']['stale'] ? ' ag-stale' : '' ?>"
                                 id="liveMoisture" style="font-size: 3.4rem;">
                                <?php if ($r['soil_moisture']['value'] === null): ?>--<?php else: ?>
                                    <?= e(number_format($r['soil_moisture']['value'], 1)) ?><small>%</small>
                                <?php endif; ?>
                            </div>
                            <div class="ag-progress mt-2" style="height:.75rem;">
                                <div id="liveMoistureBar"
                                     style="width: <?= e((string) max(0, min(100, (float) ($r['soil_moisture']['value'] ?? 0)))) ?>%;"></div>
                            </div>
                            <div class="d-flex justify-content-between ag-field-help mt-1">
                                <span>Pump ON at <?= e(number_format($settings['moisture_threshold'], 0)) ?>%</span>
                                <span>Pump OFF at <?= e(number_format($settings['target_moisture'], 0)) ?>%</span>
                            </div>
                            <?php if ($dev['probe_count'] >= 2): ?>
                                <div class="ag-probe-row justify-content-center" id="liveProbes">
                                    <span class="ag-probe-chip">
                                        <span class="ag-probe-label">Probe 1</span>
                                        <span class="ag-probe-value<?= $r['soil_moisture_1']['value'] === null ? ' text-danger' : '' ?>"
                                              id="liveProbe1"><?= e(fmt_num($r['soil_moisture_1']['value'], 1, '%')) ?></span>
                                    </span>
                                    <span class="ag-probe-chip">
                                        <span class="ag-probe-label">Probe 2</span>
                                        <span class="ag-probe-value<?= $r['soil_moisture_2']['value'] === null ? ' text-danger' : '' ?>"
                                              id="liveProbe2"><?= e(fmt_num($r['soil_moisture_2']['value'], 1, '%')) ?></span>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <div class="ag-metric-note mt-1" id="liveMoistureNote">
                                <?php if ($r['soil_moisture']['value'] === null): ?>
                                    No reading recorded yet
                                <?php elseif ($r['soil_moisture']['stale']): ?>
                                    Last good reading - the sensor is not responding
                                <?php else: ?>
                                    Updated <?= e(time_ago($r['soil_moisture']['recorded_at'])) ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($dev['show_temperature']): ?>
                        <div class="<?= $dev['show_humidity'] || $dev['show_leaf'] ? 'col-6' : 'col-12' ?>">
                            <div class="ag-metric-label justify-content-center">
                                <?= icon('thermo', 14) ?>
                                <?= $dev['show_leaf'] ? 'Air' : 'Temperature' ?>
                            </div>
                            <div class="ag-metric-value<?= $r['temperature']['stale'] ? ' ag-stale' : '' ?>" id="liveTemp">
                                <?php if ($r['temperature']['value'] === null): ?>--<?php else: ?>
                                    <?= e(number_format($r['temperature']['value'], 1)) ?><small>&deg;C</small>
                                <?php endif; ?>
                            </div>
                            <div class="ag-metric-note" id="liveTempNote"></div>
                        </div>
                        <?php endif; ?>

                        <?php if ($dev['show_leaf']): ?>
                        <!--
                            Canopy takes the second tile on an MLX90614 board.
                            The delta below it is the part that means something:
                            a canopy pulling away from the air temperature is a
                            crop that has stopped cooling itself.
                        -->
                        <div class="col-6">
                            <div class="ag-metric-label justify-content-center">
                                <?= icon('thermo', 14) ?> Canopy
                            </div>
                            <div class="ag-metric-value<?= $r['leaf_temperature']['stale'] ? ' ag-stale' : '' ?>"
                                 id="liveLeafValue">
                                <?php if ($r['leaf_temperature']['value'] === null): ?>--<?php else: ?>
                                    <?= e(number_format($r['leaf_temperature']['value'], 1)) ?><small>&deg;C</small>
                                <?php endif; ?>
                            </div>
                            <div class="ag-metric-note<?= $r['leaf_stress'] ? ' text-danger fw-semibold' : '' ?>"
                                 id="liveLeafDelta">
                                <?php if ($r['leaf_delta'] !== null): ?>
                                    <?= $r['leaf_delta'] >= 0 ? '+' : '' ?><?= e(number_format($r['leaf_delta'], 1)) ?>&deg;C
                                    vs air<?= $r['leaf_stress'] ? ' - possible water stress' : '' ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($dev['show_humidity']): ?>
                        <div class="col-6">
                            <div class="ag-metric-label justify-content-center">
                                <?= icon('humidity', 14) ?> Humidity
                            </div>
                            <div class="ag-metric-value<?= $r['humidity']['stale'] ? ' ag-stale' : '' ?>" id="liveHumidity">
                                <?php if ($r['humidity']['value'] === null): ?>--<?php else: ?>
                                    <?= e(number_format($r['humidity']['value'], 1)) ?><small>%</small>
                                <?php endif; ?>
                            </div>
                            <div class="ag-metric-note" id="liveHumidityNote"></div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <hr>

                    <div class="row g-3 small">
                        <div class="col-6 col-md-3">
                            <div class="text-muted">Pump</div>
                            <div class="fw-bold fs-5<?= $pumpOn ? ' text-success' : '' ?>" id="livePump">
                                <?= e($pump['status']) ?>
                            </div>
                            <span class="ag-pill <?= $pumpOn ? 'ag-pill-on' : 'ag-pill-off' ?>" id="livePumpPill">
                                <span class="ag-pill-dot"></span><?= $pumpOn ? 'Running' : 'Idle' ?>
                            </span>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="text-muted">Mode</div>
                            <div class="mt-1">
                                <span class="ag-pill <?= $pump['mode'] === 'AUTO' ? 'ag-pill-auto' : 'ag-pill-manual' ?>"
                                      id="liveMode"><?= $pump['mode'] === 'AUTO' ? 'Automatic' : 'Manual' ?></span>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="text-muted">Device</div>
                            <div class="fw-bold<?= $dev['online'] ? '' : ' text-danger' ?>" id="liveDevice">
                                <?= $dev['online'] ? 'ONLINE' : 'OFFLINE' ?>
                            </div>
                            <div class="text-muted">Seen <span id="liveLastSeen"><?= e($dev['last_seen_h']) ?></span></div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="text-muted">Last reading</div>
                            <div class="fw-bold" id="liveUpdated"><?= e($r['recorded_h']) ?></div>
                            <div class="text-muted" id="livePumpNote"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="ag-card">
                <div class="ag-card-head">
                    <h2 class="ag-card-title"><?= icon('water', 16) ?> Today's soil moisture</h2>
                    <div class="btn-group btn-group-sm ms-auto ag-no-print" role="group" aria-label="Chart period">
                        <button class="btn btn-outline-secondary active" data-chart-range="today" type="button">Today</button>
                        <button class="btn btn-outline-secondary" data-chart-range="7days" type="button">7 days</button>
                    </div>
                </div>
                <div class="ag-card-body">
                    <div class="ag-chart"><canvas id="chartMoisture"></canvas></div>
                </div>
            </div>
        </div>

        <!-- ============ Control column ============ -->
        <div class="col-12 col-lg-5">

            <div class="ag-card mb-3">
                <div class="ag-card-head">
                    <h2 class="ag-card-title"><?= icon('power', 16) ?> Pump control</h2>
                </div>
                <div class="ag-card-body">
                    <?php if (!$canControlPump): ?>
                        <div class="alert alert-secondary small mb-0" role="status">
                            You have view-only access. Ask an administrator to enable pump control
                            for your account if you need to operate the pump.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info d-none" id="agCommandStatus" role="status"></div>
                        <div class="alert alert-warning py-2 small d-none" id="agPendingCommand" role="status">
                            A command is waiting for the device to collect it.
                        </div>

                        <div class="row g-2 ag-pump-controls mb-3">
                            <div class="col-6">
                                <button class="btn btn-ag w-100" id="btnPumpOn" type="button"
                                        data-pump-command="PUMP_ON"><?= icon('power', 16) ?> Pump ON</button>
                            </div>
                            <div class="col-6">
                                <button class="btn btn-danger w-100" id="btnPumpOff" type="button"
                                        data-pump-command="PUMP_OFF"><?= icon('x', 16) ?> Pump OFF</button>
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

                        <ul class="ag-field-help mb-0 ps-3">
                            <li>Manual mode is required before starting the pump by hand.</li>
                            <li>The pump stops automatically after
                                <?= e(fmt_duration($settings['max_pump_runtime'])) ?>.</li>
                            <li>A rest of <?= e(fmt_duration($settings['min_cycle_interval'])) ?>
                                is enforced between cycles.</li>
                            <li>Every command is recorded against your account.</li>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Command trail: what was asked for, and what the device did -->
            <div class="ag-card">
                <div class="ag-card-head">
                    <h2 class="ag-card-title"><?= icon('logs', 16) ?> Recent commands</h2>
                </div>
                <?php if (!$commands): ?>
                    <div class="ag-empty">No manual commands have been sent to this device.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table ag-table table-sm mb-0">
                            <thead>
                            <tr>
                                <th>Time</th>
                                <th>Command</th>
                                <th>By</th>
                                <th>Result</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($commands as $c): ?>
                                <tr>
                                    <td class="small text-nowrap"><?= e(fmt_datetime($c['created_at'], 'd M, g:i A')) ?></td>
                                    <td class="small"><?= e(PumpCommand::LABELS[$c['command']] ?? $c['command']) ?></td>
                                    <td class="small"><?= e($c['requested_by_name'] ?? 'System') ?></td>
                                    <td class="small">
                                        <?php
                                        $badge = match ($c['status']) {
                                            'acknowledged' => 'success',
                                            'failed', 'expired' => 'danger',
                                            'delivered' => 'info',
                                            default => 'secondary',
                                        };
                                        ?>
                                        <span class="badge text-bg-<?= e($badge) ?>"><?= e($c['status']) ?></span>
                                        <?php if (!empty($c['result_message'])): ?>
                                            <div class="text-muted" style="font-size:.72rem;">
                                                <?= e($c['result_message']) ?>
                                            </div>
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

    <script type="application/json" id="agChartData"><?= json_encode([
        'series'   => $series,
        'activity' => [],
    ], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</div>

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
