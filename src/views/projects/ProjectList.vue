<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->

<!--
  Project list view.

  Renders the full list of projects using CnIndexPage + useListView.
  Columns: name, client, status, billable indicator, budget hours / logged hours, end date.
  Filters: status, client, billable flag.
  Row click navigates to ProjectDetail.

  @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.1
-->
<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'Projecten')"
			:description="t('pipelinq', 'Beheer projecten en werk-breakdown structuren')"
			:schema="schema"
			:objects="objects"
			:pagination="pagination"
			:loading="loading"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:include-columns="visibleColumns"
			:empty-title="t('pipelinq', 'Geen projecten gevonden')"
			:empty-action-label="t('pipelinq', 'Nieuw project')"
			@add="createNew"
			@empty-action="createNew"
			@refresh="refresh"
			@sort="onSort"
			@row-click="openProject"
			@page-changed="onPageChange" />
	</div>
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ProjectList',

	components: {
		CnIndexPage,
	},

	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('project', { sidebarState, objectStore })
	},

	computed: {
		/**
		 * Columns shown on the project list, in order.
		 *
		 * @return {Array<string>} The column keys.
		 * @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.1
		 */
		visibleColumns() {
			return ['name', 'client', 'status', 'billable', 'budgetHours', 'endDate']
		},
	},

	methods: {
		/**
		 * Navigate to a project's detail view.
		 *
		 * @param {object} row The clicked project row.
		 */
		openProject(row) {
			this.$router.push({ name: 'ProjectDetail', params: { id: row.id } })
		},

		/**
		 * Navigate to create a new project.
		 */
		createNew() {
			this.$router.push({ name: 'ProjectDetail', params: { id: 'new' } })
		},
	},
}
</script>
