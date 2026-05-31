<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<CnDetailPage
		:title="transaction.reference || t('pipelinq', 'Transaction')"
		:subtitle="t('pipelinq', 'POS transaction')"
		:back-route="{ name: 'PosTransactions' }"
		:back-label="t('pipelinq', 'Back to list')"
		:loading="loading"
		:sidebar="!loading"
		object-type="pipelinq_posTransaction"
		:object-id="transactionId"
		:sidebar-props="sidebarProps">
		<template #actions>
			<NcButton v-if="canEdit" type="secondary" @click="edit">
				{{ t('pipelinq', 'Edit') }}
			</NcButton>
			<NcButton v-if="canConfirm"
				type="primary"
				:disabled="busy || lineCount === 0"
				@click="confirm">
				{{ t('pipelinq', 'Bevestigen') }}
			</NcButton>
			<NcButton v-if="canPark"
				type="secondary"
				:disabled="busy"
				@click="park">
				{{ t('pipelinq', 'Parkeren') }}
			</NcButton>
			<NcButton v-if="canResume"
				type="primary"
				:disabled="busy"
				@click="resume">
				{{ t('pipelinq', 'Hervatten') }}
			</NcButton>
			<NcButton v-if="canSettle"
				type="primary"
				:disabled="busy"
				@click="settle">
				{{ t('pipelinq', 'Afrekenen') }}
			</NcButton>
			<NcButton v-if="canRefund"
				type="error"
				:disabled="busy"
				@click="showRefund = true">
				{{ t('pipelinq', 'Terugboeken') }}
			</NcButton>
		</template>

		<CnDetailCard :title="t('pipelinq', 'Transaction information')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Reference') }}</label>
					<span>{{ transaction.reference || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Cashier') }}</label>
					<span>{{ transaction.cashier || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Terminal') }}</label>
					<span>{{ transaction.terminalId || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Status') }}</label>
					<CnStatusBadge :status="transaction.status" :label="statusLabel" />
				</div>
				<div v-if="transaction.notes" class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Notes') }}</label>
					<span>{{ transaction.notes }}</span>
				</div>
				<div v-if="transaction.refundReason" class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Refund reason') }}</label>
					<span>{{ transaction.refundReason }}</span>
				</div>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Line items')">
			<table class="pos-detail__lines">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Description') }}</th>
						<th class="num">
							{{ t('pipelinq', 'Qty') }}
						</th>
						<th class="num">
							{{ t('pipelinq', 'Unit price') }}
						</th>
						<th class="num">
							{{ t('pipelinq', 'Discount %') }}
						</th>
						<th class="num">
							{{ t('pipelinq', 'VAT') }}
						</th>
						<th class="num">
							{{ t('pipelinq', 'Line total') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="line in lines" :key="line.id">
						<td>{{ line.description }}</td>
						<td class="num">
							{{ line.quantity }}
						</td>
						<td class="num">
							{{ formatEur(line.unitPrice) }}
						</td>
						<td class="num">
							{{ line.discount || 0 }}%
						</td>
						<td class="num">
							{{ line.taxRate }}%
						</td>
						<td class="num">
							{{ formatEur(line.lineTotal) }}
						</td>
					</tr>
					<tr v-if="lines.length === 0">
						<td colspan="6" class="empty">
							{{ t('pipelinq', 'No line items.') }}
						</td>
					</tr>
				</tbody>
			</table>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Tax breakdown')">
			<table class="pos-detail__tax">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Rate') }}</th>
						<th class="num">
							{{ t('pipelinq', 'Base') }}
						</th>
						<th class="num">
							{{ t('pipelinq', 'VAT') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="rate in taxBreakdown" :key="rate.rate">
						<td>{{ rate.rate }}%</td>
						<td class="num">
							{{ formatEur(rate.base) }}
						</td>
						<td class="num">
							{{ formatEur(rate.tax) }}
						</td>
					</tr>
				</tbody>
			</table>
			<PosTotalsPanel :lines="lines" />
		</CnDetailCard>

		<PosRefundDialog
			v-if="showRefund"
			:submitting="busy"
			@close="showRefund = false"
			@confirm="refund" />
	</CnDetailPage>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { CnDetailPage, CnDetailCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import PosTotalsPanel from '../../components/pos/PosTotalsPanel.vue'
import PosRefundDialog from '../../modals/PosRefundDialog.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { formatEur } from '../../services/posTotals.js'

const STATUS_LABELS = {
	draft: 'Concept',
	parked: 'Geparkeerd',
	confirmed: 'Bevestigd',
	settled: 'Afgerekend',
	refunded: 'Teruggeboekt',
}

export default {
	name: 'PosTransactionDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		CnStatusBadge,
		PosTotalsPanel,
		PosRefundDialog,
	},
	props: {
		posTransactionId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			transaction: {},
			lines: [],
			loading: false,
			busy: false,
			showRefund: false,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		/**
		 * The transaction id from the prop or route.
		 *
		 * @return {string|null} The id.
		 */
		transactionId() {
			return this.posTransactionId || this.$route.params.id || null
		},
		/**
		 * The current status.
		 *
		 * @return {string} The status.
		 */
		status() {
			return this.transaction.status || 'draft'
		},
		/**
		 * Translated status label.
		 *
		 * @return {string} The label.
		 */
		statusLabel() {
			return t('pipelinq', STATUS_LABELS[this.status] || this.status)
		},
		/**
		 * Persisted tax breakdown rows.
		 *
		 * @return {Array<object>} The rows.
		 */
		taxBreakdown() {
			return this.transaction.taxBreakdown || []
		},
		/**
		 * Number of line items.
		 *
		 * @return {number} The count.
		 */
		lineCount() {
			return this.lines.length
		},
		/**
		 * Whether the current user is treated as a manager in the UI.
		 *
		 * Server-side authorization is authoritative; this only hides the button
		 * for clearly non-privileged users. NC admins are always managers.
		 *
		 * @return {boolean} Whether to show manager-only actions.
		 */
		isManager() {
			return typeof window.OC?.isUserAdmin === 'function' ? window.OC.isUserAdmin() : false
		},
		canEdit() {
			return ['draft', 'parked'].includes(this.status)
		},
		canConfirm() {
			return ['draft', 'parked'].includes(this.status)
		},
		canPark() {
			return this.status === 'draft'
		},
		canResume() {
			return this.status === 'parked'
		},
		canSettle() {
			return this.status === 'confirmed'
		},
		canRefund() {
			return ['confirmed', 'settled'].includes(this.status) && this.isManager
		},
		/**
		 * Sidebar props (files / notes / audit trail).
		 *
		 * @return {object} The props.
		 */
		sidebarProps() {
			const config = this.objectStore.objectTypeRegistry?.posTransaction || {}
			return {
				title: t('pipelinq', 'POS transaction'),
				register: config.register || '',
				schema: config.schema || '',
			}
		},
	},
	async mounted() {
		await this.load()
	},
	methods: {
		formatEur,
		/**
		 * Load the transaction and its lines.
		 */
		async load() {
			this.loading = true
			try {
				this.transaction = await this.objectStore.fetchObject('posTransaction', this.transactionId) || {}
				await this.objectStore.fetchCollection('posTransactionLine', { transaction: this.transactionId, _limit: 500 })
				const rows = this.objectStore.getCollection('posTransactionLine')?.results || []
				this.lines = rows
					.filter(l => l.transaction === this.transactionId)
					.sort((a, b) => (a.sortOrder || 0) - (b.sortOrder || 0))
			} finally {
				this.loading = false
			}
		},
		/**
		 * Navigate to the editor.
		 */
		edit() {
			this.$router.push({ name: 'PosTransactionEdit', params: { id: this.transactionId } })
		},
		/**
		 * Call a lifecycle action endpoint and reload.
		 *
		 * @param {string} action The action path segment.
		 * @param {object} body The optional request body.
		 * @param {string} successMessage The success toast.
		 */
		async lifecycle(action, body, successMessage) {
			this.busy = true
			try {
				const response = await fetch(
					generateUrl(`/apps/pipelinq/api/pos-transactions/${this.transactionId}/${action}`),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
						body: JSON.stringify(body || {}),
					},
				)
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					showError(data.error || t('pipelinq', 'Action failed.'))
					return false
				}
				showSuccess(successMessage)
				await this.load()
				return true
			} catch (e) {
				showError(t('pipelinq', 'Action failed.'))
				return false
			} finally {
				this.busy = false
			}
		},
		confirm() {
			this.lifecycle('confirm', {}, t('pipelinq', 'Transaction confirmed.'))
		},
		settle() {
			this.lifecycle('settle', {}, t('pipelinq', 'Transaction settled.'))
		},
		park() {
			this.lifecycle('park', {}, t('pipelinq', 'Transaction parked.'))
		},
		resume() {
			this.lifecycle('resume', {}, t('pipelinq', 'Transaction resumed.'))
		},
		/**
		 * Submit a refund with a reason.
		 *
		 * @param {string} reason The refund reason.
		 */
		async refund(reason) {
			const ok = await this.lifecycle('refund', { reason }, t('pipelinq', 'Transaction refunded.'))
			if (ok) {
				this.showRefund = false
			}
		},
	},
}
</script>

<style scoped>
.info-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.info-field--wide {
	grid-column: 1 / -1;
}

.info-field label {
	display: block;
	font-weight: bold;
	margin-bottom: 2px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.pos-detail__lines,
.pos-detail__tax {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 12px;
}

.pos-detail__lines th,
.pos-detail__tax th {
	text-align: left;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.pos-detail__lines td,
.pos-detail__tax td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.num {
	text-align: right;
}

.empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
