<?php
/**
 * AgriSense - Device list (requirement 16)
 *
 * @var array $devices
 * @var array $summary  device id => counters
 * @var bool  $isAdmin
 */
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <p class="text-muted small mb-0">
        <?= e((string) count($devices)) ?> device<?= count($devices) === 1 ? '' : 's' ?> registered.
        A device counts as offline when it has not reported for
        <?= e(fmt_duration(DEVICE_OFFLINE_AFTER)) ?>.
    </p>
    <?php if ($isAdmin): ?>
        <a class="btn btn-sm btn-ag" href="<?= e(url('devices/create')) ?>">
            <?= icon('plus', 16) ?> Register device
        </a>
    <?php endif; ?>
</div>

<div class="ag-card">
    <div class="table-responsive">
        <table class="table ag-table table-hover mb-0">
            <thead>
            <tr>
                <th>Device</th>
                <th>Code</th>
                <th>Location</th>
                <th>MAC address</th>
                <th>Status</th>
                <th>Connectivity</th>
                <th>Last seen</th>
                <th class="num">Readings</th>
                <th class="num">Cycles</th>
                <th>Registered</th>
                <?php if ($isAdmin): ?><th></th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php if (!$devices): ?>
                <tr>
                    <td colspan="<?= $isAdmin ? 11 : 10 ?>">
                        <div class="ag-empty">
                            No devices yet.
                            <?php if ($isAdmin): ?>
                                <a href="<?= e(url('devices/create')) ?>">Register your first ESP32</a>.
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($devices as $d): ?>
                    <?php $s = $summary[(int) $d['id']] ?? ['readings' => 0, 'cycles' => 0, 'alerts' => 0]; ?>
                    <tr>
                        <td>
                            <a class="fw-semibold text-decoration-none"
                               href="<?= e(url('dashboard', ['device' => (int) $d['id']])) ?>">
                                <?= e($d['device_name']) ?>
                            </a>
                            <?php if ($s['alerts'] > 0): ?>
                                <span class="badge text-bg-danger ms-1"><?= e((string) $s['alerts']) ?> alerts</span>
                            <?php endif; ?>
                            <?php if (!empty($d['firmware_version'])): ?>
                                <div class="text-muted" style="font-size:.72rem;">
                                    firmware v<?= e($d['firmware_version']) ?>
                                    <?php if (!empty($d['ip_address'])): ?>
                                        &middot; <?= e($d['ip_address']) ?>
                                    <?php endif; ?>
                                    <?php if ($d['wifi_rssi'] !== null): ?>
                                        &middot; <?= e((string) (int) $d['wifi_rssi']) ?> dBm
                                    <?php endif; ?>
                                    <?php if ($d['uptime_seconds'] !== null): ?>
                                        &middot; up <?= e(fmt_duration((int) $d['uptime_seconds'])) ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><code class="small"><?= e($d['device_code']) ?></code></td>
                        <td class="small"><?= e($d['location'] ?: '--') ?></td>
                        <td class="small text-muted"><?= e($d['mac_address'] ?: '--') ?></td>
                        <td>
                            <?php
                            $statusBadge = match ($d['status']) {
                                'active' => 'success',
                                'maintenance' => 'warning',
                                default => 'secondary',
                            };
                            ?>
                            <span class="badge text-bg-<?= e($statusBadge) ?>"><?= e(ucfirst($d['status'])) ?></span>
                        </td>
                        <td>
                            <span class="ag-pill <?= (int) $d['is_online'] === 1 ? 'ag-pill-online' : 'ag-pill-offline' ?>">
                                <span class="ag-pill-dot"></span>
                                <?= (int) $d['is_online'] === 1 ? 'Online' : 'Offline' ?>
                            </span>
                        </td>
                        <td class="small text-nowrap"><?= e(time_ago($d['last_seen'])) ?></td>
                        <td class="num small"><?= e(number_format($s['readings'])) ?></td>
                        <td class="num small"><?= e(number_format($s['cycles'])) ?></td>
                        <td class="small text-nowrap"><?= e(fmt_date($d['created_at'])) ?></td>
                        <?php if ($isAdmin): ?>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="<?= e(url('devices/edit', ['id' => (int) $d['id']])) ?>"
                                   aria-label="Edit <?= e($d['device_name']) ?>"><?= icon('edit', 15) ?></a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="ag-card mt-3">
    <div class="ag-card-head">
        <h2 class="ag-card-title"><?= icon('info', 16) ?> Adding another ESP32</h2>
    </div>
    <div class="ag-card-body small text-muted">
        <p class="mb-2">
            The database and every screen are keyed on the device, so the system supports as many
            boards as you need. To add one:
        </p>
        <ol class="mb-0">
            <li>Register the device here to get its unique API key.</li>
            <li>Open <code>esp32/AgriSense.ino</code>, set <code>DEVICE_ID</code> and
                <code>DEVICE_API_KEY</code> to match, and flash the board.</li>
            <li>Set its thresholds under Irrigation Settings.</li>
        </ol>
    </div>
</div>
