<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -
  - @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8
-->
<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'POS staff')"
			:description="t('pipelinq', 'Manage POS staff members and their PIN login')"
			:schema="schema"
			:objects="objects"
			:pagination="pagination"
			:loading="loading"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:include-columns="visibleColumns"
			:empty-title="t('pipelinq', 'No staff members found')"
			:empty-action-label="t('pipelinq', 'New staff member')"
			@add="createNew"
			@empty-action="createNew"
			@refresh="refresh"
			@sort="onSort"
			@row-click="editStaff"
			@page-changed="onPageChange" />

		<PosStaffFormDialog
			v-if="showForm"
			:staff="editing"
			@close="showForm = false"
			@saved="onSaved" />
	</div>
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import PosStaffFormDialog from '../../modals/PosStaffFormDialog.vue'

export default {
	name: 'StaffList',
	components: {
		CnIndexPage,
		PosStaffFormDialog,
	},
	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('posStaff', { sidebarState, objectStore })
	},
	data() {
		return {
			showForm: false,
			editing: null,
		}
	},
	computed: {
		/**
		 * Columns shown on the list (never pinHash).
		 *
		 * @return {Array<string>} The column keys.
		 */
		visibleColumns() {
			return ['displayName', 'posRole', 'isActive']
		},
	},
	methods: {
		/**
		 * Open the form to create a new staff member.
		 */
		createNew() {
			this.editing = null
			this.showForm = true
		},
		/**
		 * Open the form to edit a staff member.
		 *
		 * @param {object} row The clicked row.
		 */
		editStaff(row) {
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
