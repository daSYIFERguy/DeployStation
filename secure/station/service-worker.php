<?php

declare(strict_types=1);

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

?>
const CACHE_NAME = 'deploystation-shell-v1';
const CORE_ASSETS = [
  './assets/style.css',
  './index.php',
  './setup.php'
];

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

  if (url.pathname.endsWith('/assets/style.css')) {
    event.respondWith(
      caches.match(event.request).then((cached) => {
        const network = fetch(event.request).then((response) => {
          const copy = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
          return response;
        }).catch(() => cached);
        return cached || network;
      })
    );
  }
});