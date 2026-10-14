<?php
/**
 * AgriSense - Irrigation settings (requirement 17)
 *
 * Farmers can see the configuration. Administrators can change all of it;
 * a farmer with the pump-control permission can change the planting cycle.
 *
 * @var array      $devices
 * @var array      $device
 * @var array      $settings
 * @var array      $errors
 * @var bool       $canEdit       all settings (administrators)
 * @var bool       $canEditCycle  the planting cycle (administrators and pump-control farmers)
 * @var array      $cycle         IrrigationSetting::cycleStatus()
 * @var array|null $updatedBy
 */

/** Render the validation message for a field, if there is one. */
$err = static function (string $field) use ($errors): string {
    return isset($errors[$field])
        ? '<div class="invalid-feedback d-block">' . e($errors[$field]) . '</div>'
        : '';
};
$invalid = static fn (string $field): string => isset($errors[$field]) ? ' is-invalid' : '';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <?php require APP_ROOT . '/views/layout/device_select.php'; ?>
    <?php if (!$canEdit && $canEditCycle): ?>
        <span class="badge text-bg-secondary">You can change the planting cycle &mdash; administrators change the rest</span>
    <?php elseif (!$canEdit): ?>
        <span class="badge text-bg-secondary">View only &mdash; administrators can change these values</span>
    <?php endif; ?>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger" role="alert">
        <strong>These settings were not saved.</strong>
        Please correct the highlighted fields below.
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('irrigation/settings', ['device' => (int) $device['id']])) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="device" value="<?= e((string) $device['id']) ?>">

    <div class="row g-3">

        <!-- ============ Moisture ============ -->
        <div class="col-12 col-xl-6">
            <div class="ag-card h-100">
                <div class="ag-card-head">
                    <h2 class="ag-card-title"><?= icon('water', 16) ?> Soil moisture</h2>
                </div>
                <div class="ag-card-body">

                    <div class="mb-3">
                        <label class="form-label" for="moisture_threshold">
                            Minimum irrigation threshold (%)
                        </label>
                        <input type="number" step="0.1" min="5" max="90"
                               class="form-control<?= $invalid('moisture_threshold') ?>"
                               id="moisture_threshold" name="moisture_threshold"
                               value="<?= e((string) $settings['moisture_threshold']) ?>"
                               <?= $canEdit ? '' : 'disabled' ?> required>
                        <div class="ag-field-help">
                            The pump switches ON when soil moisture falls to or below this value.
                        </div>
                        <?= $err('moisture_threshold') ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="target_moisture">Target moisture (%)</label>
                        <input type="number" step="0.1" min="10" max="95"
                               class="form-control<?= $invalid('target_moisture') ?>"
                               id="target_moisture" name="target_moisture"
                               value="<?= e((string) $settings['target_moisture']) ?>"
                               <?= $canEdit ? '' : 'disabled' ?> required>
                        <div class="ag-field-help">
                            The pump switches OFF once this level is reached. Between the two
                            values the pump simply keeps its current state &mdash; this gap is what
                            stops it switching on and off repeatedly.
                        </div>
                        <?= $err('target_moisture') ?>
                    </div>

                    <div class="alert alert-light border small mb-0">
                        <strong>How it behaves today:</strong><br>
                        At or below <?= e(number_format((float) $settings['moisture_threshold'], 1)) ?>%
                        &rarr; pump <strong>ON</strong><br>
                        At or above <?= e(number_format((float) $settings['target_moisture'], 1)) ?>%
                        &rarr; pump <strong>OFF</strong><br>
                        In between &rarr; keep the current state
                    </div>
                </div>
            </div>
        </div>

        <!-- ============ Calibration ============ -->
        <div class="col-12 col-xl-6">
            <div class="ag-card h-100">
                <div class="ag-card-head">
                    <h2 class="ag-card-title"><?= icon('sensor', 16) ?> Sensor calibration</h2>
                </div>
                <div class="ag-card-body">

                    <div class="mb-3">
                        <label class="form-label" for="dry_value">Dry value (raw ADC in air)</label>
                        <input type="number" step="1" min="0" max="4095"
                               class="form-control<?= $invalid('dry_value') ?>"
                               id="dry_value" name="dry_value"
                               value="<?= e((string) $settings['dry_value']) ?>"
                               <?= $canEdit ? '' : 'disabled' ?> required>
                        <div class="ag-field-help">
                            Hold the sensor in dry air and note the raw reading. This becomes 0%.
                        </div>
                        <?= $err('dry_value') ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="wet_value">Wet value (raw ADC in water)</label>
                        <input type="number" step="1" min="0" max="4095"
                               class="form-control<?= $invalid('wet_value') ?>"
                               id="wet_value" name="wet_value"
                               value="<?= e((string) $settings['wet_value']) ?>"
                               <?= $canEdit ? '' : 'disabled' ?> required>
                        <div class="ag-field-help">
                            Dip the sensor in water up to the marked line. This becomes 100%.
                        </div>
                        <?= $err('wet_value') ?>
                    </div>

                    <div class="alert alert-light border small mb-0">
                        A capacitive sensor reads <em>higher</em> when dry, so the dry value must be
                        the larger of the two. The firmware and this dashboard use the same mapping,
                        so a percentage always means the same thing in both places.
                    </div>
                </div>
            </div>
        </div>

        <!-- ============ Planting cycle ============ -->
        <div class="col-12">
            <div class="ag-card">
                <div class="ag-card-head">
                    <h2 class="ag-card-title"><?= icon('clock', 16) ?> Planting cycle</h2>
                </div>
                <div class="ag-card-body">
                    <div class="row g-3">

                        <div class="col-12 col-md-4">
                            <label class="form-label" for="crop_name">What is planted</label>
                            <input type="text" maxlength="80"
                                   class="form-control<?= $invalid('crop_name') ?>"
                                   id="crop_name" name="crop_name" placeholder="e.g. Rice, Corn, Tomato"
                                   value="<?= e((string) ($settings['crop_name'] ?? '')) ?>"
                                   <?= $canEditCycle ? '' : 'disabled' ?>>
                            <div class="ag-field-help">Optional. Shown on the dashboard.</div>
                            <?= $err('crop_name') ?>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label" for="cycle_start">First day of the cycle</label>
                            <input type="date"
                                   class="form-control<?= $invalid('cycle_start') ?>"
                                   id="cycle_start" name="cycle_start"
                                   value="<?= e((string) ($settings['cycle_start'] ?? '')) ?>"
                                   <?= $canEditCycle ? '' : 'disabled' ?>>
                            <div class="ag-field-help">Usually the planting or transplanting day.</div>
                            <?= $err('cycle_start') ?>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label" for="cycle_days">Length of the cycle (days)</label>
                            <input type="number" step="1" min="1" max="<?= e((string) IrrigationSetting::CYCLE_MAX_DAYS) ?>"
                                   class="form-control<?= $invalid('cycle_days') ?>"
                                   id="cycle_days" name="cycle_days" placeholder="e.g. 120"
                                   value="<?= e((string) ($settings['cycle_days'] ?? '')) ?>"
                                   <?= $canEditCycle ? '' : 'disabled' ?>>
                            <div class="ag-field-help">
                                Counts the first day as day 1. Up to <?= e((string) IrrigationSetting::CYCLE_MAX_DAYS) ?> days.
                            </div>
                            <?= $err('cycle_days') ?>
                        </div>

                        <div class="col-12">
                            <?php
                            $cycleClass = match ($cycle['state']) {
                                'active'   => 'alert-success',
                                'upcoming' => 'alert-info',
                                'ended'    => 'alert-warning',
                                default    => 'alert-light border',
                            };
                            ?>
                            <div class="alert <?= $cycleClass ?> small mb-0">
                                <strong>Today:</strong> <?= e($cycle['message']) ?>
                                <?php if ($cycle['configured']): ?>
                                    <div class="ag-progress mt-2" title="<?= e((string) $cycle['progress']) ?>% through the cycle">
                                        <div style="width: <?= e((string) max(0, min(100, $cycle['progress']))) ?>%;"></div>
                                    </div>
                                    <div class="mt-1">
                                        <?= e(fmt_date($cycle['start'])) ?> &rarr; <?= e(fmt_date($cycle['end'])) ?>
                                        (<?= e((string) $cycle['days']) ?> days)
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="ag-field-help mt-2">
                                Automatic irrigation runs only from the first to the last day of the cycle.
                                Outside it the device stops watering by itself, and a pump that is running
                                is switched off. Manual control from the dashboard still works. Leave the
                                date and length empty to water at all times.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============ Irrigation behaviour ============ -->
        <div class="col-12">
            <div class="ag-card">
                <div class="ag-card-head">
                    <h2 class="ag-card-title"><?= icon('settings', 16) ?> Irrigation behaviour and safety</h2>
                </div>
                <div class="ag-card-body">
                    <div class="row g-3">

                        <div class="col-12 col-md-6 col-xl-3">
                            <label class="form-label" for="auto_irrigation">Irrigation mode</label>
                            <select class="form-select" id="auto_irrigation" name="auto_irrigation"
                                    <?= $canEdit ? '' : 'disabled' ?>>
                                <option value="1" <?= (int) $settings['auto_irrigation'] === 1 ? 'selected' : '' ?>>
                                    Automatic &mdash; the device decides
                                </option>
                                <option value="0" <?= (int) $settings['auto_irrigation'] === 0 ? 'selected' : '' ?>>
                                    Manual &mdash; operator controls the pump
                                </option>
                            </select>
                            <div class="ag-field-help">
                                Manual mode is required before the pump can be started by hand.
                            </div>
                        </div>

                        <div class="col-12 col-md-6 col-xl-3">
                            <label class="form-label" for="max_pump_runtime">Maximum pump runtime (seconds)</label>
                            <input type="number" step="1" min="30" max="<?= e((string) PUMP_RUNTIME_CEILING) ?>"
                                   class="form-control<?= $invalid('max_pump_runtime') ?>"
                                   id="max_pump_runtime" name="max_pump_runtime"
                                   value="<?= e((string) $settings['max_pump_runtime']) ?>"
                                   <?= $canEdit ? '' : 'disabled' ?> required>
                            <div class="ag-field-help">
                                Hard safety cut-off. The pump stops even if the target is never reached.
                            </div>
                            <?= $err('max_pump_runtime') ?>
                        </div>

                        <div class="col-12 col-md-6 col-xl-3">
                            <label class="form-label" for="reading_interval">Sensor reading interval (seconds)</label>
                            <input type="number" step="1" min="5" max="3600"
                                   class="form-control<?= $invalid('reading_interval') ?>"
                                   id="reading_interval" name="reading_interval"
                                   value="<?= e((string) $settings['reading_interval']) ?>"
                                   <?= $canEdit ? '' : 'disabled' ?> required>
                            <div class="ag-field-help">
                                How often the ESP32 reads its sensors and uploads a row.
                            </div>
                            <?= $err('reading_interval') ?>
                        </div>

                        <div class="col-12 col-md-6 col-xl-3">
                            <label class="form-label" for="min_cycle_interval">Rest between cycles (seconds)</label>
                            <input type="number" step="1" min="0" max="86400"
                                   class="form-control<?= $invalid('min_cycle_interval') ?>"
                                   id="min_cycle_interval" name="min_cycle_interval"
                                   value="<?= e((string) $settings['min_cycle_interval']) ?>"
                                   <?= $canEdit ? '' : 'disabled' ?> required>
                            <div class="ag-field-help">
                                Minimum wait after a cycle ends, so water has time to soak in.
                            </div>
                            <?= $err('min_cycle_interval') ?>
                        </div>
                    </div>
                </div>

                <?php if ($canEdit || $canEditCycle): ?>
                    <div class="ag-card-body border-top d-flex flex-wrap align-items-center gap-2">
                        <button class="btn btn-ag" type="submit"><?= icon('check', 16) ?> Save settings</button>
                        <a class="btn btn-outline-secondary"
                           href="<?= e(url('irrigation/settings', ['device' => (int) $device['id']])) ?>">Cancel</a>
                        <?php if ($canEditCycle && $cycle['configured']): ?>
                            <!-- After Save in the page order, so Enter in a field submits Save, not this. -->
                            <button class="btn btn-outline-danger" type="submit" name="clear_cycle" value="1">
                                <?= icon('x', 16) ?> Clear planting cycle
                            </button>
                        <?php endif; ?>
                        <span class="ag-field-help ms-sm-auto">
                            The device applies new settings on its next check-in
                            (within <?= e(fmt_duration((int) $settings['reading_interval'])) ?>).
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<p class="text-muted small mt-3 mb-0">
    Last changed <?= e(fmt_datetime($settings['updated_at'])) ?>
    <?= $updatedBy ? 'by ' . e($updatedBy['name']) : '' ?>.
</p>
