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
			:description="
				t(
					'pipelinq',
					'Permission matrix for cashiers, supervisors and managers',
				)
			"
			:schema="schema"
			:objects="objects"
			:pagination="pagination"
			:loading="loading"
			:refreshing="refreshing"
			:sortKey="sortKey"
			:sortOrder="sortOrder"
			:selectable="true"
			:includeColumns="visibleColumns"
			:emptyTitle="t('pipelinq', 'No POS roles defined yet')"
			:emptyActionLabel="t('pipelinq', 'New role')"
			@add="createNew"
			@emptyAction="createNew"
			@refresh="onRefresh"
			@sort="onSort"
			@view="openRole"
			@pageChanged="onPageChange" />
	</div>
</template>

<script>
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { inject } from 'vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'PosRoleList',
	components: { CnIndexPage },
	emits: ['create', 'edit'],

	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('posRole', { sidebarState, objectStore })
	},

	data() {
		return {
			refreshing: false,
		}
	},

	computed: {
		visibleColumns() {
			return [
				'name',
				'canVoid',
				'maxDiscountPercent',
				'canRefund',
				'canNoSale',
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
		 * Ask the host to open this role for editing.
		 *
		 * POS roles are admin master-data, so this list now lives on the Nextcloud
		 * admin page (nav-ia-cleanup), which is its own webpack entry with no
		 * vue-router. It therefore emits instead of routing to a detail page, and
		 * PosRoleManager opens the form in a dialog.
		 *
		 * @param {object} row The role row.
		 * @spec openspec/changes/nav-ia-cleanup/specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page
		 */
		openRole(row) {
			this.$emit('edit', row.id)
		},

		/**
		 * Ask the host to open an empty form.
		 *
		 * @spec openspec/changes/nav-ia-cleanup/specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page
		 */
		createNew() {
			this.$emit('create')
		},
	},
}
</script>
