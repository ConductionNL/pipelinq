<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  Custom lead list view that wraps CnIndexPage to add:
    - stale filter (REQ-LM-002)
    - overdue row highlighting (REQ-LM-004)
    - CSV import/export via the platform mass dialogs (REQ-LM-005)

  The base CnIndexPage from @conduction/nextcloud-vue handles search, sort,
  pagination and the column rendering driven by the manifest `columns` list.
  We provide a slot override for the expectedCloseDate cell so overdue leads
  render the "Xd te laat" treatment without forking the index page.
-->
<template>
	<CnIndexPage
		:title="t('pipelinq', 'Leads')"
		:register="register"
		:schema="schema"
		:columns="columns"
		:sidebar="sidebarConfig"
		:row-class="rowClassFor"
		:items-filter="itemsFilter"
		:actions="actionsBar"
		@view="openLead">
		<template #header-extra>
			<div class="lead-list__filters">
				<NcCheckboxRadioSwitch
					v-model="showStaleOnly"
					type="checkbox">
					{{ t('pipelinq', 'Stale only (>{days}d)', { days: staleThreshold }) }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="hideClosed"
					type="checkbox">
					{{ t('pipelinq', 'Hide closed') }}
				</NcCheckboxRadioSwitch>
			</div>
		</template>

		<template #cell-expectedCloseDate="{ item }">
			<span :class="{ 'overdue-cell': isLeadOverdue(item, stages) }">
				{{ item.expectedCloseDate || '-' }}
				<small v-if="isLeadOverdue(item, stages)" class="overdue-suffix">
					{{ getOverdueDays(item, stages) }}d {{ t('pipelinq', 'late') }}
				</small>
			</span>
		</template>

		<!-- Platform mass import/export dialogs (REQ-LM-005). Built on
		     @conduction/nextcloud-vue — no custom parser/controller. -->
		<CnMassExportDialog
			v-if="exportDialogOpen"
			:register="register"
			:schema="schema"
			:columns="exportColumns"
			:title="t('pipelinq', 'Export leads')"
			@close="exportDialogOpen = false" />

		<CnMassImportDialog
			v-if="importDialogOpen"
			:register="register"
			:schema="schema"
			:required-fields="['title']"
			:row-defaults="importDefaults"
			:title="t('pipelinq', 'Import leads')"
			@close="onImportClose" />
	</CnIndexPage>
</template>

<script>
import { CnIndexPage, CnMassImportDialog, CnMassExportDialog } from '@conduction/nextcloud-vue'
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { getDaysAge, isLeadOverdue, getOverdueDays, getStaleThreshold } from '../../services/pipelineUtils.js'
import { useSettingsStore } from '../../store/modules/settings.js'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'LeadList',
	components: {
		CnIndexPage,
		CnMassImportDialog,
		CnMassExportDialog,
		NcCheckboxRadioSwitch,
	},
	data() {
		return {
			register: 'pipelinq',
			schema: 'lead',
			columns: ['title', 'stage', 'status', 'priority', 'value', 'expectedCloseDate'],
			showStaleOnly: false,
			hideClosed: true,
			exportDialogOpen: false,
			importDialogOpen: false,
			stages: [],
			defaultPipeline: null,
		}
	},
	computed: {
		/**
		 * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-002
		 */
		settingsStore() {
			return useSettingsStore()
		},
		/**
		 * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-005
		 */
		objectStore() {
			return useObjectStore()
		},
		/**
		 * Effective stale threshold from the settings store. Falls back to
		 * 14 days when the store hasn't initialised yet.
		 *
		 * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-002
		 */
		staleThreshold() {
			return getStaleThreshold(this.settingsStore.config)
		},
		/**
		 * Sidebar config for the index page; mirrors the manifest.json default.
		 */
		sidebarConfig() {
			return { enabled: true, showMetadata: true }
		},
		/**
		 * Columns exposed in the export dialog. Mirrors the spec defaults
		 * (REQ-LM-005). Users can still pick a subset in the dialog itself.
		 *
		 * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-005
		 */
		exportColumns() {
			return ['title', 'value', 'stage', 'source', 'priority', 'assignee', 'expectedCloseDate']
		},
		/**
		 * Defaults applied to every imported row that does not specify them.
		 * Anchors imports to the first non-closed stage of the default
		 * pipeline so a minimal CSV still ends up on the kanban board.
		 *
		 * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-005
		 */
		importDefaults() {
			const defaults = { status: 'open', priority: 'normal' }
			if (this.defaultPipeline) {
				defaults.pipeline = this.defaultPipeline.id
				const firstOpenStage = (this.defaultPipeline.stages || [])
					.filter(s => !s.isClosed)
					.sort((a, b) => (a.order || 0) - (b.order || 0))[0]
				if (firstOpenStage) {
					defaults.stage = firstOpenStage.name
					defaults.stageOrder = firstOpenStage.order
				}
			}
			return defaults
		},
		/**
		 * Action bar entries (Import / Export). Exposed to CnIndexPage via
		 * its `actions` prop so the buttons render in the CnActionsBar.
		 *
		 * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-005
		 */
		actionsBar() {
			return [
				{
					id: 'lead-import',
					label: t('pipelinq', 'Import'),
					icon: 'icon-upload',
					handler: () => { this.importDialogOpen = true },
				},
				{
					id: 'lead-export',
					label: t('pipelinq', 'Export'),
					icon: 'icon-download',
					handler: () => { this.exportDialogOpen = true },
				},
			]
		},
	},
	async mounted() {
		await this.settingsStore.fetchSettings()
		await this.loadDefaultPipeline()
	},
	methods: {
		isLeadOverdue,
		getOverdueDays,
		/**
		 * Open a lead's detail page (CnIndexPage row "View" action).
		 *
		 * @param {object} row The lead row.
		 */
		openLead(row) {
			this.$router.push({ name: 'LeadDetail', params: { id: row.id } })
		},
		/**
		 * Compute the row CSS class for the given lead. Drives the
		 * `.lead-overdue` highlighting on the list rows.
		 *
		 * @param {object} item The lead row.
		 * @return {string}
		 * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-004
		 */
		rowClassFor(item) {
			return isLeadOverdue(item, this.stages) ? 'lead-overdue' : ''
		},
		/**
		 * Custom items filter — applied after the platform's search/sort.
		 * Implements the stale toggle and the optional "hide closed" filter.
		 *
		 * @param {Array<object>} items The base item list.
		 * @return {Array<object>}
		 * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-002
		 */
		itemsFilter(items) {
			if (!Array.isArray(items)) return []
			return items.filter(item => {
				if (this.hideClosed && (item.status === 'won' || item.status === 'lost')) {
					return false
				}
				if (this.showStaleOnly && getDaysAge(item) < this.staleThreshold) {
					return false
				}
				return true
			})
		},
		/**
		 * Resolve the default pipeline so CSV imports can default to the
		 * first non-closed stage. Falls back to an empty default when no
		 * pipeline is configured (the import will still create the lead).
		 *
		 * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-005
		 */
		async loadDefaultPipeline() {
			try {
				const pipelines = await this.objectStore.fetchCollection('pipeline', { _limit: 50 })
				if (!Array.isArray(pipelines)) return
				const defaultPipeline = pipelines.find(p => p.isDefault) || pipelines[0]
				this.defaultPipeline = defaultPipeline || null
				if (defaultPipeline && Array.isArray(defaultPipeline.stages)) {
					this.stages = defaultPipeline.stages
				}
			} catch (e) {
				// Non-fatal — overdue calc falls back to the date check
				// without stage isClosed information.
			}
		},
		/**
		 * Handle close of the mass import dialog. Surfaces the summary
		 * toast required by REQ-LM-005 (Scenario 15/16).
		 *
		 * @param {object} [summary] The summary object from the dialog.
		 * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-005
		 */
		onImportClose(summary) {
			this.importDialogOpen = false
			if (summary && typeof summary === 'object') {
				const created = summary.created || 0
				const skipped = summary.skipped || 0
				if (created > 0) {
					showSuccess(t('pipelinq', '{count} leads imported. {skipped} rows skipped.', { count: created, skipped }))
				} else if (skipped > 0) {
					showError(t('pipelinq', 'No leads imported. {skipped} rows skipped.', { skipped }))
				}
			}
		},
	},
}
</script>

<style scoped>
.lead-list__filters {
	display: flex;
	gap: 16px;
	align-items: center;
	flex-wrap: wrap;
	padding: 8px 0;
}

/* Overdue row highlighting (REQ-LM-004 Scenario 11). Scoped class applied
   via CnIndexPage's row-class prop. Uses an inset box-shadow (matching the
   library's .cn-table-row--selected accent) rather than border-left, which
   would shift the row's content sideways. */
:deep(.lead-overdue) {
	box-shadow: inset 3px 0 0 0 var(--color-error);
}

.overdue-cell {
	color: var(--color-error);
	font-weight: 600;
}

.overdue-suffix {
	display: block;
	font-size: 11px;
	color: var(--color-error);
}
</style>
