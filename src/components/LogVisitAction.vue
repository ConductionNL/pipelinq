<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/specs/mobile-experience/spec.md#requirement-a-visit-is-logged-in-one-small-sheet-req-mob-003 -->
<template>
	<div class="log-visit-action" :class="{ 'log-visit-action--body': !inHeader }">
		<NcButton
			variant="secondary"
			data-testid="log-visit-button"
			@click="open = true">
			<template #icon>
				<MapMarkerCheckOutline :size="20" />
			</template>
			{{ t('pipelinq', 'Log a visit') }}
		</NcButton>
		<LogVisitDialog
			v-if="open"
			:saving="saving"
			:error="error"
			@close="open = false"
			@save="save" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton } from '@nextcloud/vue'
import MapMarkerCheckOutline from 'vue-material-design-icons/MapMarkerCheckOutline.vue'
import LogVisitDialog from '../dialogs/LogVisitDialog.vue'
import { buildVisitPayloads } from '../services/visitLog.js'
import { useObjectStore } from '../store/modules/object.js'

/**
 * "Log a visit" on a client or lead page: one button, one small dialog.
 * Saves an outbound contact moment with channel `visit` and, when a day is
 * picked, a follow-up task, both through the object store the rest of the
 * app writes with.
 *
 * @spec openspec/specs/mobile-experience/spec.md#requirement-a-visit-is-logged-in-one-small-sheet-req-mob-003
 */
export default {
	name: 'LogVisitAction',
	components: {
		NcButton,
		LogVisitDialog,
		MapMarkerCheckOutline,
	},

	props: {
		/** The client visited (client page, or the lead's client). */
		clientId: {
			type: String,
			default: '',
		},

		/** The lead the visit was about (lead page). */
		leadId: {
			type: String,
			default: '',
		},

		/** Rendered among the page header's buttons instead of in the body. */
		inHeader: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['saved'],

	data() {
		return {
			open: false,
			saving: false,
			error: '',
		}
	},

	methods: {
		/**
		 * Save the follow-up task; false when it did not land.
		 *
		 * @param {object} store The object store.
		 * @param {object} task The crmTask payload.
		 * @return {Promise<boolean>}
		 * @spec openspec/specs/mobile-experience/spec.md#requirement-a-visit-is-logged-in-one-small-sheet-req-mob-003
		 */
		async saveTask(store, task) {
			try {
				return Boolean(await store.saveObject('crmTask', task))
			} catch {
				return false
			}
		},

		/**
		 * Write the contact moment, then the follow-up task when a day was
		 * picked. On a failure the dialog stays open with the error.
		 *
		 * @param {{note: string, followUpDate: string}} input From the dialog.
		 * @spec openspec/specs/mobile-experience/spec.md#requirement-a-visit-is-logged-in-one-small-sheet-req-mob-003
		 */
		async save(input) {
			const store = useObjectStore()
			const { ticket, task } = buildVisitPayloads({
				...input,
				clientId: this.clientId || undefined,
				leadId: this.leadId || undefined,
				userId: window.OC?.getCurrentUser?.()?.uid,
				title: t('pipelinq', 'Visit'),
				taskSubject: t('pipelinq', 'Follow up on the visit'),
			})
			this.saving = true
			this.error = ''
			try {
				const saved = await store.saveObject('ticket', ticket)
				if (!saved)
					throw new Error(t('pipelinq', 'The visit could not be saved.'))
				this.open = false
				this.$emit('saved', saved)
				// The visit is stored now; a failed task must not invite a
				// second save that would log the visit twice.
				if (task && !(await this.saveTask(store, task))) {
					showError(
						t(
							'pipelinq',
							'The visit is saved, the follow-up task is not. Add it from the task list.',
						),
					)
					return
				}
				showSuccess(t('pipelinq', 'Visit logged'))
			} catch (e) {
				this.error =
					e?.message || t('pipelinq', 'The visit could not be saved.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.log-visit-action--body {
	margin: 0 0 12px;
}

.log-visit-action--body :deep(button) {
	min-height: 44px;
}
</style>
