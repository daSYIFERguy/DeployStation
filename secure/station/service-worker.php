<?php

declare(strict_types=1);

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

?>
// Bumped from v1 because the previous version served assets/style.css
// cache-first, which prevented CSS updates (e.g. clipboard FAB visibility
// rules) from reaching returning visitors.
// v4: standalone / installed PWA clients were keeping stale viewer + workspace
// bundles via HTTP cache; force network reload for those URLs.
const CACHE_NAME = 'deploystation-shell-v4';
const CORE_ASSETS = [
  './assets/style.css',
  './index.php',
  './setup.php'
];

function pathnameNeedsAlwaysFresh(pathname) {
  if (pathname.endsWith('/viewer.php') || pathname === 'viewer.php') {
    return true;
  }
  return (
    pathname.endsWith('/assets/project-workspace.css') ||
    pathname.endsWith('/assets/project-workspace.js') ||
    pathname.endsWith('/assets/station-shell-nav.js')
  );
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(CORE_ASSETS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
    )).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') {
    return;
  }

  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin) {
    return;
  }

  // Network-first for the stylesheet so deploys take effect on next page
  // load. Falls back to the cached copy only when offline.
  if (url.pathname.endsWith('/assets/style.css')) {
    event.respondWith(
      fetch(new Request(event.request, { cache: 'reload', credentials: event.request.credentials }))
        .then((response) => {
          const copy = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
          return response;
        })
        .catch(() => caches.match(event.request))
    );
    return;
  }

  // Avoid stale workspace UI in installed PWAs (Safari / Chrome standalone).
  if (pathnameNeedsAlwaysFresh(url.pathname)) {
    event.respondWith(
      fetch(new Request(event.request, { cache: 'reload', credentials: event.request.credentials }))
    );
  }
});
