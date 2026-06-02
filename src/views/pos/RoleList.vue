<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -
  - @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8
-->
<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'POS roles')"
			:description="t('pipelinq', 'Manage POS roles and their action permissions')"
			:schema="schema"
			:objects="objects"
			:pagination="pagination"
			:loading="loading"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:include-columns="visibleColumns"
			:empty-title="t('pipelinq', 'No roles found')"
			:empty-action-label="t('pipelinq', 'New role')"
			@add="createNew"
			@empty-action="createNew"
			@refresh="refresh"
			@sort="onSort"
			@row-click="editRole"
			@page-changed="onPageChange" />

		<PosRoleFormDialog
			v-if="showForm"
			:role="editing"
			@close="showForm = false"
			@saved="onSaved" />
	</div>
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import PosRoleFormDialog from '../../modals/PosRoleFormDialog.vue'

export default {
	name: 'RoleList',
	components: {
		CnIndexPage,
		PosRoleFormDialog,
	},
	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('posRole', { sidebarState, objectStore })
	},
	data() {
		return {
			showForm: false,
			editing: null,
		}
	},
	computed: {
		/**
		 * Columns shown on the list.
		 *
		 * @return {Array<string>} The column keys.
		 */
		visibleColumns() {
			return ['name', 'maxDiscountPercent', 'canVoid', 'canRefund', 'canNoSale']
		},
	},
	methods: {
		/**
		 * Open the form to create a new role.
		 */
		createNew() {
			this.editing = null
			this.showForm = true
		},
		/**
		 * Open the form to edit a role.
		 *
		 * @param {object} row The clicked row.
		 */
		editRole(row) {
			this.editing = { ...row }
			this.showForm = true
		},
		/**
		 * Refresh the list after a save.
		 */
		onSaved() {
			this.showForm = false
			this.refresh()
		},
	},
}
</script>
