<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Fill in from KvK on the selected organisations (clients-registry-numbers-
  - and-enrichment, REQ-CRN-003). The dialog asks OpenRegister for a previewed
  - job of pipelinq's FillFromKvkAction, shows how many records change and how
  - many are skipped, and commits only when the user confirms. Only empty fields
  - are filled; the job is undoable from the Bulk changes page.
  -
  - @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.2
  -->
<template>
	<CnDialog
		:name="t('pipelinq', 'Fill in from KvK')"
		:eyebrow="n('pipelinq', '%n record selected', '%n records selected', selectedIds.length)"
		size="normal"
		:noClose="committing"
		data-testid="bulk-fill-kvk-dialog"
		@closing="$emit('close')">
		<template #actions>
			<BulkPreviewActions v-bind="previewActions" v-on="previewListeners" />
		</template>

		<div class="bulk-fill-kvk">
			<p>
				{{ t('pipelinq', 'Each selected organisation with a KvK number is looked up in the KvK. Only empty fields are filled: place, legal form and industry. A field that holds a value is never overwritten.') }}
			</p>

			<NcNoteCard v-if="refusal" type="error" data-testid="bulk-fill-kvk-refusal">
				{{ refusal }}
			</NcNoteCard>

			<div v-else-if="preview" class="bulk-fill-kvk__summary" data-testid="bulk-fill-kvk-summary">
				<CnStatusBadge
					v-for="badge in summaryBadges"
					:key="badge.variant"
					:label="badge.label"
					:variant="badge.variant" />
			</div>
		</div>

	</CnDialog>
</template>

<script>
import { CnDialog, CnStatusBadge } from '@conduction/nextcloud-vue'
import { NcNoteCard } from '@nextcloud/vue'
import BulkPreviewActions from '../components/BulkPreviewActions.vue'
import bulkPreviewJobMixin from '../mixins/bulkPreviewJobMixin.js'
import {
	changeSummary,
	createActionJob,
	FILL_FROM_KVK_ACTION,
} from '../services/bulkJobs.js'

export default {
	name: 'BulkFillFromKvkModal',
	components: {
		BulkPreviewActions,
		CnDialog,
		CnStatusBadge,
		NcNoteCard,
	},

	mixins: [bulkPreviewJobMixin],
	previewTestidPrefix: 'bulk-fill-kvk',

	props: {
		/** The selected records, merged in by the bulk action. */
		selectedIds: { type: Array, default: () => [] },
		/** The register slug, from the bulk action's props. */
		// eslint-disable-next-line vue/no-unused-properties -- read by bulkPreviewJobMixin.runPreview
		register: { type: String, default: 'pipelinq' },
		/** The schema slug, from the bulk action's props. */
		// eslint-disable-next-line vue/no-unused-properties -- read by bulkPreviewJobMixin.runPreview
		schema: { type: String, default: 'client' },
	},

	emits: ['close'],

	data() {
		return {
			previewing: false,
			committing: false,
			preview: null,
			refusal: '',
		}
	},

	computed: {
		/**
		 * The preview's counts.
		 *
		 * @return {{changed: number, skipped: number}}
		 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.2
		 */
		summary() {
			return changeSummary(this.preview)
		},

		/**
		 * The preview's counts as badges: what changes, and what is skipped when anything is.
		 *
		 * @return {Array<{label: string, variant: string}>}
		 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.2
		 */
		summaryBadges() {
			const badges = [{ label: this.n('pipelinq', '%n record will change', '%n records will change', this.summary.changed), variant: 'primary' }]
			if (this.summary.skipped > 0) {
				badges.push({
					label: this.n('pipelinq', '%n is skipped: it already has these values or has no KvK number', '%n are skipped: they already have these values or have no KvK number', this.summary.skipped),
					variant: 'default',
				})
			}
			return badges
		},

		/**
		 * Whether there is a selection to preview the fill on.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.2
		 */
		canPreview() {
			return this.selectedIds.length > 0
		},
	},

	methods: {
		/**
		 * Ask OpenRegister for a previewed fill on the resolved scope. Nothing is written.
		 *
		 * @param {{register: string, schema: string}} scope The register and schema.
		 * @return {Promise<object>} The previewed job.
		 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.2
		 */
		createPreviewJob(scope) {
			return createActionJob({
				action: FILL_FROM_KVK_ACTION,
				register: scope.register,
				schema: scope.schema,
				ids: this.selectedIds,
			})
		},
	},
}
</script>

<style scoped lang="scss">
.bulk-fill-kvk {
	display: flex;
	flex-direction: column;
	gap: 16px;
	min-height: 80px;
}

.bulk-fill-kvk__summary {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
