<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The New lead dialog: CnFormDialog over the lead create form
  - (services/leadCreateForm.js). Opened as the createModal of the Leads and
  - Pipeline pages; a created lead opens on its detail page.
  -->
<template>
	<CnFormDialog
		v-if="ready"
		ref="form"
		:dialogTitle="t('pipelinq', 'New Lead')"
		:fields="fields"
		register="pipelinq"
		:item="null"
		:initialData="initialData"
		:confirmLabel="t('pipelinq', 'Create')"
		:cancelLabel="t('pipelinq', 'Cancel')"
		:feedback="false"
		data-testid="lead-create-dialog"
		@confirm="onConfirm"
		@close="$emit('close')" />
</template>

<script>
import { CnFormDialog } from '@conduction/nextcloud-vue'
import { leadCreateFields, leadCreateInitialData, leadCreatePayload } from '../services/leadCreateForm.js'
import { leadSourceLabel } from '../services/leadSourceLabels.js'
import { reportingCurrency } from '../services/reportingCurrency.js'
import { useLeadSourcesStore } from '../store/modules/leadSources.js'
import { useObjectStore } from '../store/modules/object.js'
import { LEAD_PRIORITY_LABELS } from '../utils/enumLabels.js'

export default {
	name: 'LeadCreateDialog',
	components: { CnFormDialog },

	emits: ['created', 'close'],

	data() {
		return {
			ready: false,
		}
	},

	computed: {
		/**
		 * @return {Array<object>} The stored pipelines.
		 */
		pipelines() {
			return useObjectStore().collections.pipeline || []
		},

		/**
		 * @return {Array<object>} The form's field definitions.
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
		 */
		fields() {
			return leadCreateFields({
				translate: (text) => t('pipelinq', text),
				pipelines: this.pipelines,
				sources: useLeadSourcesStore().sourceNames,
				sourceLabel: leadSourceLabel,
				priorityLabels: LEAD_PRIORITY_LABELS,
			})
		},

		/**
		 * @return {object} The values a new lead starts with.
		 * @spec openspec/changes/pipeline-numbers-tell-the-truth/specs/lead-management/spec.md
		 */
		initialData() {
			return leadCreateInitialData(this.pipelines, reportingCurrency())
		},
	},

	async created() {
		await Promise.all([
			useObjectStore().fetchCollection('pipeline', { _limit: 100 }),
			useLeadSourcesStore().fetchSources(),
		])
		this.ready = true
	},

	methods: {
		/**
		 * Save the lead, then open it. A refused save keeps the form open
		 * with the server's message.
		 *
		 * @param {object} formData The form values.
		 * @return {Promise<void>}
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
		 */
		async onConfirm(formData) {
			const store = useObjectStore()
			const result = await store.saveObject('lead', leadCreatePayload(formData, this.pipelines))
			if (!result) {
				const message = store.getError('lead')?.message || t('pipelinq', 'Failed to create lead.')
				this.$refs.form?.setValidationErrors({}, message)
				return
			}
			this.$emit('created', result.id)
			this.$emit('close')
			if (result.id && this.$router) {
				this.$router.push({ name: 'LeadDetail', params: { id: result.id } }).catch(() => {})
			}
		},
	},
}
</script>
