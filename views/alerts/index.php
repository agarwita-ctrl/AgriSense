<?php
/**
 * AgriSense - Alerts (requirement 15)
 *
 * @var array $devices
 * @var array $rows
 * @var array $meta
 * @var array $filters
 * @var array $range
 * @var array $summary
 */
$exportQuery = $_GET;
unset($exportQuery['page'], $exportQuery['r']);
?>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="ag-metric">
            <div class="ag-metric-label">Total alerts</div>
            <div class="ag-metric-value" style="font-size:1.6rem;"><?= e(number_format($summary['total'])) ?></div>
            <div class="ag-metric-note">all time</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ag-metric<?= $summary['unread'] > 0 ? ' is-temp' : '' ?>">
            <div class="ag-metric-label">Unread</div>
            <div class="ag-metric-value" style="font-size:1.6rem;"><?= e(number_format($summary['unread'])) ?></div>
            <div class="ag-metric-note">need attention</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ag-metric<?= $summary['critical'] > 0 ? ' is-critical' : '' ?>">
            <div class="ag-metric-label">Critical</div>
            <div class="ag-metric-value" style="font-size:1.6rem;"><?= e(number_format($summary['critical'])) ?></div>
            <div class="ag-metric-note">device or safety problems</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ag-metric is-temp">
            <div class="ag-metric-label">Warnings</div>
            <div class="ag-metric-value" style="font-size:1.6rem;"><?= e(number_format($summary['warning'])) ?></div>
            <div class="ag-metric-note">worth checking</div>
        </div>
    </div>
</div>

<div class="ag-card">
    <div class="ag-card-head">
        <h2 class="ag-card-title"><?= icon('bell', 16) ?> Alert log</h2>
        <div class="ms-auto d-flex gap-2 ag-no-print">
            <?php if ($summary['unread'] > 0): ?>
                <form method="post" action="<?= e(url('alerts/read-all')) ?>" class="m-0">
                    <?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-ag" type="submit">
                        <?= icon('check', 15) ?> Mark all read
                    </button>
                </form>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline-ag" href="<?= e(url('alerts/export', $exportQuery)) ?>">
                <?= icon('download', 15) ?> Export CSV
            </a>
        </div>
    </div>

    <div class="ag-card-body border-bottom ag-no-print">
        <form method="get" action="<?= e(url('alerts')) ?>" class="row g-2 align-items-end">

            <div class="col-12 col-md">
                <label class="form-label" for="searchInput">Search</label>
                <input type="search" class="form-control form-control-sm" id="searchInput" name="search"
                       value="<?= e($filters['search']) ?>" placeholder="Message or type">
            </div>

            <?php require APP_ROOT . '/views/layout/range_filter.php'; ?>

            <div class="col-sm-auto">
                <label class="form-label" for="deviceFilter">Device</label>
                <select class="form-select form-select-sm" id="deviceFilter" name="device_id">
                    <option value="">All devices</option>
                    <?php foreach ($devices as $d): ?>
                        <option value="<?= e((string) $d['id']) ?>"
                            <?= (int) ($filters['device_id'] ?? 0) === (int) $d['id'] ? 'selected' : '' ?>>
                            <?= e($d['device_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-sm-auto">
                <label class="form-label" for="severityFilter">Severity</label>
                <select class="form-select form-select-sm" id="severityFilter" name="severity">
                    <option value="">Any</option>
                    <?php foreach (Alert::SEVERITIES as $s): ?>
                        <option value="<?= e($s) ?>" <?= $filters['severity'] === $s ? 'selected' : '' ?>>
                            <?= e(ucfirst($s === 'info' ? 'information' : $s)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-sm-auto">
                <label class="form-label" for="typeFilter">Type</label>
                <select class="form-select form-select-sm" id="typeFilter" name="alert_type">
                    <option value="">Any</option>
                    <?php foreach (Alert::TYPES as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $filters['alert_type'] === $key ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-sm-auto">
                <label class="form-label" for="readFilter">Status</label>
                <select class="form-select form-select-sm" id="readFilter" name="is_read">
                    <option value="">Any</option>
                    <option value="0" <?= $filters['is_read'] === '0' ? 'selected' : '' ?>>Unread</option>
                    <option value="1" <?= $filters['is_read'] === '1' ? 'selected' : '' ?>>Read</option>
                </select>
            </div>

            <div class="col-sm-auto d-flex gap-2">
                <button class="btn btn-sm btn-ag" type="submit"><?= icon('search', 15) ?> Apply</button>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('alerts')) ?>">Reset</a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table ag-table table-hover mb-0">
            <thead>
            <tr>
                <th>Date / time</th>
                <th>Severity</th>
                <th>Type</th>
                <th>Message</th>
                <th>Device</th>
                <th>Status</th>
                <th class="ag-no-print"></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="7">
                        <div class="ag-empty">
                            No alerts match these filters.<br>
                            <span class="small">That usually means the system is running normally.</span>
                        </div>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $a): ?>
                    <?php $unread = (int) $a['is_read'] === 0; ?>
                    <tr<?= $unread ? ' class="fw-semibold"' : '' ?>>
                        <td class="text-nowrap small"><?= e(fmt_datetime($a['created_at'])) ?></td>
                        <td>
                            <span class="badge text-bg-<?= e(severity_class($a['severity'])) ?>">
                                <?= e($a['severity'] === 'info' ? 'Information' : ucfirst($a['severity'])) ?>
                            </span>
                        </td>
                        <td class="small"><?= e(Alert::label($a['alert_type'])) ?></td>
                        <td class="small fw-normal"><?= e($a['message']) ?></td>
                        <td class="small fw-normal"><?= e($a['device_name'] ?? 'System') ?></td>
                        <td>
                            <?php if ($unread): ?>
                                <span class="ag-pill ag-pill-offline">Unread</span>
                            <?php else: ?>
                                <span class="ag-pill ag-pill-off">Read</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap ag-no-print">
                            <form method="post" class="d-inline m-0"
                                  action="<?= e(url($unread ? 'alerts/read' : 'alerts/unread')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= e((string) $a['id']) ?>">
                                <button class="btn btn-sm btn-outline-secondary" type="submit"
                                        title="<?= $unread ? 'Mark as read' : 'Mark as unread' ?>">
                                    <?= icon($unread ? 'check' : 'refresh', 14) ?>
                                </button>
                            </form>
                            <?php if (Auth::isAdmin()): ?>
                                <form method="post" action="<?= e(url('alerts/delete')) ?>" class="d-inline m-0"
                                      data-confirm="Delete this alert permanently?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= e((string) $a['id']) ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete">
                                        <?= icon('trash', 14) ?>
                                    </button>
                                </form>
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
