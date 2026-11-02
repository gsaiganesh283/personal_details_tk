/**
 * Service worker for Personal Details Saver.
 *
 * This app holds financial/personal data, so the caching rule is
 * deliberately narrow: only static assets under /assets/ are ever cached.
 * Every .php request -- pages, API calls, document/image previews served
 * through PHP -- is left untouched and always goes to the network. That's
 * an allowlist on URL *path*, not on request.destination, because e.g.
 * view-document.php?... has an "image" destination but must never be
 * cache-first-served (would risk showing another session's or a stale
 * document).
 *
 * Deliberately not BASE_PATH-aware (unlike manifest.php) -- matching by
 * pathname pattern instead works the same whether the site is deployed at
 * the domain root or in a subdirectory, with no server-side templating
 * needed for a file the browser fetches as a static script.
 */

const CACHE_NAME = 'pds-static-v1';
const STATIC_ASSET_RE = /\/assets\/.*\.(?:css|js|png|jpg|jpeg|svg|webp|woff2?|ico)(?:\?.*)?$/i;

// Resolve offline.html/icon relative to this script's own location, not the
// origin root -- works whether the site is deployed at the domain root or a
// subdirectory, without needing BASE_PATH.
const OFFLINE_URL = new URL('offline.html', self.location.href).href;
const OFFLINE_ICON_URL = new URL('assets/icons/icon-96.png', self.location.href).href;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll([OFFLINE_URL, OFFLINE_ICON_URL]))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Mutations (POST/PUT/DELETE forms, AJAX writes) always hit the network.
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Third-party requests (Font Awesome CDN, etc.) always hit the network --
    // no benefit to caching them here, and it keeps the allowlist simple.
    if (url.origin !== self.location.origin) {
        return;
    }

    if (STATIC_ASSET_RE.test(url.pathname)) {
        // Cache-first for static assets.
        event.respondWith(
            caches.match(request).then((cached) => {
                if (cached) return cached;
                return fetch(request).then((response) => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
                    }
                    return response;
                });
            })
        );
        return;
    }

    if (request.mode === 'navigate') {
        // Network-first for page loads; fall back to a static, data-free
        // offline page only when there's genuinely no connection. Never
        // serve a cached copy of an actual data page.
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL))
        );
        return;
    }

    // Everything else -- .php API/data calls, document previews, uploads --
    // is intentionally left alone (no respondWith call), so it always goes
    // straight to the network exactly as if no service worker existed.
});
