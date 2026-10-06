<?php
/**
 * AgriSense - Date range selector shared by the history and report pages
 * (requirement 12).
 *
 * @var array $range From Controller::rangeFilter()
 */
?>
<div class="col-sm-auto">
    <label class="form-label" for="rangeSelect">Period</label>
    <select class="form-select form-select-sm" id="rangeSelect" name="range" data-range-select>
        <option value="today"     <?= $range['range'] === 'today' ? 'selected' : '' ?>>Today</option>
        <option value="yesterday" <?= $range['range'] === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
        <option value="7days"     <?= $range['range'] === '7days' ? 'selected' : '' ?>>Last 7 days</option>
        <option value="30days"    <?= $range['range'] === '30days' ? 'selected' : '' ?>>Last 30 days</option>
        <option value="custom"    <?= $range['range'] === 'custom' ? 'selected' : '' ?>>Custom range</option>
    </select>
</div>

<div class="col-sm-auto<?= $range['range'] === 'custom' ? '' : ' d-none' ?>" data-range-custom>
    <label class="form-label" for="rangeFrom">From</label>
    <input type="date" class="form-control form-control-sm" id="rangeFrom" name="from"
           value="<?= e($range['from']) ?>" max="<?= e(date('Y-m-d')) ?>">
</div>

<div class="col-sm-auto<?= $range['range'] === 'custom' ? '' : ' d-none' ?>" data-range-custom>
    <label class="form-label" for="rangeTo">To</label>
    <input type="date" class="form-control form-control-sm" id="rangeTo" name="to"
           value="<?= e($range['to']) ?>" max="<?= e(date('Y-m-d')) ?>">
</div>
