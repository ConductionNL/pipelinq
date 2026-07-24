<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  Custom lead list view that wraps CnIndexPage to add:
    - stale filter (REQ-LM-002)
    - overdue row highlighting (REQ-LM-004)

  Import/export (REQ-LM-005) uses CnIndexPage's built-in mass-import/export
  buttons and dialogs (enabled by default) — no custom dialogs or row actions.
  Note: the built-in flow is openregister's generic server-side bulk import/
  export; the spec's lead-specific behaviour (default-stage assignment, title
  validation, "X geïmporteerd / Y overgeslagen" summary) is not yet wired.

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
		:row-click-to-view="false"
		@row-click="openLead"
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
	</CnIndexPage>
</template>

<script>
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { getDaysAge, isLeadOverdue, getOverdueDays, getStaleThreshold } from '../../services/pipelineUtils.js'
import { useSettingsStore } from '../../store/modules/settings.js'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'LeadList',
	components: {
		CnIndexPage,
		NcCheckboxRadioSwitch,
	},
	data() {
		return {
			register: 'pipelinq',
			schema: 'lead',
			columns: ['title', 'stage', 'status', 'priority', 'value', 'expectedCloseDate'],
			showStaleOnly: false,
			hideClosed: true,
			stages: [],
		}
	},
	computed: {
		/**
		 * @spec openspec/specs/lead-management/spec.md
		 */
		settingsStore() {
			return useSettingsStore()
		},
		/**
		 * @spec openspec/specs/lead-management/spec.md
		 */
		objectStore() {
			return useObjectStore()
		},
		/**
		 * Effective stale threshold from the settings store. Falls back to
		 * 14 days when the store hasn't initialised yet.
		 *
		 * @spec openspec/specs/lead-management/spec.md
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
		 * @spec openspec/specs/lead-management/spec.md
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
		 * @spec openspec/specs/lead-management/spec.md
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
		 * Resolve the default pipeline's stages so the overdue highlighting
		 * (REQ-LM-004) can honour each stage's `isClosed` flag. Falls back to
		 * a plain date check when no pipeline is configured.
		 *
		 * @spec openspec/specs/lead-management/spec.md
		 */
		async loadDefaultPipeline() {
			try {
				const pipelines = await this.objectStore.fetchCollection('pipeline', { _limit: 50 })
				if (!Array.isArray(pipelines)) return
				const defaultPipeline = pipelines.find(p => p.isDefault) || pipelines[0]
				if (defaultPipeline && Array.isArray(defaultPipeline.stages)) {
					this.stages = defaultPipeline.stages
				}
			} catch (e) {
				// Non-fatal — overdue calc falls back to the date check
				// without stage isClosed information.
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
