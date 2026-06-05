// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// useSyncQueue — flushes buffered timer captures to the time-tracker leaf
// via OR integration link endpoints on the `online` event.
//
// Idempotency: each capture carries a client-generated `bufferId`. The leaf
// endpoint accepts `bufferId` as an idempotency key; duplicate submissions
// are silently dropped by the leaf. This keeps offline → online transitions
// safe without a pipelinq-side dedup table.
//
// Endpoint: POST /apps/openregister/api/objects/{register}/{schema}/{objectId}/links/time-tracker
//
// @spec openspec/changes/time-entry-mobile/tasks.md#task-2.1

import { ref, onMounted, onUnmounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { openDb, STORE_NAME } from './useOfflineTimer.js'

/**
 * Read all pending capture entries from IndexedDB.
 *
 * @param {IDBDatabase} db The open database.
 * @return {Promise<Array<object>>} Pending entries.
 */
function readPendingEntries(db) {
	return new Promise((resolve, reject) => {
		const tx = db.transaction(STORE_NAME, 'readonly')
		const index = tx.objectStore(STORE_NAME).index('status')
		const request = index.getAll('pending')
		request.onsuccess = (event) => resolve(event.target.result || [])
		request.onerror = (event) => reject(event.target.error)
	})
}

/**
 * Mark a capture entry as synced in IndexedDB.
 *
 * @param {IDBDatabase} db       The open database.
 * @param {string}      bufferId The entry's bufferId.
 * @return {Promise<void>}
 */
function markSynced(db, bufferId) {
	return new Promise((resolve, reject) => {
		const tx = db.transaction(STORE_NAME, 'readwrite')
		const store = tx.objectStore(STORE_NAME)
		const request = store.get(bufferId)
		request.onsuccess = (event) => {
			const entry = event.target.result
			if (entry) {
				store.put({ ...entry, status: 'synced' })
			}
		}
		tx.oncomplete = () => resolve()
		tx.onerror = (event) => reject(event.target.error)
	})
}

/**
 * Submit one buffered capture to the time-tracker leaf.
 *
 * The leaf endpoint is the OR integration link endpoint for the `time-tracker`
 * linked type. It accepts `bufferId` as an idempotency key.
 *
 * @param {object} entry The capture entry.
 * @return {Promise<void>} Resolves on success (including 2xx and 409 duplicate).
 */
async function submitCapture(entry) {
	const url = generateUrl(
		`/apps/openregister/api/objects/${entry.register}/${entry.schema}/${entry.objectId}/links/time-tracker`,
	)

	const payload = {
		bufferId: entry.bufferId,
		duration: entry.duration,
		startedAt: entry.startedAt,
		description: entry.description || '',
		metadata: entry.metadata || {},
	}

	try {
		await axios.post(url, payload)
	} catch (err) {
		// A 409 Conflict means the leaf already accepted this bufferId — treat as
		// success so the entry is marked synced and not retried endlessly.
		if (err.response && err.response.status === 409) {
			return
		}
		throw err
	}
}

/**
 * The sync-queue composable.
 *
 * Flushes pending IndexedDB captures to the time-tracker leaf. Automatically
 * triggers on mount and on the `online` event.
 *
 * @return {object} Reactive state and flush control.
 */
export function useSyncQueue() {
	/** Whether a flush is in progress. */
	const isSyncing = ref(false)

	/** Number of pending entries waiting to be synced. */
	const pendingCount = ref(0)

	/** The last error encountered during flush, or null. */
	const lastError = ref(null)

	/**
	 * Refresh the pending count from IndexedDB without flushing.
	 */
	async function refreshPendingCount() {
		try {
			const db = await openDb()
			const entries = await readPendingEntries(db)
			pendingCount.value = entries.length
		} catch {
			// Non-fatal.
		}
	}

	/**
	 * Flush all pending captures to the leaf.
	 *
	 * Skips silently when offline or already flushing. Each entry is submitted
	 * individually; partial failures leave remaining entries in the queue for
	 * the next flush attempt.
	 *
	 * @return {Promise<void>}
	 */
	async function flush() {
		if (isSyncing.value || !navigator.onLine) {
			return
		}
		isSyncing.value = true
		lastError.value = null

		try {
			const db = await openDb()
			const entries = await readPendingEntries(db)
			pendingCount.value = entries.length

			for (const entry of entries) {
				try {
					await submitCapture(entry)
					await markSynced(db, entry.bufferId)
					pendingCount.value = Math.max(0, pendingCount.value - 1)
				} catch (err) {
					// Record but do not abort remaining entries.
					lastError.value = err.message || String(err)
					// eslint-disable-next-line no-console
					console.warn('[useSyncQueue] Failed to sync entry', entry.bufferId, err)
				}
			}
		} catch (err) {
			lastError.value = err.message || String(err)
		} finally {
			isSyncing.value = false
		}
	}

	/**
	 * Handler wired to the window `online` event.
	 */
	function onOnline() {
		flush()
	}

	onMounted(() => {
		refreshPendingCount()
		window.addEventListener('online', onOnline)
		// Attempt an immediate flush if we're already online.
		if (navigator.onLine) {
			flush()
		}
	})

	onUnmounted(() => {
		window.removeEventListener('online', onOnline)
	})

	return {
		isSyncing,
		pendingCount,
		lastError,
		flush,
		refreshPendingCount,
	}
}
