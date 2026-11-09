/* =====================================================================
   AgriSense - service worker

   Deliberately tiny. It exists so the installed app has a shell and a
   sensible offline page; it is NOT a data cache. The dashboard drives a
   real irrigation pump, so a cached reading or page would be a lie.

   Cached:       /assets/* (cache-first) and the offline page.
   Never touched: /api/*, anything that is not a GET, login, logout,
                  profile and CSV exports. Those go straight to the network.

   Works from the web root or from a subfolder (XAMPP): every path is
   resolved against the worker's own scope, nothing is hard-coded.

   To ship a change to the precache list or to force every browser to drop
   its cached assets, bump CACHE_VERSION below.
   ===================================================================== */
'use strict';

const CACHE_VERSION = 'v1';
const CACHE_PREFIX = 'agrisense-static-';
const CACHE = CACHE_PREFIX + CACHE_VERSION;

const SCOPE = self.registration.scope;               // always ends with "/"
const SCOPE_PATH = new URL(SCOPE).pathname;

const OFFLINE_URL = SCOPE + 'offline.html';

// Files that carry no ?v= fingerprint. App CSS/JS are requested as
// ".../app.css?v=<mtime>", so they are cached by their exact URL the first
// time a page loads them (see cacheFirst) - a fixed list could not know the
// version and would pin pages to stale code.
const PRECACHE = [
    'offline.html',
    'assets/css/offline.css',
    'assets/js/offline.js',
    'assets/vendor/bootstrap.min.css',
    'assets/vendor/bootstrap.bundle.min.js',
    'assets/vendor/chart.umd.min.js',
    'assets/images/favicon.svg',
    'assets/images/logo.svg',
    'assets/images/icons/icon-192.png',
    'assets/images/icons/icon-512.png',
    'assets/images/icons/icon-maskable-512.png',
    'assets/images/icons/apple-touch-icon.png'
].map((path) => SCOPE + path);

// Navigations the worker must leave completely alone.
const UNTOUCHED = /^(login|logout|profile)$|(^|\/)export$/;

// No skipWaiting() / clients.claim(): a new worker waits until the old one's
// tabs are closed, so one page never runs half on old and half on new code.
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE).then((cache) =>
            cache.addAll(PRECACHE.map((url) => new Request(url, { cache: 'reload' })))
        )
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter((key) => key.startsWith(CACHE_PREFIX) && key !== CACHE)
                .map((key) => caches.delete(key))
        ))
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin || !url.pathname.startsWith(SCOPE_PATH)) return;

    const path = url.pathname.slice(SCOPE_PATH.length);

    if (path.startsWith('api/')) return;

    if (path.startsWith('assets/')) {
        event.respondWith(cacheFirst(request, url));
        return;
    }

    // Page loads: always the network. Only a *failed* load gets the offline page.
    if (request.mode === 'navigate' && !UNTOUCHED.test(path.replace(/\/+$/, ''))) {
        event.respondWith(fetch(request).catch(offlinePage));
    }
});

async function cacheFirst(request, url) {
    const cache = await caches.open(CACHE);
    const hit = await cache.match(request);
    if (hit) return hit;

    const response = await fetch(request);
    if (response.ok && response.type === 'basic' && response.status === 200) {
        // Drop older fingerprints of the same file so the cache cannot grow.
        // Entries with no query string are the precached copies; keep those.
        for (const key of await cache.keys()) {
            const known = new URL(key.url);
            if (url.search && known.search && known.pathname === url.pathname && known.search !== url.search) {
                await cache.delete(key);
            }
        }
        await cache.put(request, response.clone());
    }
    return response;
}

/**
 * offline.html is served in place of whatever page failed, so its relative
 * links would resolve against that page's URL. Pin them to the scope.
 */
async function offlinePage() {
    const cached = await caches.match(OFFLINE_URL);
    if (!cached) return Response.error();

    const html = (await cached.text()).replace('<base href="./">', '<base href="' + SCOPE + '">');
    return new Response(html, {
        status: 200,
        headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' }
    });
}
