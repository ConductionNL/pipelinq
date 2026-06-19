<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<CnIndexPage
		:title="t('pipelinq', 'Blasts')"
		:description="t('pipelinq', 'Marketing campaign sends — segment + template + channel, with optional A/B variant')"
		:schema="schema"
		:objects="objects"
		:pagination="pagination"
		:loading="loading"
		:refreshing="refreshing"
		:sort-key="sortKey"
		:sort-order="sortOrder"
		:include-columns="visibleColumns"
		:show-view-action="false"
		:actions="rowActions"
		:empty-title="t('pipelinq', 'No blasts yet')"
		:empty-action-label="t('pipelinq', 'New blast')"
		@add="createNew"
		@empty-action="createNew"
		@refresh="onRefresh"
		@sort="onSort"
		@view="openBlast"
		@page-changed="onPageChange" />
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import Monitor from 'vue-material-design-icons/Monitor.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'BlastList',
	components: {
		CnIndexPage,
	},
	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('blast', { sidebarState, objectStore })
	},
	data() {
		return {
			refreshing: false,
		}
	},
	computed: {
		/**
		 * Columns shown on the blast list, in order.
		 *
		 * @return {Array<string>}
		 */
		visibleColumns() {
			return ['name', 'channel', 'status', 'scheduledFor', 'sentAt']
		},
		/**
		 * Row actions for the blast list. Replaces the default View action with
		 * a Monitor action that opens the live send monitor for the row.
		 *
		 * @return {Array<object>}
		 */
		rowActions() {
			return [
				{
					label: t('pipelinq', 'Monitor'),
					icon: Monitor,
					handler: (row) => this.openBlast(row),
				},
			]
		},
	},
	methods: {
		/**
		 * Refresh handler for the Actions-menu Refresh item. Drives the
		 * CnIndexPage `:refreshing` spinner around the underlying fetch.
		 *
		 * @spec exclude presentational refresh-button spinner wiring — no business logic
		 */
		async onRefresh() {
			this.refreshing = true
			try {
				await this.refresh()
			} finally {
				this.refreshing = false
			}
		},
		/**
		 * Navigate to the blast monitor for the clicked row.
		 *
		 * @param {object} row The clicked blast.
		 */
		openBlast(row) {
			this.$router.push({ name: 'BlastMonitor', params: { id: row.id } })
		},
		/**
		 * Open the multi-step new-blast wizard.
		 */
		createNew() {
			this.$router.push({ name: 'BlastNew' })
		},
	},
}
</script>
