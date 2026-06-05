// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// useOfflineTimer — buffers start/pause/stop events in IndexedDB while
// offline and flushes to the time-tracker leaf on reconnect via useSyncQueue.
//
// The composable is intentionally stateless across page reloads: the
// IndexedDB store is the durable source of truth for buffered captures.
//
// @spec openspec/changes/time-entry-mobile/tasks.md#task-2.1

import { ref, computed, onUnmounted } from 'vue'

/** IndexedDB database name shared by all pipelinq offline composables. */
export const DB_NAME = 'pipelinq-offline'

/** Object store that holds buffered time captures. */
export const STORE_NAME = 'timer-captures'

/** Current schema version for the IndexedDB upgrade path. */
export const DB_VERSION = 1

/**
 * Open (or upgrade) the pipelinq IndexedDB database.
 *
 * @return {Promise<IDBDatabase>} The open database.
 */
export function openDb() {
	return new Promise((resolve, reject) => {
		if (typeof indexedDB === 'undefined') {
			reject(new Error('IndexedDB not available'))
			return
		}

		const request = indexedDB.open(DB_NAME, DB_VERSION)

		request.onupgradeneeded = (event) => {
			const db = event.target.result
			if (!db.objectStoreNames.contains(STORE_NAME)) {
				const store = db.createObjectStore(STORE_NAME, { keyPath: 'bufferId' })
				store.createIndex('status', 'status', { unique: false })
				store.createIndex('startedAt', 'startedAt', { unique: false })
			}
		}

		request.onsuccess = (event) => resolve(event.target.result)
		request.onerror = (event) => reject(event.target.error)
	})
}

/**
 * Write a capture entry to the IndexedDB buffer.
 *
 * @param {object} entry The capture entry to persist.
 * @param {string} entry.bufferId   Client-generated UUID for idempotency.
 * @param {string} entry.register   OR register slug (e.g. 'pipelinq').
 * @param {string} entry.schema     OR schema slug (e.g. 'client').
 * @param {string} entry.objectId   OR object UUID the time links to.
 * @param {number} entry.duration   Duration in seconds.
 * @param {string} entry.startedAt  ISO 8601 start timestamp.
 * @param {string} [entry.description] Optional description / notes.
 * @param {object} [entry.metadata]   Optional metadata payload (GPS, etc.).
 * @return {Promise<void>}
 */
export async function persistCapture(entry) {
	const db = await openDb()
	return new Promise((resolve, reject) => {
		const tx = db.transaction(STORE_NAME, 'readwrite')
		tx.objectStore(STORE_NAME).put({ ...entry, status: 'pending' })
		tx.oncomplete = () => resolve()
		tx.onerror = (event) => reject(event.target.error)
	})
}

/**
 * Generate a simple UUID v4 for use as bufferId.
 *
 * Uses `crypto.randomUUID()` when available; falls back to a
 * Math.random-based variant otherwise.
 *
 * @return {string} A UUID string.
 */
export function generateBufferId() {
	if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
		return crypto.randomUUID()
	}
	// Polyfill for environments without crypto.randomUUID.
	return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
		// eslint-disable-next-line no-bitwise
		const r = (Math.random() * 16) | 0
		// eslint-disable-next-line no-bitwise
		const v = c === 'x' ? r : (r & 0x3) | 0x8
		return v.toString(16)
	})
}

/**
 * The offline timer composable.
 *
 * Tracks start/pause/stop state in memory; on stop, the completed capture
 * is written to IndexedDB so useSyncQueue can flush it later.
 *
 * @param {object} [options] Optional configuration.
 * @param {Function} [options.onCapture] Called with the capture entry after
 *   it is written to IndexedDB. Useful for triggering an immediate flush
 *   when the device is online.
 * @return {object} Reactive state and controls.
 */
export function useOfflineTimer(options = {}) {
	const { onCapture } = options

	/** Whether the timer is currently running. */
	const isRunning = ref(false)

	/** Whether the timer is paused (started then paused). */
	const isPaused = ref(false)

	/** Elapsed seconds (updated every second while running). */
	const elapsed = ref(0)

	/** The OR context for the current capture. */
	const context = ref(null)

	/** Client-generated bufferId for the in-progress capture. */
	const currentBufferId = ref(null)

	/** Wall-clock timestamp when the timer was last started or resumed. */
	let startWall = null

	/** Accumulated seconds from completed segments (before pauses). */
	let accumulated = 0

	/** Interval handle for the elapsed ticker. */
	let ticker = null

	/**
	 * Elapsed duration as a formatted HH:MM:SS string.
	 *
	 * @return {string} Formatted duration.
	 */
	const elapsedFormatted = computed(() => {
		const h = Math.floor(elapsed.value / 3600)
		const m = Math.floor((elapsed.value % 3600) / 60)
		const s = elapsed.value % 60
		return [h, m, s].map((v) => String(v).padStart(2, '0')).join(':')
	})

	/**
	 * Tick the elapsed counter once per second.
	 */
	function tick() {
		if (startWall !== null) {
			const nowSec = Math.floor(Date.now() / 1000)
			elapsed.value = accumulated + (nowSec - startWall)
		}
	}

	/**
	 * Start the timer for the given OR object context.
	 *
	 * @param {object} ctx The OR object context.
	 * @param {string} ctx.register  OR register slug.
	 * @param {string} ctx.schema    OR schema slug.
	 * @param {string} ctx.objectId  OR object UUID.
	 * @param {object} [ctx.metadata] Optional metadata (GPS, etc.).
	 */
	function start(ctx) {
		if (isRunning.value) {
			return
		}
		context.value = ctx
		currentBufferId.value = generateBufferId()
		accumulated = 0
		elapsed.value = 0
		startWall = Math.floor(Date.now() / 1000)
		isRunning.value = true
		isPaused.value = false
		ticker = setInterval(tick, 1000)
	}

	/**
	 * Pause the running timer without discarding the capture.
	 */
	function pause() {
		if (!isRunning.value || isPaused.value) {
			return
		}
		if (startWall !== null) {
			accumulated += Math.floor(Date.now() / 1000) - startWall
			startWall = null
		}
		clearInterval(ticker)
		ticker = null
		isPaused.value = true
	}

	/**
	 * Resume a paused timer.
	 */
	function resume() {
		if (!isPaused.value) {
			return
		}
		startWall = Math.floor(Date.now() / 1000)
		isPaused.value = false
		ticker = setInterval(tick, 1000)
	}

	/**
	 * Stop the timer and persist the capture to IndexedDB.
	 *
	 * @return {Promise<void>} Resolves once the entry is written.
	 */
	async function stop() {
		if (!isRunning.value) {
			return
		}
		// Finalise the elapsed total.
		if (startWall !== null && !isPaused.value) {
			accumulated += Math.floor(Date.now() / 1000) - startWall
		}
		clearInterval(ticker)
		ticker = null
		isRunning.value = false
		isPaused.value = false

		if (accumulated < 1 || !context.value) {
			reset()
			return
		}

		const entry = {
			bufferId: currentBufferId.value,
			register: context.value.register,
			schema: context.value.schema,
			objectId: context.value.objectId,
			duration: accumulated,
			startedAt: new Date(
				(Math.floor(Date.now() / 1000) - accumulated) * 1000,
			).toISOString(),
			description: context.value.description || '',
			metadata: context.value.metadata || {},
		}

		try {
			await persistCapture(entry)
			if (typeof onCapture === 'function') {
				onCapture(entry)
			}
		} catch (err) {
			// Non-fatal — the in-memory capture was finalised. Log and continue.
			// eslint-disable-next-line no-console
			console.error('[useOfflineTimer] Failed to persist capture:', err)
		}

		reset()
	}

	/**
	 * Discard the current capture without persisting.
	 */
	function reset() {
		clearInterval(ticker)
		ticker = null
		isRunning.value = false
		isPaused.value = false
		elapsed.value = 0
		context.value = null
		currentBufferId.value = null
		startWall = null
		accumulated = 0
	}

	onUnmounted(() => {
		clearInterval(ticker)
	})

	return {
		isRunning,
		isPaused,
		elapsed,
		elapsedFormatted,
		context,
		start,
		pause,
		resume,
		stop,
		reset,
	}
}
