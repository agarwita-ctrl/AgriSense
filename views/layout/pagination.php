<?php
/**
 * AgriSense - Pagination control.
 *
 * Preserves every current filter in the query string so paging never
 * silently drops the user's search.
 *
 * @var array $meta  From paginate(): page, per_page, total, pages, offset
 */
if (empty($meta) || $meta['pages'] <= 1) {
    if (!empty($meta) && $meta['total'] > 0) {
        echo '<div class="text-muted small px-3 py-2">Showing all '
            . e((string) $meta['total']) . ' record' . ($meta['total'] === 1 ? '' : 's') . '.</div>';
    }

    return;
}

$route = $GLOBALS['CURRENT_ROUTE'] ?? 'dashboard';

/** Rebuild the current query string with a different page number. */
$pageUrl = static function (int $page) use ($route): string {
    $query = $_GET;
    $query['page'] = $page;
    unset($query['r']);

    return url($route, $query);
};

$current = $meta['page'];
$last    = $meta['pages'];

// A compact window around the current page: 1 ... 4 5 [6] 7 8 ... 20
$window = [];
foreach ([1, 2, $current - 1, $current, $current + 1, $last - 1, $last] as $p) {
    if ($p >= 1 && $p <= $last) {
        $window[$p] = true;
    }
}
$pages = array_keys($window);
sort($pages);

$from = $meta['offset'] + 1;
$to   = min($meta['offset'] + $meta['per_page'], $meta['total']);
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 px-3 py-2 border-top">
    <div class="text-muted small">
        Showing <?= e((string) $from) ?>&ndash;<?= e((string) $to) ?>
        of <?= e(number_format($meta['total'])) ?> records
    </div>

    <nav aria-label="Pagination">
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item<?= $current <= 1 ? ' disabled' : '' ?>">
                <a class="page-link" href="<?= e($pageUrl(max(1, $current - 1))) ?>"
                   aria-label="Previous page">&laquo;</a>
            </li>

            <?php $previous = 0; ?>
            <?php foreach ($pages as $p): ?>
                <?php if ($previous && $p > $previous + 1): ?>
                    <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                <?php endif; ?>
                <li class="page-item<?= $p === $current ? ' active' : '' ?>">
                    <a class="page-link" href="<?= e($pageUrl($p)) ?>"
                       <?= $p === $current ? 'aria-current="page"' : '' ?>><?= e((string) $p) ?></a>
                </li>
                <?php $previous = $p; ?>
            <?php endforeach; ?>

            <li class="page-item<?= $current >= $last ? ' disabled' : '' ?>">
                <a class="page-link" href="<?= e($pageUrl(min($last, $current + 1))) ?>"
                   aria-label="Next page">&raquo;</a>
            </li>
        </ul>
    </nav>
</div>
