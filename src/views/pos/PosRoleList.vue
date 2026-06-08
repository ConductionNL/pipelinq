<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - POS role admin list (pos-staff-pin-permissions REQ-PSP-001).
  -
  - @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.1
  -->
<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'POS roles')"
			:description="t('pipelinq', 'Permission matrix for cashiers, supervisors and managers')"
			:schema="schema"
			:objects="objects"
			:pagination="pagination"
			:loading="loading"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:selectable="true"
			:include-columns="visibleColumns"
			:empty-title="t('pipelinq', 'No POS roles defined yet')"
			:empty-action-label="t('pipelinq', 'New role')"
			@add="createNew"
			@empty-action="createNew"
			@refresh="refresh"
			@sort="onSort"
			@row-click="openRole"
			@page-changed="onPageChange" />
	</div>
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'PosRoleList',
	components: { CnIndexPage },
	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('posRole', { sidebarState, objectStore })
	},
	computed: {
		visibleColumns() {
			return ['name', 'canVoid', 'maxDiscountPercent', 'canRefund', 'canNoSale']
		},
	},
	methods: {
		openRole(row) {
			this.$router.push({ name: 'PosRoleDetail', params: { id: row.id } })
		},
		createNew() {
			this.$router.push({ name: 'PosRoleNew' })
		},
	},
}
</script>
