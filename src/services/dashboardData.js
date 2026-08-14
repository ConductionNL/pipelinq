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

import { reactive } from 'vue'
import { generateUrl } from '@nextcloud/router'
import { initializeStores } from '../store/store.js'

const CACHE_TTL_MS = 5 * 60 * 1000
const cache = new Map()

// Reactive refresh signal. Dashboard widgets watch `refreshSignal.token`
// (via dashboardRefreshMixin) and refetch when it bumps. This replaces the
// old route-query-bump remount trick, which never fired because CnAppRoot's
// <router-view> is not path-keyed so the widgets never remounted.
// `reactive()` is Vue 3's replacement for `Vue.observable`.
const refreshSignal = reactive({ token: 0 })

/**
 * The reactive refresh signal. Read `.token` inside a computed to make a
 * component re-evaluate when the dashboard is refreshed.
 *
 * @return {{ token: number }} The observable signal.
 */
export function getDashboardRefreshSignal() {
	return refreshSignal
}

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
	return generateUrl(
		'/apps/openregister/api/objects/'
			+ typeConfig.register
			+ '/'
			+ typeConfig.schema
			+ (queryParams.toString() ? '?' + queryParams.toString() : ''),
	)
}

/**
 * Raw fetch helper — identical to the per-widget fetchRaw the legacy
 * widgets defined locally. Returns `[]` when the object type is not
 * registered in the app's settings (graceful no-op).
 *
 * @param {string} type - Object type slug (lead, ticket, pipeline, …).
 * @param {object} params - Query parameters (including `ticketType` when
 *   narrowing the unified `ticket` schema to one subtype).
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
	if (entry && now - entry.timestamp < CACHE_TTL_MS) {
		return entry.promise
	}
	const promise = fetcher().catch((err) => {
		// On error, drop the cache so the next mount retries.
		cache.delete(key)
		throw err
	})
	cache.set(key, { promise, timestamp: now })
	return promise
}

/**
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-17
 */
export function getLeads() {
	return cached('lead', () => fetchRaw('lead', { _limit: 500 }))
}

/**
 * Requests, read from the unified `ticket` schema and narrowed to the
 * `request` subtype (unify-ticket-supertype).
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-21
 * @return {Promise<Array>} The request-type ticket records.
 */
export function getRequests() {
	return cached('ticket:request', () =>
		fetchRaw('ticket', {
			ticketType: 'request',
			_limit: 500,
		}),
	)
}

/**
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-20
 */
export function getPipelines() {
	return cached('pipeline', () => fetchRaw('pipeline', { _limit: 100 }))
}

/**
 * Get all contracts (contract-renewal-tracking). Returns [] when the contract
 * schema is not registered (graceful no-op for instances without the feature).
 *
 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up
 * @return {Promise<Array>} The contract records.
 */
export function getContracts() {
	return cached('contract', () => fetchRaw('contract', { _limit: 1000 }))
}

/**
 * Complaints, read from the unified `ticket` schema and narrowed to the
 * `complaint` subtype (unify-ticket-supertype).
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-16
 * @return {Promise<Array>} The complaint-type ticket records.
 */
export function getComplaints() {
	return cached('ticket:complaint', () =>
		fetchRaw('ticket', {
			ticketType: 'complaint',
			_limit: 500,
		}),
	)
}

/**
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-14
 */
export function getClients() {
	return cached('client', () => fetchRaw('client', { _limit: 500 }))
}

/**
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-18
 */
export function getMyLeads() {
	const uid = window.OC?.getCurrentUser?.()?.uid
	if (!uid) return Promise.resolve([])
	return cached('lead:mine', () =>
		fetchRaw('lead', {
			assignee: uid,
			_limit: 200,
		}),
	)
}

/**
 * The current user's requests, read from the unified `ticket` schema and
 * narrowed to the `request` subtype (unify-ticket-supertype).
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-19
 * @return {Promise<Array>} The request-type ticket records assigned to the user.
 */
export function getMyRequests() {
	const uid = window.OC?.getCurrentUser?.()?.uid
	if (!uid) return Promise.resolve([])
	return cached('ticket:request:mine', () =>
		fetchRaw('ticket', {
			ticketType: 'request',
			assignee: uid,
			_limit: 200,
		}),
	)
}

/**
 * Authenticated GET against a pipelinq app endpoint (not OR objects).
 *
 * @param {string} path - App-relative path (e.g. '/apps/pipelinq/api/…').
 * @return {Promise<object>} Parsed JSON body.
 */
async function fetchAppJson(path) {
	const response = await fetch(generateUrl(path), {
		headers: {
			'Content-Type': 'application/json',
			requesttoken: OC.requestToken,
			'OCS-APIREQUEST': 'true',
		},
	})
	if (!response.ok) throw new Error('Failed to fetch ' + path)
	return response.json()
}

/**
 * Cross-module analytics overview, cached per period so the four
 * analytics KPI widgets share a single request per render pass.
 *
 * @param {string} period - week | month | quarter | year.
 * @return {Promise<object>} Overview payload (REQ-DASH-011).
 * @spec openspec/specs/dashboard/spec.md
 */
export function getAnalyticsOverview(period) {
	return cached('analytics:overview:' + period, () =>
		fetchAppJson(
			'/apps/pipelinq/api/analytics/overview?period='
				+ encodeURIComponent(period),
		),
	)
}

/**
 * Commercial KPI overview, cached per period so the six commercial KPI
 * widgets share a single request per render pass.
 *
 * @param {string} period - week | month | quarter | year.
 * @return {Promise<object>} Commercial overview (revenue, wonValue, winRate,
 *   avgDealSize, weightedForecast, openPipelineValue, previousPeriod).
 * @spec openspec/specs/commercial-dashboard/spec.md
 */
export function getCommercialOverview(period) {
	return cached('analytics:commercial:' + period, () =>
		fetchAppJson(
			'/apps/pipelinq/api/analytics/commercial?period='
				+ encodeURIComponent(period),
		),
	)
}

/**
 * Analytics trend series, cached per metric + period.
 *
 * @param {string} metric - leads | requests-by-category | pipeline-value.
 * @param {string} period - week | month | quarter | year.
 * @return {Promise<object>} `{ metric, period, series }` (REQ-DASH-011).
 * @spec openspec/specs/dashboard/spec.md
 */
export function getAnalyticsTrend(metric, period) {
	return cached('analytics:trend:' + metric + ':' + period, () =>
		fetchAppJson(
			'/apps/pipelinq/api/analytics/trends?metric='
				+ encodeURIComponent(metric)
				+ '&period='
				+ encodeURIComponent(period),
		),
	)
}

/**
 * Drop every cached dataset. Call from a "Refresh" UI action or
 * after creating a new object so the dashboard reflects the change
 * on the next widget mount/remount.
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-22
 */
export function invalidateDashboardData() {
	cache.clear()
}

/**
 * Force the whole dashboard to refetch: drop every cached dataset and bump
 * the reactive refresh signal so all mounted widgets re-run their `load()`.
 * Use from the dashboard-wide "Refresh" action.
 */
export function refreshDashboardData() {
	cache.clear()
	refreshSignal.token++
}

/**
 * Compute the set of stage names that mark a pipeline as closed.
 * Shared helper because both Open Leads and Pipeline Value KPIs
 * (plus My Work) need it.
 *
 * @param {Array} pipelines - Pipeline records.
 * @return {Set<string>} Names of stages flagged isClosed.
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-15
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
