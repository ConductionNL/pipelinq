<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Task-detail in-body section (kind:'section', board PqTaak "Deze taak
  - afronden"): one result field and two buttons. Log attempt posts the result
  - as a callback attempt; Finish posts it as the task's result and closes the
  - task. Both go to CallbackController, which checks the assignee and the
  - status move server side. The page refreshes after either.
  -->
<template>
	<section
		v-if="open"
		class="task-finish-section"
		data-testid="task-finish-section">
		<NcTextArea
			v-model="result"
			:label="t('pipelinq', 'Result')"
			:placeholder="t('pipelinq', 'What came out of the call?')"
			resize="vertical" />
		<div class="task-finish-section__actions">
			<NcButton
				:disabled="busy || result.trim() === ''"
				data-testid="task-finish-attempt"
				@click="logAttempt">
				<template #icon>
					<Phone :size="20" />
				</template>
				{{ t('pipelinq', 'Log attempt') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy"
				data-testid="task-finish-complete"
				@click="finish">
				<template #icon>
					<Check :size="20" />
				</template>
				{{ t('pipelinq', 'Finish') }}
			</NcButton>
		</div>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcTextArea } from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import Phone from 'vue-material-design-icons/Phone.vue'

export default {
	name: 'TaskFinishSection',
	components: {
		Check,
		NcButton,
		NcTextArea,
		Phone,
	},

	props: {
		/** The task id, token-resolved from `@objectId`. */
		objectId: {
			type: String,
			default: '',
		},

		/** The task's status, token-resolved from `@object.status`. */
		status: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			result: '',
			busy: false,
		}
	},

	computed: {
		/**
		 * Whether the task can still be finished: open or in progress.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/callback-management/spec.md
		 */
		open() {
			return this.objectId !== '' && ['', 'open', 'in_progress'].includes(this.status)
		},
	},

	methods: {
		/**
		 * Log the result as a callback attempt.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/callback-management/spec.md
		 */
		async logAttempt() {
			await this.post('attempts', { result: this.result }, this.t('pipelinq', 'The attempt is logged.'))
		},

		/**
		 * Finish the task with the result.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/callback-management/spec.md
		 */
		async finish() {
			await this.post('complete', { resultText: this.result }, this.t('pipelinq', 'The task is finished.'))
		},

		/**
		 * Post to the callback endpoint, then refresh the page.
		 *
		 * @param {string} action The endpoint under the task: `attempts` or `complete`.
		 * @param {object} body The request body.
		 * @param {string} success The message shown on success.
		 * @return {Promise<void>}
		 * @spec openspec/specs/callback-management/spec.md
		 */
		async post(action, body, success) {
			this.busy = true
			try {
				await axios.post(
					generateUrl(`/apps/pipelinq/api/callbacks/${encodeURIComponent(this.objectId)}/${action}`),
					body,
				)
				this.result = ''
				showSuccess(success)
				emit('cn:page:refresh', {})
			} catch (error) {
				showError(error?.response?.data?.error || this.t('pipelinq', 'Could not save this on the task.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.task-finish-section {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.task-finish-section__actions {
	display: flex;
	gap: 8px;
}
</style>
