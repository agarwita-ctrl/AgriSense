<?php
/**
 * AgriSense - Page shell: sidebar navigation, topbar and flash messages.
 *
 * Expects $pageTitle, $activeNav and optionally $bodyClass from render().
 */

require_once APP_ROOT . '/views/layout/icons.php';

$currentUser = Auth::user();
$isAdmin     = Auth::isAdmin();
$unread      = $currentUser ? Alert::unreadCount() : 0;
$bellAlerts  = $currentUser ? Alert::recent(6) : [];
$deviceParam = !empty($_SESSION['device_id']) ? ['device' => (int) $_SESSION['device_id']] : [];

/** One sidebar entry. */
$navItem = static function (string $key, string $label, string $route, string $iconName,
                            array $query = [], ?int $badge = null) use ($activeNav): string {
    $isActive = $activeNav === $key;

    return sprintf(
        '<a class="nav-link%s" href="%s">%s<span>%s</span>%s</a>',
        $isActive ? ' active' : '',
        e(url($route, $query)),
        icon($iconName),
        e($label),
        $badge ? '<span class="badge text-bg-danger rounded-pill">' . e((string) min($badge, 99)) . '</span>' : ''
    );
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="base-path" content="<?= e(BASE_PATH) ?>">
    <title><?= e($pageTitle) ?> &middot; <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e(asset('images/favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= e(BASE_PATH) ?>/assets/vendor/bootstrap.min.css">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<div class="ag-shell">

    <!-- ============ Sidebar ============ -->
    <aside class="ag-sidebar" id="agSidebar">
        <a class="ag-brand" href="<?= e(url('dashboard')) ?>">
            <span class="ag-brand-mark">
                <img src="<?= e(asset('images/logo.svg')) ?>" width="26" height="26" alt="">
            </span>
            <span>
                <span class="ag-brand-name d-block"><?= e(APP_NAME) ?></span>
                <span class="ag-brand-sub">Smart Irrigation</span>
            </span>
        </a>

        <nav class="ag-nav nav flex-column" aria-label="Main navigation">
            <?= $navItem('dashboard', 'Dashboard', 'dashboard', 'dashboard', $deviceParam) ?>
            <?= $navItem('monitor', 'Real-Time Monitoring', 'monitor', 'monitor', $deviceParam) ?>

            <div class="ag-nav-heading">Records</div>
            <?= $navItem('sensors', 'Sensor History', 'sensors/history', 'sensor', $deviceParam) ?>
            <?= $navItem('irrigation', 'Irrigation History', 'irrigation/history', 'water', $deviceParam) ?>
            <?= $navItem('alerts', 'Alerts', 'alerts', 'bell', [], $unread) ?>
            <?= $navItem('reports', 'Reports', 'reports', 'report', $deviceParam) ?>

            <div class="ag-nav-heading">Configuration</div>
            <?= $navItem('devices', 'Devices', 'devices', 'device') ?>
            <?= $navItem('settings', 'Irrigation Settings', 'irrigation/settings', 'settings', $deviceParam) ?>

            <?php if ($isAdmin): ?>
                <div class="ag-nav-heading">Administration</div>
                <?= $navItem('users', 'Users', 'users', 'users') ?>
                <?= $navItem('logs', 'System Logs', 'logs', 'logs') ?>
            <?php endif; ?>
        </nav>
    </aside>
    <div class="ag-backdrop" id="agBackdrop"></div>

    <!-- ============ Main ============ -->
    <div class="ag-main">

        <header class="ag-topbar d-flex align-items-center gap-2">
            <button class="btn btn-sm btn-outline-secondary d-lg-none" id="agMenuToggle"
                    type="button" aria-label="Open navigation" aria-controls="agSidebar">
                <?= icon('menu') ?>
            </button>

            <h1 class="ag-page-title flex-grow-1 text-truncate"><?= e($pageTitle) ?></h1>

            <?php if ($currentUser): ?>
                <!-- Notifications -->
                <div class="dropdown">
                    <button class="btn btn-sm btn-light ag-bell" type="button"
                            data-bs-toggle="dropdown" data-bs-auto-close="outside"
                            aria-expanded="false" aria-label="Notifications">
                        <?= icon('bell', 20) ?>
                        <span class="badge rounded-pill text-bg-danger<?= $unread ? '' : ' d-none' ?>"
                              id="agUnreadBadge"><?= e((string) min($unread, 99)) ?></span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end shadow" style="width: 22rem;">
                        <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
                            <strong class="small">Notifications</strong>
                            <?php if ($unread > 0): ?>
                                <button class="btn btn-link btn-sm p-0 text-decoration-none"
                                        id="agMarkAllRead" type="button">Mark all read</button>
                            <?php endif; ?>
                        </div>
                        <div class="ag-notif-list" id="agNotifList">
                            <?php if (!$bellAlerts): ?>
                                <p class="text-muted small px-3 py-4 mb-0 text-center">
                                    No alerts yet. The system will notify you here.
                                </p>
                            <?php else: ?>
                                <?php foreach ($bellAlerts as $a): ?>
                                    <div class="ag-alert-item<?= (int) $a['is_read'] === 0 ? ' is-unread' : '' ?>">
                                        <span class="ag-alert-bar sev-<?= e($a['severity']) ?>"></span>
                                        <div class="flex-grow-1 small">
                                            <div class="fw-semibold"><?= e(Alert::label($a['alert_type'])) ?></div>
                                            <div class="text-muted"><?= e($a['message']) ?></div>
                                            <div class="text-muted mt-1" style="font-size:.72rem;">
                                                <?= e($a['device_name'] ?? 'System') ?> &middot; <?= e(time_ago($a['created_at'])) ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <a class="dropdown-item text-center small border-top py-2"
                           href="<?= e(url('alerts')) ?>">View all alerts</a>
                    </div>
                </div>

                <!-- Account -->
                <div class="dropdown">
                    <button class="btn btn-sm btn-light d-flex align-items-center gap-2"
                            type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <?= icon('user', 18) ?>
                        <span class="d-none d-sm-inline"><?= e($currentUser['name']) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li class="px-3 py-2 border-bottom">
                            <div class="fw-semibold small"><?= e($currentUser['name']) ?></div>
                            <div class="text-muted" style="font-size:.75rem;">
                                <?= e($currentUser['role'] === 'admin' ? 'Administrator' : 'Farmer') ?>
                                &middot; @<?= e($currentUser['username']) ?>
                            </div>
                        </li>
                        <li><a class="dropdown-item" href="<?= e(url('profile')) ?>">My account</a></li>
                        <li>
                            <form method="post" action="<?= e(url('logout')) ?>" class="m-0">
                                <?= csrf_field() ?>
                                <button class="dropdown-item text-danger" type="submit">Sign out</button>
                            </form>
                        </li>
                    </ul>
                </div>
            <?php endif; ?>
        </header>

        <main class="p-3 p-lg-4">
            <?php foreach (take_flashes() as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show ag-no-print" role="alert">
                    <?= e($flash['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endforeach; ?>
