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
		:sort-key="sortKey"
		:sort-order="sortOrder"
		:include-columns="visibleColumns"
		:empty-title="t('pipelinq', 'No blasts yet')"
		:empty-action-label="t('pipelinq', 'New blast')"
		@add="createNew"
		@empty-action="createNew"
		@refresh="refresh"
		@sort="onSort"
		@row-click="openBlast"
		@page-changed="onPageChange" />
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
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
	computed: {
		/**
		 * Columns shown on the blast list, in order.
		 *
		 * @return {Array<string>}
		 */
		visibleColumns() {
			return ['name', 'channel', 'status', 'scheduledFor', 'sentAt']
		},
	},
	methods: {
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
