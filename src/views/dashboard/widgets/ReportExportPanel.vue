<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Funder reporting export panel. Collapsible widget that lets a user pick an
  - entity type + period and download a structured report. The export itself is
  - delegated entirely to the OpenRegister ExportService via CnMassExportDialog —
  - no custom export controller is built here.
  -->
<template>
	<div class="report-export">
		<button
			type="button"
			class="report-export__header"
			:aria-expanded="expanded ? 'true' : 'false'"
			@click="toggle"
			@keydown.enter.prevent="toggle"
			@keydown.space.prevent="toggle">
			<span class="report-export__title">{{ t('pipelinq', 'Report export') }}</span>
			<span class="report-export__hint">{{ t('pipelinq', 'Generate a CRM performance report for funders and stakeholders') }}</span>
			<ChevronDown v-if="!expanded" :size="20" />
			<ChevronUp v-else :size="20" />
		</button>
		<div v-show="expanded" class="report-export__body">
			<div class="report-export__field">
				<NcSelect
					:value="selectedEntityOption"
					:options="entityOptions"
					:clearable="false"
					:input-label="t('pipelinq', 'Report type')"
					label="label"
					@input="onEntityChange" />
			</div>
			<div class="report-export__field">
				<NcSelect
					:value="selectedPeriodOption"
					:options="periodOptions"
					:clearable="false"
					:input-label="t('pipelinq', 'Period')"
					label="label"
					@input="onPeriodChange" />
			</div>
			<NcButton type="primary" @click="openDialog">
				{{ t('pipelinq', 'Download report') }}
			</NcButton>
		</div>
		<CnMassExportDialog
			v-if="dialogOpen"
			ref="exportDialog"
			:dialog-title="t('pipelinq', 'Download report')"
			:description="exportDescription"
			:formats="formats"
			@confirm="onExportConfirm"
			@close="dialogOpen = false" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcSelect } from '@nextcloud/vue'
import { CnMassExportDialog } from '@conduction/nextcloud-vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import { initializeStores } from '../../../store/store.js'

export default {
	name: 'ReportExportPanel',
	components: {
		NcButton,
		NcSelect,
		CnMassExportDialog,
		ChevronDown,
		ChevronUp,
	},
	data() {
		return {
			expanded: false,
			entityType: 'lead',
			period: 'month',
			dialogOpen: false,
		}
	},
	computed: {
		/**
		 * @return {Array} The selectable report entity types.
		 */
		entityOptions() {
			return [
				{ id: 'lead', label: this.t('pipelinq', 'Leads') },
				{ id: 'request', label: this.t('pipelinq', 'Requests') },
				{ id: 'contactmoment', label: this.t('pipelinq', 'Contact moments') },
				{ id: 'surveyResponse', label: this.t('pipelinq', 'Satisfaction scores') },
			]
		},
		/**
		 * @return {Array} The selectable reporting periods.
		 */
		periodOptions() {
			return [
				{ id: 'week', label: this.t('pipelinq', 'This week') },
				{ id: 'month', label: this.t('pipelinq', 'This month') },
				{ id: 'quarter', label: this.t('pipelinq', 'This quarter') },
				{ id: 'year', label: this.t('pipelinq', 'This year') },
			]
		},
		/**
		 * @return {object} The selected entity option.
		 */
		selectedEntityOption() {
			return this.entityOptions.find((opt) => opt.id === this.entityType) || this.entityOptions[0]
		},
		/**
		 * @return {object} The selected period option.
		 */
		selectedPeriodOption() {
			return this.periodOptions.find((opt) => opt.id === this.period) || this.periodOptions[1]
		},
		/**
		 * @return {Array} The export formats offered by the dialog.
		 */
		formats() {
			return [
				{ id: 'excel', label: this.t('pipelinq', 'Excel (.xlsx)') },
				{ id: 'csv', label: this.t('pipelinq', 'CSV (.csv)') },
				{ id: 'json', label: this.t('pipelinq', 'JSON (.json)') },
			]
		},
		/**
		 * @return {string} The dialog description summarising the export scope.
		 */
		exportDescription() {
			return this.t('pipelinq', 'Export {entity} for {period}.', {
				entity: this.selectedEntityOption.label,
				period: this.selectedPeriodOption.label,
			})
		},
	},
	methods: {
		/**
		 * Toggle the panel open/closed.
		 */
		toggle() {
			this.expanded = !this.expanded
		},
		/**
		 * Update the selected entity type.
		 *
		 * @param {object} option - The newly selected entity option.
		 */
		onEntityChange(option) {
			if (option) {
				this.entityType = option.id
			}
		},
		/**
		 * Update the selected period.
		 *
		 * @param {object} option - The newly selected period option.
		 */
		onPeriodChange(option) {
			if (option) {
				this.period = option.id
			}
		},
		/**
		 * Open the export format dialog.
		 */
		openDialog() {
			this.dialogOpen = true
		},
		/**
		 * Perform the export via the OpenRegister export endpoint and trigger a
		 * browser download. The entity register/schema and period filter are
		 * applied server-side; the dialog only chose the format.
		 *
		 * @param {{ format: string }} payload - The chosen export format.
		 */
		async onExportConfirm({ format }) {
			try {
				const { objectStore } = await initializeStores()
				const typeConfig = objectStore.objectTypeRegistry[this.entityType]
				if (!typeConfig) {
					throw new Error('unconfigured entity type')
				}
				const response = await axios.get(
					generateUrl('/apps/openregister/api/objects/' + typeConfig.register + '/' + typeConfig.schema + '/export'),
					{ params: { type: format, _period: this.period }, responseType: 'blob' },
				)
				this.triggerDownload(response.data, this.entityType + '-report.' + this.extension(format))
				if (this.$refs.exportDialog) {
					this.$refs.exportDialog.setResult({ success: true })
				}
			} catch (err) {
				if (this.$refs.exportDialog) {
					this.$refs.exportDialog.setResult({ error: this.t('pipelinq', 'Export failed. Please try again.') })
				}
			}
		},
		/**
		 * Map an export format id to a file extension.
		 *
		 * @param {string} format - The export format id.
		 * @return {string} The file extension.
		 */
		extension(format) {
			if (format === 'csv') {
				return 'csv'
			}
			if (format === 'json') {
				return 'json'
			}
			return 'xlsx'
		},
		/**
		 * Trigger a browser download for a blob.
		 *
		 * @param {Blob} blob - The file blob.
		 * @param {string} filename - The download filename.
		 */
		triggerDownload(blob, filename) {
			const url = window.URL.createObjectURL(blob)
			const link = document.createElement('a')
			link.href = url
			link.download = filename
			document.body.appendChild(link)
			link.click()
			document.body.removeChild(link)
			window.URL.revokeObjectURL(url)
		},
	},
}
</script>

<style scoped>
.report-export {
	padding: 12px;
	height: 100%;
}

.report-export__header {
	display: flex;
	align-items: center;
	gap: 12px;
	width: 100%;
	background: transparent;
	border: none;
	cursor: pointer;
	padding: 8px;
	text-align: left;
	color: var(--color-main-text);
	border-radius: var(--border-radius);
}

.report-export__header:hover {
	background: var(--color-background-hover);
}

.report-export__title {
	font-weight: 600;
	font-size: 15px;
}

.report-export__hint {
	flex: 1;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.report-export__body {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 12px;
	padding: 12px 8px;
}

.report-export__field {
	min-width: 200px;
}
</style>
