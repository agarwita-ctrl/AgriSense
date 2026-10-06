<?php
/**
 * AgriSense - Register / edit a device
 *
 * @var array      $device
 * @var array      $errors
 * @var bool       $isNew
 * @var array|null $settings  Present when editing
 */
$err     = static fn (string $f): string => isset($errors[$f])
    ? '<div class="invalid-feedback d-block">' . e($errors[$f]) . '</div>' : '';
$invalid = static fn (string $f): string => isset($errors[$f]) ? ' is-invalid' : '';

$action = $isNew
    ? url('devices/create')
    : url('devices/edit', ['id' => (int) $device['id']]);
?>

<div class="mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('devices')) ?>">&larr; Back to devices</a>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-7">
        <form method="post" action="<?= e($action) ?>" novalidate>
            <?= csrf_field() ?>
            <?php if (!$isNew): ?>
                <input type="hidden" name="id" value="<?= e((string) $device['id']) ?>">
            <?php endif; ?>

            <div class="ag-card">
                <div class="ag-card-head">
                    <h2 class="ag-card-title">
                        <?= icon('device', 16) ?>
                        <?= $isNew ? 'Register a new device' : 'Device details' ?>
                    </h2>
                </div>
                <div class="ag-card-body">

                    <div class="mb-3">
                        <label class="form-label" for="device_name">Device name</label>
                        <input type="text" class="form-control<?= $invalid('device_name') ?>"
                               id="device_name" name="device_name" maxlength="100" required
                               value="<?= e($device['device_name']) ?>"
                               placeholder="e.g. Field A - Rice Paddy">
                        <div class="ag-field-help">A name the farmer will recognise.</div>
                        <?= $err('device_name') ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="device_code">Device code</label>
                        <input type="text" class="form-control<?= $invalid('device_code') ?>"
                               id="device_code" name="device_code" maxlength="50" required
                               value="<?= e($device['device_code']) ?>"
                               placeholder="ESP32-001" autocapitalize="characters" spellcheck="false">
                        <div class="ag-field-help">
                            Must match <code>DEVICE_ID</code> in the firmware. Letters, numbers,
                            dashes and underscores only.
                        </div>
                        <?= $err('device_code') ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="location">Farm / location</label>
                        <input type="text" class="form-control<?= $invalid('location') ?>"
                               id="location" name="location" maxlength="150"
                               value="<?= e($device['location'] ?? '') ?>"
                               placeholder="e.g. Barangay San Isidro - Field A">
                        <?= $err('location') ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="mac_address">ESP32 MAC address <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" class="form-control<?= $invalid('mac_address') ?>"
                               id="mac_address" name="mac_address" maxlength="17"
                               value="<?= e($device['mac_address'] ?? '') ?>"
                               placeholder="A0:B7:65:1C:2D:3E" spellcheck="false">
                        <div class="ag-field-help">
                            The board prints its MAC to the serial monitor at start-up.
                        </div>
                        <?= $err('mac_address') ?>
                    </div>

                    <hr>

                    <p class="form-label mb-2">Hardware fitted to this board</p>

                    <div class="mb-3">
                        <label class="form-label fw-normal" for="probe_count">Soil moisture probes</label>
                        <select class="form-select<?= $invalid('probe_count') ?>" id="probe_count" name="probe_count">
                            <option value="1" <?= (int) ($device['probe_count'] ?? 1) === 1 ? 'selected' : '' ?>>
                                One probe
                            </option>
                            <option value="2" <?= (int) ($device['probe_count'] ?? 1) === 2 ? 'selected' : '' ?>>
                                Two probes &mdash; readings averaged, each logged separately
                            </option>
                        </select>
                        <div class="ag-field-help">
                            With two probes the primary reading is their average, and each probe is
                            also charted on its own so a loose or failed probe is visible.
                        </div>
                        <?= $err('probe_count') ?>
                    </div>

                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" value="1"
                                   id="has_mlx90614" name="has_mlx90614"
                                   <?= (int) ($device['has_mlx90614'] ?? 0) === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="has_mlx90614">
                                MLX90614 infrared thermometer fitted
                            </label>
                        </div>
                        <div class="ag-field-help">
                            Firmware 4.0.0 and later. Measures air temperature and, without touching
                            the plants, the temperature of the canopy itself. A canopy running well
                            above the air is an early sign of water stress, several hours before the
                            soil probes show it.
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" value="1"
                                   id="has_dht22" name="has_dht22"
                                   <?= (int) ($device['has_dht22'] ?? 1) === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="has_dht22">
                                DHT22 temperature and humidity sensor fitted
                            </label>
                        </div>
                        <div class="ag-field-help">
                            Firmware 3.x and earlier. This is the only sensor that measures humidity;
                            boards that have had the DHT22 replaced by an MLX90614 report none.
                        </div>
                    </div>

                    <div class="ag-field-help mb-3">
                        Both switches are independent, and turning one off is not a way of hiding a
                        broken sensor: a card is hidden only when the sensor is genuinely absent, so
                        that a fault still shows up as a fault. Air temperature appears when either
                        sensor is fitted, humidity only with the DHT22, canopy temperature only with
                        the MLX90614.
                    </div>

                    <div class="mb-0">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select<?= $invalid('status') ?>" id="status" name="status">
                            <option value="active"      <?= $device['status'] === 'active' ? 'selected' : '' ?>>Active &mdash; accepting data and commands</option>
                            <option value="maintenance" <?= $device['status'] === 'maintenance' ? 'selected' : '' ?>>Maintenance &mdash; visible but not in service</option>
                            <option value="inactive"    <?= $device['status'] === 'inactive' ? 'selected' : '' ?>>Inactive &mdash; API access refused</option>
                        </select>
                        <div class="ag-field-help">
                            An inactive device cannot upload readings or receive pump commands.
                        </div>
                        <?= $err('status') ?>
                    </div>
                </div>

                <div class="ag-card-body border-top d-flex gap-2">
                    <button class="btn btn-ag" type="submit">
                        <?= icon('check', 16) ?> <?= $isNew ? 'Register device' : 'Save changes' ?>
                    </button>
                    <a class="btn btn-outline-secondary" href="<?= e(url('devices')) ?>">Cancel</a>
                </div>
            </div>
        </form>
    </div>

    <?php if (!$isNew): ?>
        <div class="col-12 col-xl-5">

            <!-- ============ API key ============ -->
            <div class="ag-card mb-3">
                <div class="ag-card-head">
                    <h2 class="ag-card-title"><?= icon('key', 16) ?> Firmware credentials</h2>
                </div>
                <div class="ag-card-body">
                    <?php $newKey = $newKey ?? null; ?>

                    <?php if ($newKey): ?>
                        <label class="form-label">API key</label>
                        <div class="input-group input-group-sm mb-2">
                            <input type="text" class="form-control ag-key" id="apiKeyField" readonly
                                   value="<?= e($newKey) ?>">
                            <button class="btn btn-outline-secondary" type="button" id="copyKey">Copy</button>
                        </div>
                        <div class="alert alert-warning py-2 px-3 small mb-3">
                            <strong>Copy this now.</strong> Only a hash of the key is stored, so it
                            cannot be shown again. If you lose it, generate a new one and re-flash
                            the board.
                        </div>

                        <p class="small mb-2 fw-semibold">
                            Paste into <code>esp32/AgriSense.ino</code>, under USER CONFIG:
                        </p>
<pre class="small bg-light border rounded p-2 mb-3" style="white-space: pre-wrap;">const char* AGRISENSE_BASE = "http://&lt;your-pc-ip&gt;<?= e(BASE_PATH) ?>";
const char* DEVICE_API_KEY = "<?= e($newKey) ?>";
const char* DEVICE_ID      = "<?= e($device['device_code']) ?>";</pre>
                    <?php else: ?>
                        <label class="form-label">API key</label>
                        <div class="input-group input-group-sm mb-2">
                            <input type="text" class="form-control ag-key" readonly disabled
                                   value="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;  not stored in readable form">
                        </div>
                        <div class="ag-field-help mb-3">
                            The ESP32 sends its key in the <code>X-API-Key</code> header. Only a
                            SHA-256 hash of it is kept, so the key itself cannot be read back
                            &mdash; not from this page, and not from the database. If the board
                            has lost its key, generate a new one below and flash it.
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?= e(url('devices/rotate-key')) ?>" class="m-0"
                          data-confirm="Generate a new API key? The device will stop working until you flash the new key.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= e((string) $device['id']) ?>">
                        <button class="btn btn-sm btn-outline-secondary" type="submit">
                            <?= icon('refresh', 15) ?> Generate a new key
                        </button>
                    </form>
                </div>
            </div>

            <!-- ============ Live status ============ -->
            <div class="ag-card mb-3">
                <div class="ag-card-head">
                    <h2 class="ag-card-title"><?= icon('wifi', 16) ?> Current status</h2>
                </div>
                <div class="ag-card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-5 text-muted fw-normal">Connectivity</dt>
                        <dd class="col-7">
                            <span class="ag-pill <?= (int) $device['is_online'] === 1 ? 'ag-pill-online' : 'ag-pill-offline' ?>">
                                <span class="ag-pill-dot"></span>
                                <?= (int) $device['is_online'] === 1 ? 'Online' : 'Offline' ?>
                            </span>
                        </dd>

                        <dt class="col-5 text-muted fw-normal">Last seen</dt>
                        <dd class="col-7"><?= e(time_ago($device['last_seen'])) ?></dd>

                        <dt class="col-5 text-muted fw-normal">IP address</dt>
                        <dd class="col-7"><?= e($device['ip_address'] ?: '--') ?></dd>

                        <dt class="col-5 text-muted fw-normal">Firmware</dt>
                        <dd class="col-7"><?= e($device['firmware_version'] ?: '--') ?></dd>

                        <dt class="col-5 text-muted fw-normal">Registered</dt>
                        <dd class="col-7"><?= e(fmt_datetime($device['created_at'])) ?></dd>

                        <?php if ($settings): ?>
                            <dt class="col-5 text-muted fw-normal">Thresholds</dt>
                            <dd class="col-7">
                                ON &le; <?= e(number_format((float) $settings['moisture_threshold'], 0)) ?>%,
                                OFF &ge; <?= e(number_format((float) $settings['target_moisture'], 0)) ?>%
                                <a class="d-block"
                                   href="<?= e(url('irrigation/settings', ['device' => (int) $device['id']])) ?>">Change</a>
                            </dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>

            <!-- ============ Danger zone ============ -->
            <div class="ag-danger-zone p-3">
                <h3 class="h6 fw-bold text-danger mb-2"><?= icon('warning', 15) ?> Delete this device</h3>
                <p class="small text-muted">
                    Deleting removes the device together with <strong>every</strong> sensor reading,
                    irrigation record, alert and command belonging to it. This cannot be undone.
                    To take a device out of service without losing its history, set its status to
                    <em>Inactive</em> instead.
                </p>
                <form method="post" action="<?= e(url('devices/delete')) ?>"
                      data-confirm="This permanently deletes the device and all of its recorded history. Continue?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $device['id']) ?>">
                    <label class="form-label small" for="confirm_code">
                        Type <code><?= e($device['device_code']) ?></code> to confirm
                    </label>
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" id="confirm_code" name="confirm_code"
                               placeholder="<?= e($device['device_code']) ?>" autocomplete="off" spellcheck="false">
                        <button class="btn btn-danger" type="submit"><?= icon('trash', 15) ?> Delete</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if (!$isNew && ($newKey ?? null)): ?>
<script <?= csp_attr() ?>>
    document.getElementById('copyKey')?.addEventListener('click', function () {
        var field = document.getElementById('apiKeyField');
        field.select();
        field.setSelectionRange(0, 99999);
        // The Clipboard API needs a secure context; fall back to execCommand
        // so this still works over plain HTTP on a LAN.
        var done = function () { window.AgriSense.toast('API key copied to the clipboard.'); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(field.value).then(done);
        } else {
            document.execCommand('copy');
            done();
        }
    });
</script>
<?php endif; ?>
