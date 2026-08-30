/**
 * POS staff session store for Pipelinq.
 *
 * Holds the active POS terminal session (staff member + role permission
 * matrix) after a successful PIN authentication via /api/pos/staff/auth.
 * The store is purely client-side — the server is the authority on the
 * permission matrix and the PIN validation; this store just caches the
 * result for the terminal UI.
 *
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#5.2
 */

import { defineStore } from 'pinia'

const EMPTY_PERMISSIONS = Object.freeze({
	canVoid: false,
	maxDiscountPercent: 0,
	canRefund: false,
	canNoSale: false,
	roleId: '',
	roleName: '',
})

export const usePosSessionStore = defineStore('posSession', {
	state: () => ({
		staffId: '',
		displayName: '',
		permissions: { ...EMPTY_PERMISSIONS },
		expiresAt: '',
	}),
	getters: {
		/**
		 * True when a staff session is currently open.
		 *
		 * @param {object} state Store state.
		 * @return {boolean} True if a staff has logged in and the cached expiry is in the future.
		 */
		isSessionActive: (state) => {
			if (!state.staffId) {
				return false
			}
			if (!state.expiresAt) {
				return true
			}
			const expiry = Date.parse(state.expiresAt)
			if (Number.isNaN(expiry)) {
				return true
			}
			return expiry > Date.now()
		},
	},
	actions: {
		/**
		 * Open a session from the /api/pos/staff/auth response envelope.
		 *
		 * @param {object} session The session payload returned by the backend.
		 * @param {string} session.staffId The staff UUID.
		 * @param {string} session.displayName The staff display name.
		 * @param {object} session.permissions The role permission matrix.
		 * @param {string} session.expiresAt ISO 8601 timestamp.
		 */
		openSession(session) {
			this.staffId = String(session?.staffId || '')
			this.displayName = String(session?.displayName || '')
			this.permissions = {
				...EMPTY_PERMISSIONS,
				...(session?.permissions || {}),
			}
			this.expiresAt = String(session?.expiresAt || '')
		},

		/**
		 * Clear the session (logout or expiry).
		 */
		closeSession() {
			this.staffId = ''
			this.displayName = ''
			this.permissions = { ...EMPTY_PERMISSIONS }
			this.expiresAt = ''
		},
	},
})
