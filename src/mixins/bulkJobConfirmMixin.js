// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { commitJob, refusalReason } from '../services/bulkJobs.js'

/**
 * Commit a previewed bulk job, then let the list read the new values.
 *
 * The host dialog keeps `preview` (the previewed job), `summary` (its
 * change counts), `committing` and `refusal`. A refused commit drops the
 * preview so the user can preview again.
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.2
 */
export default {
	methods: {
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
