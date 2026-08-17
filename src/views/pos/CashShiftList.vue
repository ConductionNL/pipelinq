<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'Cash drawer')"
			:description="
				t(
					'pipelinq',
					'Manage cash shifts: opening float, drops, blind counting and reconciliation',
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
			:emptyTitle="t('pipelinq', 'No shifts found')"
			:emptyActionLabel="t('pipelinq', 'Open shift')"
			@add="openShift"
			@emptyAction="openShift"
			@refresh="onRefresh"
			@sort="onSort"
			@view="openDetail"
			@pageChanged="onPageChange" />

		<CashShiftOpenDialog
			v-if="showOpen"
			:submitting="opening"
			@close="showOpen = false"
			@confirm="createShift" />
	</div>
</template>

<script>
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { inject } from 'vue'
import CashShiftOpenDialog from '../../modals/CashShiftOpenDialog.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'CashShiftList',
	components: {
		CnIndexPage,
		CashShiftOpenDialog,
	},

	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('cashShift', { sidebarState, objectStore })
	},

	data() {
		return {
			showOpen: false,
			opening: false,
			refreshing: false,
		}
	},

	computed: {
		/**
		 * Columns shown on the list, in order.
		 *
		 * @return {Array<string>} The column keys.
		 */
		visibleColumns() {
			return [
				'reference',
				'drawer',
				'operator',
				'floatAmount',
				'status',
				'floatAt',
				'closedAt',
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
		 * Navigate to a shift's detail.
		 *
		 * @param {object} row The clicked row.
		 */
		openDetail(row) {
			this.$router.push({ name: 'CashShiftDetail', params: { id: row.id } })
		},

		/**
		 * Open the float-declaration dialog to start a new shift.
		 */
		openShift() {
			this.showOpen = true
		},

		/**
		 * Create a new shift via the server-authoritative open endpoint.
		 *
		 * The operator and floatAt timestamp are set server-side from the session;
		 * the client only supplies the drawer and the declared float amount.
		 *
		 * @param {object} payload The dialog payload (drawer, floatAmount, reference, notes).
		 */
		async createShift(payload) {
			this.opening = true
			try {
				const response = await fetch(
					generateUrl('/apps/pipelinq/api/pos-shifts'),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
						body: JSON.stringify(payload),
					},
				)
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					showError(data.error || t('pipelinq', 'Failed to open shift.'))
					return
				}
				showSuccess(t('pipelinq', 'Shift opened.'))
				this.showOpen = false
				const id = data.shift?.id
				if (id) {
					this.$router.push({ name: 'CashShiftDetail', params: { id } })
				} else {
					await this.refresh()
				}
			} catch (e) {
				showError(t('pipelinq', 'Failed to open shift.'))
			} finally {
				this.opening = false
			}
		},
	},
}
</script>
