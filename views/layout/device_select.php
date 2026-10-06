<?php
/**
 * AgriSense - Device picker.
 *
 * Shown on every device-scoped page. Kept as a plain GET form so the choice
 * survives a bookmark or a page reload.
 *
 * @var array $devices All registered devices
 * @var array $device  The currently selected device
 */
if (count($devices) <= 1 && !empty($device)) {
    // With a single device a dropdown would be noise - show its identity.
    ?>
    <div class="d-flex align-items-center gap-2 text-muted small">
        <?= icon('device', 16) ?>
        <span class="fw-semibold text-body"><?= e($device['device_name']) ?></span>
        <span class="d-none d-sm-inline">&middot; <?= e($device['device_code']) ?></span>
        <?php if (!empty($device['location'])): ?>
            <span class="d-none d-md-inline">&middot; <?= e($device['location']) ?></span>
        <?php endif; ?>
    </div>
    <?php
    return;
}
?>
<form method="get" action="<?= e(url($GLOBALS['CURRENT_ROUTE'] ?? 'dashboard')) ?>"
      class="d-flex align-items-center gap-2 ag-no-print">
    <?php
    // Carry the other active filters through the device switch.
    foreach ($_GET as $key => $value) {
        if (in_array($key, ['device', 'page', 'r'], true) || !is_scalar($value)) {
            continue;
        }
        echo '<input type="hidden" name="' . e($key) . '" value="' . e((string) $value) . '">';
    }
    ?>
    <label class="form-label mb-0 small text-muted d-none d-sm-block" for="deviceSelect">Device</label>
    <select class="form-select form-select-sm" id="deviceSelect" name="device"
            data-autosubmit style="min-width: 13rem;">
        <?php foreach ($devices as $d): ?>
            <option value="<?= e((string) $d['id']) ?>"
                <?= (int) $d['id'] === (int) ($device['id'] ?? 0) ? 'selected' : '' ?>>
                <?= e($d['device_name']) ?> (<?= e($d['device_code']) ?>)<?= $d['status'] !== 'active' ? ' - ' . e($d['status']) : '' ?>
            </option>
        <?php endforeach; ?>
    </select>
    <noscript><button class="btn btn-sm btn-outline-secondary" type="submit">Go</button></noscript>
</form>
