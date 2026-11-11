/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * What the POS role and staff forms on the admin page share: the record id,
 * the loading, saving, error and delete-confirm state, the record URLs under
 * `/apps/pipelinq/api/pos/<recordPath>`, and the load, save and delete runs.
 *
 * The host sets `recordPath` in its data (`roles`, `staff`).
 *
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.2
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	props: {
		id: { type: String, default: '' },
	},

	data() {
		return {
			loading: false,
			saving: false,
			errorMessage: '',
			showDeleteConfirm: false,
		}
	},

	computed: {
		isNew() {
			return !this.id
		},
	},

	methods: {
		/**
		 * @return {string} The URL of the record being edited.
		 */
		existingRecordUrl() {
			return generateUrl(`/apps/pipelinq/api/pos/${this.recordPath}/{id}`, {
				id: this.id,
			})
		},

		/**
		 * @return {string} The URL a save goes to: the list for a new record, else the record.
		 */
		saveRecordUrl() {
			return this.isNew
				? generateUrl(`/apps/pipelinq/api/pos/${this.recordPath}`)
				: this.existingRecordUrl()
		},

		/**
		 * Read the record being edited, with `loading` on.
		 *
		 * @param {{read: (record: object) => void, failure: string}} load What to read from the answer, and what a failure says.
		 * @return {Promise<void>}
		 */
		async loadRecord(load) {
			this.loading = true
			try {
				const response = await axios.get(this.existingRecordUrl())
				load.read(response?.data || {})
			} catch (error) {
				this.showRecordError(error, load.failure)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Run a save or delete with `saving` on; done when it succeeds.
		 *
		 * @param {{request: () => Promise, failure: string}} change The request and what a failure says.
		 * @return {Promise<void>}
		 */
		async submitRecord(change) {
			this.saving = true
			this.errorMessage = ''
			try {
				await change.request()
				this.$emit('done')
			} catch (error) {
				this.showRecordError(error, change.failure)
			} finally {
				this.saving = false
			}
		},

		/**
		 * @param {object} error The failure.
		 * @param {string} failure What the form says when the server gives no reason.
		 * @return {void}
		 */
		showRecordError(error, failure) {
			this.errorMessage =
				error?.response?.data?.error
				|| failure
		},

		/**
		 * Save the record: a new one is posted, an existing one put.
		 *
		 * @param {{payload: object, failure: string}} save The body and what a failure says.
		 * @return {Promise<void>}
		 */
		async saveRecord(save) {
			await this.submitRecord({
				request: () => axios[this.isNew ? 'post' : 'put'](this.saveRecordUrl(), save.payload),
				failure: save.failure,
			})
		},

		/**
		 * Open the delete confirmation.
		 *
		 * @return {void}
		 */
		confirmDelete() {
			this.showDeleteConfirm = true
		},

		/**
		 * Delete the record once the dialog confirms.
		 *
		 * @param {string} failure What a failure says.
		 * @return {Promise<void>}
		 */
		async deleteRecord(failure) {
			this.showDeleteConfirm = false
			await this.submitRecord({
				request: () => axios.delete(this.existingRecordUrl()),
				failure,
			})
		},

		/**
		 * Leave the form. The host (the role or staff manager, on the admin
		 * page) closes the dialog — the form no longer routes back to a list
		 * page of its own.
		 *
		 * @spec openspec/changes/nav-ia-cleanup/specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page
		 */
		goBack() {
			this.$emit('done')
		},
	},
}
