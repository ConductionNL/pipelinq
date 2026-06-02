/**
 * POS staff session store for Pipelinq.
 *
 * Holds the active staff session opened by a successful PIN authentication:
 * the staff member's id, display name and the server-resolved permission matrix
 * (canVoid, maxDiscountPercent, canRefund, canNoSale). The permission matrix is
 * authoritative as returned by the server — the client never decides what a PIN
 * grants. Cleared on logout or when the session expires.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#5
 */
import { defineStore } from 'pinia'

/**
 * Default (most-restrictive) permission matrix.
 *
 * @return {object} A matrix granting no permissions.
 */
function emptyPermissions() {
	return {
		canVoid: false,
		maxDiscountPercent: 0,
		canRefund: false,
		canNoSale: false,
	}
}

export const usePosSessionStore = defineStore('posSession', {
	state: () => ({
		staffId: null,
		displayName: '',
		permissions: emptyPermissions(),
		expiresAt: null,
	}),
	getters: {
		/**
		 * Whether a staff session is currently active (and not expired).
		 *
		 * @param {object} state The store state.
		 * @return {boolean} True when a non-expired session is open.
		 */
		hasActiveSession: (state) => {
			if (!state.staffId) {
				return false
			}
			if (state.expiresAt && Date.parse(state.expiresAt) <= Date.now()) {
				return false
			}
			return true
		},
	},
	actions: {
		/**
		 * Open a staff session from a server authentication payload.
		 *
		 * @param {object} data The payload: { staffId, displayName, permissions, expiresAt? }.
		 */
		openSession(data) {
			this.staffId = data?.staffId || null
			this.displayName = data?.displayName || ''
			this.permissions = { ...emptyPermissions(), ...(data?.permissions || {}) }
			this.expiresAt = data?.expiresAt || null
		},
		/**
		 * Close the active staff session.
		 */
		closeSession() {
			this.staffId = null
			this.displayName = ''
			this.permissions = emptyPermissions()
			this.expiresAt = null
		},
		/**
		 * Whether a staff session is currently active (action form for callers
		 * preferring an explicit method).
		 *
		 * @return {boolean} True when a non-expired session is open.
		 */
		isSessionActive() {
			return this.hasActiveSession
		},
	},
})
