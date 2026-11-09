/* =====================================================================
   AgriSense - PWA glue

   - registers the service worker (secure contexts only)
   - shows a banner while the browser is offline
   - wires the optional "Install app" button

   Loaded as a plain external script. The app's base path (empty at the web
   root, "/AgriSense" under XAMPP) arrives as data-base-path on this tag.
   ===================================================================== */
(function () {
    'use strict';

    var tag = document.currentScript;
    var base = ((tag && tag.getAttribute('data-base-path')) || '').replace(/\/+$/, '');

    // -----------------------------------------------------------------
    // Service worker
    // -----------------------------------------------------------------
    if ('serviceWorker' in navigator && window.isSecureContext) {
        window.addEventListener('load', function () {
            navigator.serviceWorker
                .register(base + '/sw.js', { scope: base + '/' })
                .catch(function (error) {
                    console.warn('AgriSense: service worker not registered', error);
                });
        });
    }

    // -----------------------------------------------------------------
    // Offline banner
    // -----------------------------------------------------------------
    var banner = null;

    function syncBanner() {
        if (!banner) {
            if (navigator.onLine) return;
            banner = document.createElement('div');
            banner.className = 'ag-offline-banner';
            banner.setAttribute('role', 'status');
            banner.textContent = 'You are offline. Data may be out of date.';
            document.body.appendChild(banner);
        }
        banner.hidden = navigator.onLine;
    }

    window.addEventListener('online', syncBanner);
    window.addEventListener('offline', syncBanner);
    if (document.body) {
        syncBanner();
    } else {
        document.addEventListener('DOMContentLoaded', syncBanner);
    }

    // -----------------------------------------------------------------
    // Install button (only exists in the app header)
    // -----------------------------------------------------------------
    var installEvent = null;

    function installButton() {
        return document.getElementById('agInstallBtn');
    }

    function isInstalled() {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    }

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        installEvent = event;
        var button = installButton();
        if (button && !isInstalled()) button.classList.remove('d-none');
    });

    window.addEventListener('appinstalled', function () {
        installEvent = null;
        var button = installButton();
        if (button) button.classList.add('d-none');
    });

    document.addEventListener('DOMContentLoaded', function () {
        var button = installButton();
        if (!button) return;

        button.addEventListener('click', function () {
            if (!installEvent) return;
            installEvent.prompt();
            installEvent.userChoice.then(function () {
                installEvent = null;
                button.classList.add('d-none');
            });
        });
    });
})();
