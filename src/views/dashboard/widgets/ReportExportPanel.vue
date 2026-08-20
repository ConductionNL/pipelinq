<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Funder report export panel (openspec/changes/dashboard,
  REQ-DASH-020 / REQ-DASH-021).

  Collapsible panel offering:
   - entity type selector (Leads / Verzoeken / Contactmomenten / Tevredenheidsscores)
   - period selector (week / month / quarter / year)
   - "Download report" button -> opens CnMassExportDialog (format picker
     delegated to the shared component; ExportService does the actual work)

  Collapse toggle responds to Enter + Space (WCAG AA) and the panel sets
  aria-expanded correctly.
-->
<template>
	<div class="report-export">
		<div
			role="button"
			tabindex="0"
			class="report-export__header"
			:aria-expanded="expanded ? 'true' : 'false'"
			aria-controls="report-export-body"
			@click="toggle"
			@keydown.enter.prevent="toggle"
			@keydown.space.prevent="toggle">
			<span class="report-export__title">
				{{ t('pipelinq', 'Report Export') }}
			</span>
			<span class="report-export__subtitle">
				{{
					t(
						'pipelinq',
						'Download CSV / Excel / JSON reports for funders and stakeholders.',
					)
				}}
			</span>
			<span class="report-export__chevron" aria-hidden="true">
				{{ expanded ? '▾' : '▸' }}
			</span>
		</div>
		<div v-if="expanded" id="report-export-body" class="report-export__body">
			<NcSelect
				v-model="entityType"
				:inputLabel="t('pipelinq', 'Entity type')"
				:options="entityOptions"
				label="label"
				trackBy="value"
				:reduce="(opt) => opt.value"
				:clearable="false"
				class="report-export__field" />
			<NcSelect
				v-model="period"
				:inputLabel="t('pipelinq', 'Period')"
				:options="periodOptions"
				label="label"
				trackBy="value"
				:reduce="(opt) => opt.value"
				:clearable="false"
				class="report-export__field" />
			<NcButton
				variant="primary"
				class="report-export__download"
				@click="downloadReport">
				{{ t('pipelinq', 'Download Report') }}
			</NcButton>
		</div>
		<CnMassExportDialog
			v-if="showDialog"
			ref="exportDialog"
			:items="exportItems"
			:dialogTitle="
				t('pipelinq', 'Export {entity} report', {
					entity: selectedEntityLabel,
				})
			"
			:description="
				t('pipelinq', 'Generates a {period} report for {entity}.', {
					period: selectedPeriodLabel,
					entity: selectedEntityLabel,
				})
			"
			:formats="exportFormats"
			defaultFormat="excel"
			@confirm="onExportConfirm"
			@close="showDialog = false" />
	</div>
</template>

<script>
import { CnMassExportDialog } from '@conduction/nextcloud-vue'
import { NcButton, NcSelect } from '@nextcloud/vue'

/**
 * ReportExportPanel — collapsible exporter delegating to ExportService /
 * CnMassExportDialog.
 *
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/specs/dashboard/spec.md
 */
export default {
	name: 'ReportExportPanel',
	components: {
		NcButton,
		NcSelect,
		CnMassExportDialog,
	},

	data() {
		return {
			expanded: false,
			entityType: 'leads',
			period: 'month',
			showDialog: false,
			exportFormats: [
				{ id: 'excel', label: 'Excel (.xlsx)' },
				{ id: 'csv', label: 'CSV (.csv)' },
				{ id: 'json', label: 'JSON (.json)' },
			],
		}
	},

	computed: {
		/**
		 * @spec openspec/specs/dashboard/spec.md
		 */
		entityOptions() {
			return [
				{ value: 'leads', label: this.t('pipelinq', 'Leads') },
				{ value: 'requests', label: this.t('pipelinq', 'Requests') },
				{
					value: 'contactmomenten',
					label: this.t('pipelinq', 'Contact moments'),
				},
				{
					value: 'satisfaction',
					label: this.t('pipelinq', 'Satisfaction scores'),
				},
			]
		},

		periodOptions() {
			return [
				{ value: 'week', label: this.t('pipelinq', 'This week') },
				{ value: 'month', label: this.t('pipelinq', 'This month') },
				{ value: 'quarter', label: this.t('pipelinq', 'This quarter') },
				{ value: 'year', label: this.t('pipelinq', 'This year') },
			]
		},

		selectedEntityLabel() {
			const opt = this.entityOptions.find((o) => o.value === this.entityType)
			return opt ? opt.label : this.entityType
		},

		selectedPeriodLabel() {
			const opt = this.periodOptions.find((o) => o.value === this.period)
			return opt ? opt.label : this.period
		},

		/**
		 * Synthetic items array used to satisfy CnMassExportDialog's items
		 * prop. The actual export query is built by the consumer in
		 * `onExportConfirm` from the entity + period filter.
		 *
		 * @return {Array<{id: string, title: string}>}
		 */
		exportItems() {
			return [{ id: this.entityType, title: this.selectedEntityLabel }]
		},
	},

	methods: {
		/**
		 * Toggle the collapsible body. Wired to click AND keyboard so the
		 * panel meets WCAG AA (REQ-DASH-021).
		 */
		toggle() {
			this.expanded = !this.expanded
		},

		/**
		 * Open the CnMassExportDialog with the current filters applied.
		 *
		 * @spec openspec/specs/dashboard/spec.md
		 */
		downloadReport() {
			this.showDialog = true
		},

		/**
		 * Handle the CnMassExportDialog confirm event — delegated to the
		 * platform ExportService; this method only relays the selection
		 * for the parent / app-wide event bus.
		 *
		 * @param {{format: string, ids: Array<string>}} payload - Dialog payload.
		 */
		onExportConfirm(payload) {
			this.$emit('export-confirmed', {
				entityType: this.entityType,
				period: this.period,
				format: payload?.format,
			})
			if (
				this.$refs.exportDialog
				&& typeof this.$refs.exportDialog.setResult === 'function'
			) {
				this.$refs.exportDialog.setResult({ success: true })
			}
		},
	},
}
</script>

<style scoped>
.report-export {
	height: 100%;
	display: flex;
	flex-direction: column;
	padding: 8px;
	box-sizing: border-box;
}

.report-export__header {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 12px;
	border-radius: 8px;
	cursor: pointer;
	background: var(--color-background-hover);
}

.report-export__header:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.report-export__title {
	font-weight: 600;
	font-size: 14px;
}

.report-export__subtitle {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	flex: 1;
}

.report-export__chevron {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.report-export__body {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	align-items: flex-end;
	margin-top: 8px;
	padding: 8px 12px;
}

.report-export__field {
	min-width: 200px;
	flex: 1 1 200px;
}

.report-export__download {
	flex-shrink: 0;
}
</style>
