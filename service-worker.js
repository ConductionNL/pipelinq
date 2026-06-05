// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Pipelinq mobile timer — app-shell service worker.
//
// Strategy:
//   - Static assets (JS/CSS/images): cache-first, pre-cached on install.
//   - Nextcloud API calls (/ocs/, /apps/openregister/api/): network-first;
//     fall through to cache on failure (read-only fallback).
//   - All other navigation requests: network-first; serve cached shell on
//     failure so the timer UI loads offline.
//
// @spec openspec/changes/time-entry-mobile/tasks.md#task-1.1

const CACHE_NAME = 'pipelinq-shell-v1'

// Core app-shell assets to pre-cache on install.
const PRECACHE_URLS = [
	'/index.php/apps/pipelinq/',
	'/index.php/apps/pipelinq/timer',
]

// Install: pre-cache the app shell.
self.addEventListener('install', (event) => {
	event.waitUntil(
		caches.open(CACHE_NAME).then((cache) => {
			// Swallow individual URL failures — a missing asset must not block
			// the service worker install.
			return Promise.allSettled(
				PRECACHE_URLS.map((url) => cache.add(url).catch(() => {})),
			)
		}).then(() => self.skipWaiting()),
	)
})

// Activate: delete stale caches from prior versions.
self.addEventListener('activate', (event) => {
	event.waitUntil(
		caches.keys().then((keys) => {
			return Promise.all(
				keys
					.filter((key) => key !== CACHE_NAME)
					.map((key) => caches.delete(key)),
			)
		}).then(() => self.clients.claim()),
	)
})

// Fetch: route by request type.
self.addEventListener('fetch', (event) => {
	const { request } = event
	const url = new URL(request.url)

	// Only intercept same-origin GET requests.
	if (request.method !== 'GET' || url.origin !== self.location.origin) {
		return
	}

	// API calls — network-first, no offline fallback (mutations would be stale).
	if (url.pathname.startsWith('/ocs/') || url.pathname.includes('/api/')) {
		event.respondWith(
			fetch(request).catch(() => caches.match(request)),
		)
		return
	}

	// Static assets under /apps/pipelinq/js/ and /apps/pipelinq/css/ —
	// cache-first for faster repeat loads.
	if (url.pathname.startsWith('/apps/pipelinq/js/')
		|| url.pathname.startsWith('/apps/pipelinq/css/')
		|| url.pathname.startsWith('/custom_apps/pipelinq/js/')
		|| url.pathname.startsWith('/custom_apps/pipelinq/css/')) {
		event.respondWith(
			caches.match(request).then((cached) => {
				if (cached) {
					return cached
				}
				return fetch(request).then((response) => {
					const clone = response.clone()
					caches.open(CACHE_NAME).then((cache) => cache.put(request, clone))
					return response
				})
			}),
		)
		return
	}

	// Navigation requests — network-first; fall back to cached shell so the
	// timer UI renders offline.
	if (request.mode === 'navigate') {
		event.respondWith(
			fetch(request).catch(() => {
				return caches.match('/index.php/apps/pipelinq/timer')
					.then((cached) => cached || caches.match('/index.php/apps/pipelinq/'))
			}),
		)
	}
})
