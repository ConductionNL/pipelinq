<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'Automations')"
			:description="t('pipelinq', 'Trigger-action automations for CRM events')"
			:schema="schema"
			:objects="objects"
			:pagination="pagination"
			:loading="loading"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:include-columns="visibleColumns"
			:empty-title="t('pipelinq', 'No automations yet')"
			:empty-action-label="t('pipelinq', 'New automation')"
			@add="createNew"
			@empty-action="createNew"
			@refresh="refresh"
			@sort="onSort"
			@row-click="openAutomation"
			@page-changed="onPageChange" />
	</div>
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'AutomationList',
	components: {
		CnIndexPage,
	},
	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('automation', { sidebarState, objectStore })
	},
	computed: {
		/**
		 * Columns shown on the automation list, in order.
		 *
		 * @return {Array<string>} The column keys.
		 */
		visibleColumns() {
			return ['name', 'trigger', 'isActive', 'lastRun', 'runCount']
		},
	},
	methods: {
		/**
		 * Navigate to an automation's detail.
		 *
		 * @param {object} row The clicked row.
		 */
		openAutomation(row) {
			this.$router.push({ name: 'AutomationDetail', params: { id: row.id } })
		},
		/**
		 * Start a new automation.
		 */
		createNew() {
			this.$router.push({ name: 'AutomationNew' })
		},
	},
}
</script>
