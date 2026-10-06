/* =====================================================================
   AgriSense - Core front-end behaviour
   Sidebar, CSRF-aware fetch wrapper, toasts, notification bell and the
   small conveniences shared by every page.
   ===================================================================== */
(function () {
    'use strict';

    const meta = (name) => {
        const el = document.querySelector(`meta[name="${name}"]`);
        return el ? el.getAttribute('content') : '';
    };

    const AgriSense = {
        basePath: meta('base-path'),
        csrf: meta('csrf-token'),

        /** Absolute URL for an application path. */
        url(path) {
            return this.basePath + '/' + String(path).replace(/^\/+/, '');
        },

        /**
         * fetch() wrapper that always asks for JSON, attaches the CSRF token
         * to writes, and turns an error envelope into a rejected promise so
         * callers only handle one failure path.
         */
        async api(path, options = {}) {
            const opts = Object.assign({ method: 'GET', headers: {} }, options);
            opts.headers = Object.assign({
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }, opts.headers);
            opts.credentials = 'same-origin';

            if (opts.body !== undefined && typeof opts.body !== 'string') {
                opts.headers['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(opts.body);
            }
            if (opts.method !== 'GET' && opts.method !== 'HEAD') {
                opts.headers['X-CSRF-Token'] = this.csrf;
            }

            const response = await fetch(this.url(path), opts);
            let payload;
            try {
                payload = await response.json();
            } catch (e) {
                throw new Error('The server returned an unreadable response.');
            }

            if (!response.ok || payload.success === false) {
                const error = new Error(payload.error || 'The request could not be completed.');
                error.status = response.status;
                error.payload = payload;
                throw error;
            }
            return payload;
        },

        /** Transient message in the corner. */
        toast(message, variant = 'success') {
            let host = document.getElementById('agToastHost');
            if (!host) {
                host = document.createElement('div');
                host.id = 'agToastHost';
                host.className = 'toast-container position-fixed bottom-0 end-0 p-3';
                host.style.zIndex = '1090';
                document.body.appendChild(host);
            }

            const el = document.createElement('div');
            el.className = `toast align-items-center text-bg-${variant} border-0`;
            el.setAttribute('role', 'alert');
            el.setAttribute('aria-live', 'assertive');
            el.innerHTML =
                '<div class="d-flex">' +
                '<div class="toast-body"></div>' +
                '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
                '</div>';
            el.querySelector('.toast-body').textContent = message;
            host.appendChild(el);

            const toast = new bootstrap.Toast(el, { delay: variant === 'danger' ? 8000 : 4000 });
            toast.show();
            el.addEventListener('hidden.bs.toast', () => el.remove());
        },

        /** Format a number, or an em dash when the value is unavailable. */
        num(value, decimals = 1, suffix = '') {
            if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) {
                return '--';
            }
            return Number(value).toFixed(decimals) + suffix;
        }
    };

    window.AgriSense = AgriSense;

    // -----------------------------------------------------------------
    // Sidebar (mobile)
    // -----------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', () => {
        const sidebar  = document.getElementById('agSidebar');
        const backdrop = document.getElementById('agBackdrop');
        const toggle   = document.getElementById('agMenuToggle');

        const close = () => {
            sidebar?.classList.remove('show');
            backdrop?.classList.remove('show');
        };

        toggle?.addEventListener('click', () => {
            sidebar?.classList.toggle('show');
            backdrop?.classList.toggle('show');
        });
        backdrop?.addEventListener('click', close);
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
    });

    // -----------------------------------------------------------------
    // Confirmation before a destructive or physical action
    // -----------------------------------------------------------------
    document.addEventListener('submit', (event) => {
        const form = event.target;
        const message = form.getAttribute('data-confirm');
        if (message && !form.dataset.confirmed) {
            event.preventDefault();
            if (window.confirm(message)) {
                form.dataset.confirmed = '1';
                form.submit();
            }
        }
    });

    // -----------------------------------------------------------------
    // Filter forms: re-submit when a select changes
    // -----------------------------------------------------------------
    document.addEventListener('change', (event) => {
        const el = event.target;
        if (el.matches('[data-autosubmit]')) {
            el.form?.requestSubmit ? el.form.requestSubmit() : el.form?.submit();
        }
    });

    // Show the custom date inputs only when "Custom range" is selected.
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-range-select]').forEach((select) => {
            const custom = document.querySelectorAll('[data-range-custom]');
            const sync = () => custom.forEach((el) => {
                el.classList.toggle('d-none', select.value !== 'custom');
            });
            select.addEventListener('change', sync);
            sync();
        });
    });

    // -----------------------------------------------------------------
    // Notification bell
    // -----------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', () => {
        const markAll = document.getElementById('agMarkAllRead');
        markAll?.addEventListener('click', async () => {
            markAll.disabled = true;
            try {
                const result = await AgriSense.api('api/alerts/read', {
                    method: 'POST',
                    body: { all: true }
                });
                AgriSense.setUnreadCount(0);
                document.querySelectorAll('#agNotifList .ag-alert-item')
                    .forEach((el) => el.classList.remove('is-unread'));
                markAll.remove();
                AgriSense.toast(result.message || 'All alerts marked as read.');
            } catch (error) {
                AgriSense.toast(error.message, 'danger');
                markAll.disabled = false;
            }
        });
    });

    /** Keep the bell badge and the sidebar badge in step. */
    AgriSense.setUnreadCount = function (count) {
        const badge = document.getElementById('agUnreadBadge');
        if (badge) {
            badge.textContent = String(Math.min(count, 99));
            badge.classList.toggle('d-none', count === 0);
        }
        const navBadge = document.querySelector('.ag-nav .nav-link .badge');
        if (navBadge) {
            navBadge.textContent = String(Math.min(count, 99));
            navBadge.classList.toggle('d-none', count === 0);
        }
    };
})();
