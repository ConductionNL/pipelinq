// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { commitJob, refusalReason, resolveScope } from '../services/bulkJobs.js'

/**
 * Preview a bulk job, then commit it and let the list read the new values.
 *
 * The host dialog keeps `preview` (the previewed job), `summary` (its
 * change counts), `previewing`, `committing` and `refusal`, has the props
 * `register`, `schema` and `selectedIds`, a `canPreview` computed, the
 * component option `previewTestidPrefix`, and a `createPreviewJob(scope)`
 * method that asks OpenRegister for the job on the resolved scope. A refused
 * preview or commit shows its reason and lets the user preview again.
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.2
 */
export default {
	computed: {
		/**
		 * The props of the dialog's BulkPreviewActions footer.
		 *
		 * @return {object}
		 */
		previewActions() {
			return {
				previewed: Boolean(this.preview) && !this.refusal,
				canPreview: this.canPreview,
				previewing: this.previewing,
				committing: this.committing,
				changed: this.summary.changed,
				testidPrefix: this.$options.previewTestidPrefix,
			}
		},
	},

	methods: {
		/**
		 * Ask OpenRegister for a previewed job. Nothing is written. A refusal
		 * shows its reason in the dialog.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.2
		 */
		async runPreview() {
			this.previewing = true
			this.refusal = ''
			this.preview = null
			try {
				const scope = await resolveScope(this.register, this.schema, this.selectedIds[0])
				this.preview = await this.createPreviewJob(scope)
			} catch (error) {
				this.refusal = refusalReason(error, this.t('pipelinq', 'OpenRegister refused this change.'))
			} finally {
				this.previewing = false
			}
		},

		/**
		 * Commit the previewed job, then close the dialog.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.2
		 */
		async confirm() {
			if (!this.preview || this.preview.id === undefined) return
			this.committing = true
			try {
				await commitJob(this.preview.id)
				showSuccess(this.n('pipelinq', '%n record changed.', '%n records changed.', this.summary.changed))
				emit('cn:page:refresh', {})
				this.$emit('close')
			} catch (error) {
				this.refusal = refusalReason(error, this.t('pipelinq', 'The change could not be saved.'))
				this.preview = null
			} finally {
				this.committing = false
			}
		},
	},
}
