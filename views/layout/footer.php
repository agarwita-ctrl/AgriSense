        </main>

        <footer class="px-3 px-lg-4 pb-4 pt-2 text-muted small ag-no-print">
            <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> &mdash; <?= e(APP_TAGLINE) ?>
            <span class="mx-1">&middot;</span>
            Server time <?= e(date('d M Y, g:i A')) ?>
        </footer>
    </div>
</div>

<script src="<?= e(BASE_PATH) ?>/assets/vendor/bootstrap.bundle.min.js"></script>
<script src="<?= e(BASE_PATH) ?>/assets/vendor/chart.umd.min.js"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/charts.js')) ?>"></script>
<?php foreach ($GLOBALS['PAGE_SCRIPTS'] ?? [] as $script): ?>
    <script src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
