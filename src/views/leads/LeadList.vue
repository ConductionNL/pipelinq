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
-->
<template>
	<CnIndexPage
		ref="index"
		:title="t('pipelinq', 'Leads')"
		:register="register"
		:schema="schema"
		:columns="columns"
		:rowClass="rowClassFor"
		:sidebar="sidebarConfig"
		:quickFilters="quickFilters"
		:quickFilterMaxVisible="6"
		:showTitle="true"
		:showTitleIcon="false"
		:viewSwitch="viewSwitch"
		:viewSwitchLinks="viewSwitchLinks"
		rowTitle="plain"
		:searchPlaceholder="t('pipelinq', 'Filter by title or client')"
		:headerButtons="headerButtons"
		:countText="
			t(
				'pipelinq',
				'{shown} of {total} open leads · {pipeline|currency:EUR} in the pipeline, {weighted|currency:EUR} weighted · win chance first',
			)
		"
		:countValues="countValues"
		:defaultFilters="defaultFilters"
		:appConfig="appConfig"
		:extend="['client']"
		:footerNote="
			t(
				'pipelinq',
				'Win chance is the chance of the stage, lower when a lead stands still. A lead without a step in {days} days is called stale.',
				{ days: staleThreshold },
			)
		"
		createModal="LeadCreateDialog"
		rowClickToView
		@rowClick="openLead"
		@view="openLead">
	</CnIndexPage>
</template>

<script>
import { CnIndexPage, openRowTarget } from '@conduction/nextcloud-vue'
import { CALL_FIRST_SORT, isCallFirstSort } from '../../services/leadScore.js'
import {
	defaultPipelineFor,
	getOverdueDays,
	getStaleThreshold,
	isLeadOverdue,
} from '../../services/pipelineUtils.js'
import { STAGE_COLORS } from '../../services/stageColors.js'
import { useObjectStore } from '../../store/modules/object.js'
import { useSettingsStore } from '../../store/modules/settings.js'

export default {
	name: 'LeadList',
	components: {
		CnIndexPage,
	},

	// The page config the renderer hands a custom page (columns, sort, actions)
	// would fall through onto CnIndexPage and replace what this list declares.
	inheritAttrs: false,

	data() {
		return {
			register: 'pipelinq',
			schema: 'lead',
			columns: [
				{
					key: 'title',
					label: t('pipelinq', 'Lead'),
					secondary: '{client.name} · {source}',
				},
				{
					key: 'stage',
					label: t('pipelinq', 'Stage'),
					widget: 'badge',
					widgetProps: { colorMap: STAGE_COLORS },
				},
				{
					key: 'value',
					label: t('pipelinq', 'Value'),
					formatter: 'objectCurrency',
					formatterOptions: { decimals: 0 },
				},
				{
					key: 'qualificationScore',
					label: t('pipelinq', 'Win chance'),
					sortable: true,
					widget: 'progress',
					widgetProps: {
						fallbackFields: ['probability'],
						thresholds: { good: 60, warn: 30 },
						badge: {
							label: t('pipelinq', 'Out of date {days} days'),
							dateField: '@self.updated',
							afterDays: 14,
							when: { status: 'open' },
						},
					},
				},
				{
					key: 'expectedCloseDate',
					label: t('pipelinq', 'Expected close'),
					widget: 'lead-close-date',
					sortable: true,
				},
				{
					key: 'priority',
					label: t('pipelinq', 'Priority'),
					formatter: 'enumText',
				},
				{
					key: 'assignee',
					label: t('pipelinq', 'Owner'),
					widget: 'avatar',
					// The Pq boards draw the owner as a 28px grey chip.
					widgetProps: { user: true, tone: 'neutral', size: 28 },
				},
			],

			// The board's view switch: table and cards in place, the
			// board is the Pipeline page.
			viewSwitch: ['table', 'cards', 'board', 'map'],
			viewSwitchLinks: { board: { route: 'Pipeline' } },
			callFirst: false,
			// The sort Call first replaced, restored when it is switched off.
			sortBeforeCallFirst: [],
			stages: [],
			// The default pipeline the list opens on, once it is read.
			defaultPipelineId: '',
			// The board opens on the default pipeline, as a removable filter.
			defaultFilters: { pipeline: '@config.defaultPipeline?' },
			// The open pipeline's value and its win-chance weighted forecast,
			// both under the list's filters.
			countValues: {
				pipeline: { metric: 'sum', field: 'value', filter: { status: 'open' } },
				weighted: { endpoint: '/apps/pipelinq/api/analytics/commercial', path: 'weightedForecast', followFilters: true },
			},
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
		 * The board's chips: Open (the default), Mine, Stale, Won and Lost, each
		 * with its count. Stale means not modified within the threshold,
		 * matching `isStale`.
		 *
		 * @return {Array<object>}
		 * @spec openspec/specs/lead-management/spec.md
		 */
		quickFilters() {
			return [
				{
					// The key, untranslated: the bar translates its labels, and a
					// label whose translation equals its key ("Open") falls back to
					// the library catalogue, where Open is the verb "Openen".
					label: 'Open (lead status)',
					filter: { status: 'open' },
					default: true,
					showCount: true,
				},
				{
					label: t('pipelinq', 'Mine'),
					filter: { status: 'open', assignee: '@me' },
					showCount: true,
				},
				{
					label: t('pipelinq', 'Closes this month'),
					filter: {
						status: 'open',
						'expectedCloseDate[gte]': '@monthStart',
						'expectedCloseDate[lte]': '@monthEnd',
					},

					showCount: true,
				},
				{
					label: t('pipelinq', 'Out of date'),
					filter: {
						status: 'open',
						'@self[updated][lt]': `@today-${this.staleThreshold}d`,
					},

					// The row badge and this list filter both read `@self.updated`.
					showCount: true,
				},
				{
					label: t('pipelinq', 'Won'),
					filter: { status: 'won' },
					showCount: true,
				},
				{
					label: t('pipelinq', 'Lost'),
					filter: { status: 'lost' },
					showCount: true,
				},
			]
		},

		/**
		 * The header buttons in the board's order: Download, then New lead.
		 *
		 * @return {Array<object>}
		 *
		 * @spec openspec/changes/round6-board-look/specs/board-look/spec.md
		 */
		headerButtons() {
			return [
				{ action: 'export', label: t('pipelinq', 'Download') },
				{
					action: 'add',
					variant: 'primary',
					icon: 'Plus',
					label: t('pipelinq', 'New lead'),
				},
			]
		},

		/**
		 * The app config the list's default filter reads: the default
		 * pipeline, once it is read.
		 *
		 * @return {object}
		 * @spec openspec/specs/leads-list-board-line/spec.md#requirement-the-leads-list-is-the-board
		 */
		appConfig() {
			return this.defaultPipelineId ? { defaultPipeline: this.defaultPipelineId } : {}
		},

		/**
		 * Sidebar config for the index page; mirrors the manifest.json default.
		 */
		sidebarConfig() {
			return { enabled: true, showMetadata: true }
		},
	},

	/**
	 * Track whether the list is sorted Call first, open it that way, then load
	 * the settings and the default pipeline's stages.
	 *
	 * @spec openspec/specs/lead-management/spec.md#requirement-the-lead-list-shows-and-sorts-by-score-req-lscore-001
	 */
	async mounted() {
		// Read from the page's own sort, so a column click, a saved view or a
		// sort restored from the URL all count.
		this.$watch(
			() => this.$refs.index?.effectiveSortKeys,
			(keys) => {
				this.callFirst = isCallFirstSort(keys)
			},
			{ immediate: true },
		)
		// The list opens win chance first, as its count line says, unless the
		// address already carries a sort.
		if (!this.$route?.query?._order && !this.callFirst) {
			this.setCallFirst(true)
		}
		await this.settingsStore.fetchSettings()
		await this.loadDefaultPipeline()
	},

	methods: {
		isLeadOverdue,
		getOverdueDays,

		/**
		 * Switch the "Call first" sort on or off. On, OpenRegister returns the
		 * highest score first, ties broken by the lead updated longest ago.
		 * Off, it restores the sort it replaced. The filters are left as they are.
		 *
		 * @param {boolean} on Whether Call first is on.
		 * @spec openspec/specs/lead-management/spec.md#requirement-the-lead-list-shows-and-sorts-by-score-req-lscore-001
		 */
		setCallFirst(on) {
			const index = this.$refs.index
			if (on && !isCallFirstSort(index?.effectiveSortKeys)) {
				this.sortBeforeCallFirst = [...(index?.effectiveSortKeys || [])]
			}
			index?.onSortEvent?.({
				keys: on ? CALL_FIRST_SORT : this.sortBeforeCallFirst,
			})
		},

		/**
		 * Open a lead's detail page (CnIndexPage row "View" action).
		 *
		 * @param {object} row The lead row.
		 * @param {MouseEvent} [event] The row click; a modified or middle click opens a new tab.
		 * @spec openspec/specs/lead-management/spec.md#requirement-lead-list-view-mvp
		 */
		openLead(row, event) {
			openRowTarget(
				event,
				{ name: 'LeadDetail', params: { id: row.id } },
				this.$router,
			)
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
		 * Resolve the default pipeline's stages so the overdue highlighting
		 * (REQ-LM-004) can honour each stage's `isClosed` flag. Falls back to
		 * a plain date check when no pipeline is configured.
		 *
		 * @spec openspec/specs/lead-management/spec.md
		 */
		async loadDefaultPipeline() {
			try {
				const pipelines = await this.objectStore.fetchCollection(
					'pipeline',
					{ _limit: 50 },
				)
				const defaultPipeline = defaultPipelineFor(pipelines, 'lead')
				if (defaultPipeline && defaultPipeline.id) {
					this.defaultPipelineId = String(defaultPipeline.id)
				}
				if (defaultPipeline && Array.isArray(defaultPipeline.stages)) {
					this.stages = defaultPipeline.stages
				}
			} catch {
				// Non-fatal — overdue calc falls back to the date check
				// without stage isClosed information.
			}
		},
	},
}
</script>

<style scoped>
/* Overdue row highlighting (REQ-LM-004 Scenario 11). Scoped class applied
   via CnIndexPage's row-class prop. Uses an inset box-shadow (matching the
   library's .cn-table-row--selected accent) rather than border-left, which
   would shift the row's content sideways. A selected row keeps the
   selection accent: this scoped rule outranks the library's. */
:deep(.lead-overdue:not(.cn-table-row--selected)) {
	box-shadow: inset 3px 0 0 0 var(--color-element-error);
}

.overdue-cell {
	color: var(--color-text-error);
	font-weight: 600;
}

.overdue-suffix {
	display: block;
	font-size: 11px;
	color: var(--color-text-error);
}
</style>
