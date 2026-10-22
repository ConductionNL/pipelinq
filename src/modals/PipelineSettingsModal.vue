<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The settings of the pipeline the board shows. Target of the Pipeline
  - settings header action on the Pipeline page; the page hands over its
  - active filters, so the modal edits the pipeline the board is narrowed to.
  -->
<template>
	<PipelineFormDialog
		v-if="pipeline"
		:pipeline="pipeline"
		@save="save"
		@cancel="$emit('close')" />
	<NcDialog
		v-else-if="!loading"
		:name="t('pipelinq', 'Pipeline settings')"
		@closing="$emit('close')">
		<p>{{ t('pipelinq', 'Choose a pipeline on the board first.') }}</p>
	</NcDialog>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { NcDialog } from '@nextcloud/vue'
import PipelineFormDialog from '../dialogs/PipelineFormDialog.vue'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'PipelineSettingsModal',
	components: { NcDialog, PipelineFormDialog },

	props: {
		/** The board's active filters; `pipeline` names the pipeline shown. */
		activeFilters: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['close'],

	data() {
		return {
			pipeline: null,
			loading: true,
		}
	},

	computed: {
		/**
		 * @return {string} The id of the pipeline the board shows, or ''.
		 * @spec openspec/specs/pipeline/spec.md#requirement-kanban-board-view-mvp
		 */
		pipelineId() {
			const raw = this.activeFilters?.pipeline
			const value = Array.isArray(raw) ? raw[0] : raw
			return value ? String(value) : ''
		},
	},

	/**
	 * @spec openspec/specs/pipeline/spec.md#requirement-kanban-board-view-mvp
	 */
	async mounted() {
		if (this.pipelineId !== '') {
			this.pipeline = await useObjectStore().fetchObject('pipeline', this.pipelineId)
		}
		this.loading = false
	},

	methods: {
		/**
		 * Save the pipeline and ask the board to read again.
		 *
		 * @param {object} pipelineData The edited pipeline.
		 * @return {Promise<void>}
		 * @spec openspec/specs/pipeline/spec.md#requirement-kanban-board-view-mvp
		 */
		async save(pipelineData) {
			const saved = await useObjectStore().saveObject('pipeline', pipelineData)
			if (!saved) {
				showError(t('pipelinq', 'Could not save the pipeline.'))
				return
			}
			emit('cn:page:refresh', {})
			this.$emit('close')
		},
	},
}
</script>
