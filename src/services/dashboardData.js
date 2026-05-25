// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Shared, lightly-cached data fetcher for the manifest-driven Dashboard.
//
// The 8 dashboard widgets (4 KPI cards + 4 panels) each render
// independently from the manifest. To avoid 4–6 duplicate REST calls
// per page load (each KPI widget needs leads + pipelines, etc.), this
// module memoises one in-flight promise per dataset for a short TTL.
//
// The cache TTL matches the Dashboard's old auto-refresh interval
// (5 min). Call `invalidateDashboardData()` to force a refetch (used
// by the "Refresh" header action).

import { initializeStores } from '../store/store.js'

const CACHE_TTL_MS = 5 * 60 * 1000
const cache = new Map()

/**
 * Build the OR query URL for a registered object type.
 *
 * @param {object} typeConfig - { register, schema } from objectTypeRegistry.
 * @param {object} params - Query parameters.
 * @return {string} Fully-qualified path.
 */
function buildUrl(typeConfig, params = {}) {
	const queryParams = new URLSearchParams()
	for (const [key, value] of Object.entries(params)) {
		if (value === undefined || value === null || value === '') continue
		queryParams.set(key, value)
	}
	return '/apps/openregister/api/objects/'
		+ typeConfig.register + '/' + typeConfig.schema
		+ (queryParams.toString() ? '?' + queryParams.toString() : '')
}

/**
 * Raw fetch helper — identical to the per-widget fetchRaw the legacy
 * widgets defined locally. Returns `[]` when the object type is not
 * registered in the app's settings (graceful no-op).
 *
 * @param {string} type - Object type slug (lead, request, pipeline, …).
 * @param {object} params - Query parameters.
 * @return {Promise<Array>} Array of object records.
 */
async function fetchRaw(type, params = {}) {
	const { objectStore } = await initializeStores()
	const typeConfig = objectStore.objectTypeRegistry[type]
	if (!typeConfig) return []

	const response = await fetch(buildUrl(typeConfig, params), {
		headers: {
			'Content-Type': 'application/json',
			requesttoken: OC.requestToken,
			'OCS-APIREQUEST': 'true',
		},
	})

	if (!response.ok) throw new Error('Failed to fetch ' + type)
	const data = await response.json()
	return data.results || data || []
}

/**
 * Get a cached dataset, fetching once and reusing the result across
 * widgets that mount during the same render pass. Cache entries
 * expire after CACHE_TTL_MS to keep the Dashboard reasonably fresh
 * without an explicit refresh.
 *
 * @param {string} key - Unique cache key (e.g. 'lead', 'lead:mine').
 * @param {Function} fetcher - Promise-returning fetcher invoked on miss.
 * @return {Promise<Array>} The cached or freshly fetched data.
 */
function cached(key, fetcher) {
	const entry = cache.get(key)
	const now = Date.now()
	if (entry && (now - entry.timestamp) < CACHE_TTL_MS) {
		return entry.promise
	}
	const promise = fetcher().catch(err => {
		// On error, drop the cache so the next mount retries.
		cache.delete(key)
		throw err
	})
	cache.set(key, { promise, timestamp: now })
	return promise
}

export function getLeads() {
	return cached('lead', () => fetchRaw('lead', { _limit: 500 }))
}

export function getRequests() {
	return cached('request', () => fetchRaw('request', { _limit: 500 }))
}

export function getPipelines() {
	return cached('pipeline', () => fetchRaw('pipeline', { _limit: 100 }))
}

export function getComplaints() {
	return cached('complaint', () => fetchRaw('complaint', { _limit: 500 }))
}

export function getClients() {
	return cached('client', () => fetchRaw('client', { _limit: 500 }))
}

export function getMyLeads() {
	if (!OC.currentUser) return Promise.resolve([])
	return cached('lead:mine', () => fetchRaw('lead', {
		assignee: OC.currentUser,
		_limit: 200,
	}))
}

export function getMyRequests() {
	if (!OC.currentUser) return Promise.resolve([])
	return cached('request:mine', () => fetchRaw('request', {
		assignee: OC.currentUser,
		_limit: 200,
	}))
}

/**
 * Drop every cached dataset. Call from a "Refresh" UI action or
 * after creating a new object so the dashboard reflects the change
 * on the next widget mount/remount.
 */
export function invalidateDashboardData() {
	cache.clear()
}

/**
 * Compute the set of stage names that mark a pipeline as closed.
 * Shared helper because both Open Leads and Pipeline Value KPIs
 * (plus My Work) need it.
 *
 * @param {Array} pipelines - Pipeline records.
 * @return {Set<string>} Names of stages flagged isClosed.
 */
export function getClosedStageNames(pipelines) {
	const names = new Set()
	for (const p of pipelines || []) {
		if (!p.stages) continue
		for (const s of p.stages) {
			if (s.isClosed) names.add(s.name)
		}
	}
	return names
}
