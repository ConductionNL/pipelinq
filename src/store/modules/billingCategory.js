// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Billing category store — manages billingCategory CRUD via OpenRegister.
//
// Mirrors the established Pipelinq lookup-store pattern (skills.js,
// queues.js, product.js): a thin Pinia layer over the shared objectStore
// so views can read activeCategories / defaultCategory / getById without
// each component re-fetching the same collection.
//
// REQ-BCT-001 (lifecycle), REQ-BCT-002 (time-entry pre-selection default),
// REQ-BCT-003 (WBSO categories), REQ-BCT-005 (DBA categories).
//
// Per ADR-022, the actual time-entry list / dialog UI lives in the
// nc-vue CnTimeTrackerTab/Card leaf — this store is what the leaf's
// host page (and the dashboard BillingCategoryWidget) will read from
// to render the category picker, default pre-selection and donut chart.

import { defineStore } from 'pinia'
import { useObjectStore } from './object.js'

export const useBillingCategoryStore = defineStore('billingCategory', {
	state: () => ({
		categories: [],
		loading: false,
		error: null,
	}),
	getters: {
		/**
		 * Active categories — those available for selection on new time entries.
		 *
		 * @param {object} state The store state.
		 * @return {Array<object>} Active category records.
		 */
		activeCategories: (state) => state.categories.filter(c => c.isActive !== false),
		/**
		 * The default category for new time entries, if any.
		 *
		 * @param {object} state The store state.
		 * @return {object|null} The default category or null.
		 */
		defaultCategory: (state) => state.categories.find(c => c.isDefault === true && c.isActive !== false) || null,
		/**
		 * Look up a category by id, slug or uuid.
		 *
		 * @param {object} state The store state.
		 * @return {Function} (key) => category|undefined
		 */
		getCategoryById: (state) => (key) => state.categories.find(c => c.id === key
			|| c['@self']?.slug === key
			|| c['@self']?.uuid === key),
	},
	actions: {
		/**
		 * Fetch the full collection (active + inactive) so the management
		 * list view and the dashboard widget share one cached payload.
		 *
		 * REQ-BCT-001.
		 *
		 * @return {Promise<void>}
		 */
		async fetchCategories() {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.fetchCollection('billingCategory', { _limit: 200 })
				this.categories = result || []
			} catch (error) {
				this.error = error.message
				console.error('Error fetching billing categories:', error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Persist a category — create when no id, otherwise update.
		 *
		 * REQ-BCT-001.
		 *
		 * @param {object} data The category payload.
		 * @return {Promise<object|null>} The saved object or null on failure.
		 */
		async saveCategory(data) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.saveObject('billingCategory', data)
				if (result) {
					await this.fetchCategories()
				}
				return result
			} catch (error) {
				this.error = error.message
				console.error('Error saving billing category:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Delete (or deactivate by isActive=false) a category by id.
		 *
		 * REQ-BCT-001 deactivation scenario.
		 *
		 * @param {string} id The category id.
		 * @return {Promise<boolean>} True on success.
		 */
		async deleteCategory(id) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const success = await objectStore.deleteObject('billingCategory', id)
				if (success) {
					this.categories = this.categories.filter(c => c.id !== id)
				}
				return success
			} catch (error) {
				this.error = error.message
				console.error('Error deleting billing category:', error)
				return false
			} finally {
				this.loading = false
			}
		},
	},
})
