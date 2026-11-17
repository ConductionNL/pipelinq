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
		<div class="bulk-fill-kvk">
			<p>
				{{ t('pipelinq', 'Each selected organisation with a KvK number is looked up in the KvK. Only empty fields are filled: place, legal form and industry. A field that holds a value is never overwritten.') }}
			</p>

			<NcNoteCard v-if="refusal" type="error" data-testid="bulk-fill-kvk-refusal">
				{{ refusal }}
			</NcNoteCard>

			<div v-else-if="preview" class="bulk-fill-kvk__summary" data-testid="bulk-fill-kvk-summary">
				<CnStatusBadge
					:label="n('pipelinq', '%n record will change', '%n records will change', summary.changed)"
					variant="primary" />
				<CnStatusBadge
					v-if="summary.skipped > 0"
					:label="n('pipelinq', '%n is skipped: it already has these values or has no KvK number', '%n are skipped: they already have these values or have no KvK number', summary.skipped)"
					variant="default" />
			</div>
		</div>

		<template #actions>
			<NcButton :disabled="committing" @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!preview || refusal"
				variant="primary"
				:disabled="selectedIds.length === 0 || previewing"
				data-testid="bulk-fill-kvk-preview"
				@click="runPreview">
				<template #icon>
					<NcLoadingIcon v-if="previewing" :size="18" />
				</template>
				{{ t('pipelinq', 'Preview') }}
			</NcButton>
			<NcButton
				v-else
				variant="primary"
				:disabled="committing || summary.changed === 0"
				data-testid="bulk-fill-kvk-confirm"
				@click="confirm">
				<template #icon>
					<Check :size="18" />
				</template>
				{{ t('pipelinq', 'Confirm') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog, CnStatusBadge } from '@conduction/nextcloud-vue'
import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import {
	changeSummary,
	commitJob,
	createActionJob,
	FILL_FROM_KVK_ACTION,
	refusalReason,
	resolveScope,
} from '../services/bulkJobs.js'

export default {
	name: 'BulkFillFromKvkModal',
	components: {
		Check,
		CnDialog,
		CnStatusBadge,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** The selected records, merged in by the bulk action. */
		selectedIds: { type: Array, default: () => [] },
		/** The register slug, from the bulk action's props. */
		register: { type: String, default: 'pipelinq' },
		/** The schema slug, from the bulk action's props. */
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
	},

	methods: {
		/**
		 * Ask OpenRegister for a previewed fill. Nothing is written.
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
				this.preview = await createActionJob({
					action: FILL_FROM_KVK_ACTION,
					register: scope.register,
					schema: scope.schema,
					ids: this.selectedIds,
				})
			} catch (error) {
				this.refusal = refusalReason(error, this.t('pipelinq', 'OpenRegister refused this change.'))
			} finally {
				this.previewing = false
			}
		},

		/**
		 * Commit the previewed fill, then let the list read the new values.
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
