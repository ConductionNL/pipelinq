<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -
  - The cash shift list as the PqKassadiensten board draws it. The list reads
  - itself (CnIndexPage self-fetch) with the board's count line, chips, columns
  - and footer; this view only owns opening a shift, which goes through the
  - server's open endpoint so the operator and the opening time come from the
  - session.
  -->
<template>
	<div>
		<CnIndexPage
			ref="index"
			:title="t('pipelinq', 'Cash drawer')"
			:showTitle="true"
			:searchPlaceholder="t('pipelinq', 'Filter by cashier or till')"
			register="pipelinq"
			schema="cashShift"
			:countText="t('pipelinq', 'Manage cash shifts: opening float, drops, blind counting and reconciliation')"
			:footerNote="t('pipelinq', 'A shift closes with a blind count. The difference is checked against the margin.')"
			:quickFilters="quickFilters"
			:columns="columns"
			sortKey="floatAt"
			sortOrder="desc"
			:selectable="true"
			:showMassExport="true"
			:addLabel="t('pipelinq', 'Open shift')"
			:headerButtons="headerButtons"
			:emptyTitle="t('pipelinq', 'No shifts found')"
			:emptyActionLabel="t('pipelinq', 'Open shift')"
			rowClickToView
			@add="openShift"
			@emptyAction="openShift"
			@rowClick="openDetail"
			@view="openDetail" />

		<CashShiftOpenDialog
			v-if="showOpen"
			:submitting="opening"
			@close="showOpen = false"
			@confirm="createShift" />
	</div>
</template>

<script>
import { CnIndexPage, openRowTarget } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import CashShiftOpenDialog from '../../modals/CashShiftOpenDialog.vue'

export default {
	name: 'CashShiftList',
	components: {
		CnIndexPage,
		CashShiftOpenDialog,
	},

	data() {
		return {
			showOpen: false,
			opening: false,
		}
	},

	computed: {
		/**
		 * The board's chips: every shift, the open ones, and the closed ones
		 * still waiting for their reconciliation.
		 *
		 * @return {Array<object>} The quick filters.
		 * @spec openspec/changes/screens-pos-lists-board/specs/pos-cash-management/spec.md
		 */
		quickFilters() {
			return [
				{ label: t('pipelinq', 'All'), filter: {}, default: true, showCount: true },
				{ label: t('pipelinq', 'Open'), filter: { status: 'open' }, showCount: true },
				{ label: t('pipelinq', 'To review'), filter: { status: 'closed', reconciliationStatus: 'pending' }, showCount: true },
			]
		},

		/**
		 * The board's columns. Drops are counted from the cashDrop records of
		 * the shift.
		 *
		 * @return {Array<object>} The columns.
		 * @spec openspec/changes/screens-pos-lists-board/specs/pos-cash-management/spec.md
		 */
		columns() {
			return [
				{ key: 'reference', label: t('pipelinq', 'Shift'), cellClass: 'cn-cell--strong' },
				{ key: 'operator', label: t('pipelinq', 'Cashier'), formatter: 'userDisplayName' },
				{
					key: 'status',
					label: t('pipelinq', 'Status'),
					formatter: 'enumLabel',
					widget: 'badge',
					widgetProps: { colorMap: { open: 'primary', closed: 'warning', reconciled: 'success' } },
				},
				{ key: 'floatAt', label: t('pipelinq', 'Opened'), widget: 'date', widgetProps: { timeOnly: true, showTime: true } },
				{ key: 'closedAt', label: t('pipelinq', 'Closed'), widget: 'date', widgetProps: { timeOnly: true, showTime: true } },
				{ key: 'floatAmount', label: t('pipelinq', 'Opening balance'), formatter: 'objectCurrency', formatterOptions: { decimals: 2 }, cellClass: 'cn-cell--end' },
				{
					key: 'drops',
					label: t('pipelinq', 'Deposits'),
					sortable: false,
					cellClass: 'cn-cell--end',
					aggregate: { schema: 'cashDrop', op: 'count', where: { shift: '@self.id' } },
				},
			]
		},

		/**
		 * The board header: Download, the actions menu and Open shift.
		 *
		 * @return {Array<object>} The header buttons.
		 */
		headerButtons() {
			return [
				{ action: 'export', label: t('pipelinq', 'Download') },
				{ action: 'add', variant: 'primary', icon: 'Plus', label: t('pipelinq', 'Open shift') },
			]
		},
	},

	methods: {
		/**
		 * Navigate to a shift's detail.
		 *
		 * @param {object} row The clicked row.
		 * @param {MouseEvent} [event] The row click; a modified or middle click opens a new tab.
		 * @spec exclude the cash-shift surface has no owning requirement, see
		 *   postShiftAction in services/posShiftActions.js
		 */
		openDetail(row, event) {
			openRowTarget(
				event,
				{ name: 'CashShiftDetail', params: { id: row.id } },
				this.$router,
			)
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
		 * @spec exclude the cash-shift surface has no owning requirement, see
		 *   postShiftAction in services/posShiftActions.js
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
					await this.$refs.index?.onRefreshEvent()
				}
			} catch {
				showError(t('pipelinq', 'Failed to open shift.'))
			} finally {
				this.opening = false
			}
		},
	},
}
</script>
