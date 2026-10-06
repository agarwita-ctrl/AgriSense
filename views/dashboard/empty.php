<?php
/**
 * AgriSense - Shown when no ESP32 has been registered yet.
 *
 * @var bool $isAdmin
 */
?>
<div class="ag-card">
    <div class="ag-card-body text-center py-5">
        <div class="mb-3 text-muted"><?= icon('device', 44) ?></div>
        <h2 class="h5 fw-bold">No irrigation device registered yet</h2>

        <?php if ($isAdmin): ?>
            <p class="text-muted mx-auto mb-4" style="max-width: 34rem;">
                Register your ESP32 to start collecting soil moisture, temperature and humidity
                readings. You will get an API key to paste into the firmware, and the dashboard
                will come alive as soon as the board sends its first reading.
            </p>
            <a class="btn btn-ag" href="<?= e(url('devices/create')) ?>">
                <?= icon('plus', 16) ?> Register a device
            </a>
        <?php else: ?>
            <p class="text-muted mx-auto mb-0" style="max-width: 34rem;">
                Your administrator has not registered an irrigation device yet.
                Once a device is added and sending data, your dashboard will appear here.
            </p>
        <?php endif; ?>
    </div>
</div>
