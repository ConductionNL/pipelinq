<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<CnDetailPage
		:title="refund.reference || t('pipelinq', 'Refund')"
		:subtitle="t('pipelinq', 'POS refund')"
		:back-route="{ name: 'PosRefunds' }"
		:back-label="t('pipelinq', 'Back to list')"
		:loading="loading"
		:sidebar="{ enabled: !loading }"
		object-type="pipelinq_posRefund"
		:object-id="refundId"
		:sidebar-props="sidebarProps">
		<template #actions>
			<NcButton v-if="canConfirm"
				type="primary"
				:disabled="busy"
				@click="confirm">
				{{ t('pipelinq', 'Bevestigen') }}
			</NcButton>
			<NcButton v-if="canReject"
				type="error"
				:disabled="busy"
				@click="showReject = true">
				{{ t('pipelinq', 'Afwijzen') }}
			</NcButton>
		</template>

		<CnDetailCard :title="t('pipelinq', 'Refund information')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Reference') }}</label>
					<span>{{ refund.reference || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Original transaction') }}</label>
					<a v-if="refund.originalTransaction" href="#" @click.prevent="openTransaction">
						{{ originalTransaction.reference || refund.originalTransaction }}
					</a>
					<span v-else>-</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Cashier') }}</label>
					<span>{{ refund.cashier || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Status') }}</label>
					<CnStatusBadge :status="refund.status" :label="statusLabel" />
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Refund reason') }}</label>
					<span>{{ reasonLabel(refund.refundReason) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Payment method') }}</label>
					<span>{{ refund.paymentMethod || '-' }}</span>
				</div>
				<div v-if="refund.confirmedAt" class="info-field">
					<label>{{ t('pipelinq', 'Confirmed at') }}</label>
					<span>{{ formatDate(refund.confirmedAt) }}</span>
				</div>
				<div v-if="refund.rejectedAt" class="info-field">
					<label>{{ t('pipelinq', 'Rejected at') }}</label>
					<span>{{ formatDate(refund.rejectedAt) }}</span>
				</div>
				<div v-if="refund.notes" class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Notes') }}</label>
					<span>{{ refund.notes }}</span>
				</div>
				<div v-if="refund.rejectionReason" class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Rejection reason') }}</label>
					<span>{{ refund.rejectionReason }}</span>
				</div>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Returned items')">
			<table class="pos-refund-detail__lines">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Description') }}</th>
						<th class="num">
							{{ t('pipelinq', 'Original qty') }}
						</th>
						<th class="num">
							{{ t('pipelinq', 'Returned qty') }}
						</th>
						<th>{{ t('pipelinq', 'Reason') }}</th>
						<th>{{ t('pipelinq', 'Restock') }}</th>
						<th class="num">
							{{ t('pipelinq', 'Refund total') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in lineRows" :key="row.id">
						<td>{{ row.description }}</td>
						<td class="num">
							{{ row.originalQuantity }}
						</td>
						<td class="num">
							{{ row.returnedQuantity }}
						</td>
						<td>{{ reasonLabel(row.returnReason) }}</td>
						<td>{{ row.restock ? t('pipelinq', 'Yes') : t('pipelinq', 'No') }}</td>
						<td class="num">
							{{ formatEur(row.lineTotal) }}
						</td>
					</tr>
					<tr v-if="lineRows.length === 0">
						<td colspan="6" class="empty">
							{{ t('pipelinq', 'No returned items.') }}
						</td>
					</tr>
				</tbody>
			</table>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Refund totals')">
			<PosRefundTotalsPanel :lines="lineRows" />
		</CnDetailCard>

		<PosRefundRejectDialog
			v-if="showReject"
			:submitting="busy"
			@close="showReject = false"
			@confirm="reject" />
	</CnDetailPage>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { CnDetailPage, CnDetailCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import PosRefundTotalsPanel from '../../components/pos/PosRefundTotalsPanel.vue'
import PosRefundRejectDialog from '../../modals/PosRefundRejectDialog.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { formatEur } from '../../services/posTotals.js'

const STATUS_LABELS = {
	pending: 'In behandeling',
	completed: 'Voltooid',
	rejected: 'Afgewezen',
}

export default {
	name: 'PosRefundDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		CnStatusBadge,
		PosRefundTotalsPanel,
		PosRefundRejectDialog,
	},
	props: {
		posRefundId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			refund: {},
			lines: [],
			originalLines: [],
			originalTransaction: {},
			reasons: [],
			loading: false,
			busy: false,
			showReject: false,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		/**
		 * The refund id from the prop or route.
		 *
		 * @return {string|null} The id.
		 */
		refundId() {
			return this.posRefundId || this.$route.params.id || null
		},
		/**
		 * The current refund status.
		 *
		 * @return {string} The status.
		 */
		status() {
			return this.refund.status || 'pending'
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
		 * Whether the current user is treated as a manager in the UI. Server-side
		 * authorization is authoritative; this only hides the buttons for clearly
		 * non-privileged users. NC admins are always managers.
		 *
		 * @return {boolean} Whether to show manager-only actions.
		 */
		isManager() {
			return typeof window.OC?.isUserAdmin === 'function' ? window.OC.isUserAdmin() : false
		},
		canConfirm() {
			return this.status === 'pending' && this.isManager
		},
		canReject() {
			return this.status === 'pending' && this.isManager
		},
		/**
		 * Display rows joining each refund line with its original transaction line.
		 *
		 * @return {Array<object>} The rows.
		 */
		lineRows() {
			return this.lines.map(line => {
				const original = this.originalLines.find(o => o.id === line.originalLine) || {}
				return {
					id: line.id,
					description: original.description || '-',
					originalQuantity: original.quantity ?? '-',
					returnedQuantity: line.returnedQuantity,
					returnReason: line.returnReason,
					restock: line.restock ?? true,
					taxAmount: line.taxAmount || 0,
					lineTotal: line.lineTotal || 0,
				}
			})
		},
		/**
		 * Sidebar props (files / notes / audit trail).
		 *
		 * @return {object} The props.
		 */
		sidebarProps() {
			const config = this.objectStore.objectTypeRegistry?.posRefund || {}
			return {
				title: t('pipelinq', 'POS refund'),
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
		 * Format an ISO timestamp for display.
		 *
		 * @param {string} value The ISO date string.
		 * @return {string} The formatted date.
		 */
		formatDate(value) {
			if (!value) {
				return '-'
			}
			return new Date(value).toLocaleString('nl-NL')
		},
		/**
		 * Resolve a refundReason id to its label.
		 *
		 * @param {string} id The reason id.
		 * @return {string} The label.
		 */
		reasonLabel(id) {
			const reason = this.reasons.find(r => r.id === id)
			return reason ? (reason.label || reason.code) : (id || '-')
		},
		/**
		 * Load the refund, its lines, the original transaction context and reasons.
		 */
		async load() {
			this.loading = true
			try {
				this.refund = await this.objectStore.fetchObject('posRefund', this.refundId) || {}

				await this.objectStore.fetchCollection('posRefundLine', { refund: this.refundId, _limit: 500 })
				this.lines = (this.objectStore.getCollection('posRefundLine')?.results || [])
					.filter(l => l.refund === this.refundId)

				await this.objectStore.fetchCollection('refundReason', { _limit: 100 })
				this.reasons = this.objectStore.getCollection('refundReason')?.results || []

				if (this.refund.originalTransaction) {
					this.originalTransaction = await this.objectStore.fetchObject('posTransaction', this.refund.originalTransaction) || {}
					await this.objectStore.fetchCollection('posTransactionLine', { transaction: this.refund.originalTransaction, _limit: 500 })
					this.originalLines = (this.objectStore.getCollection('posTransactionLine')?.results || [])
						.filter(l => l.transaction === this.refund.originalTransaction)
				}
			} finally {
				this.loading = false
			}
		},
		/**
		 * Navigate to the original transaction detail.
		 */
		openTransaction() {
			this.$router.push({ name: 'PosTransactionDetail', params: { id: this.refund.originalTransaction } })
		},
		/**
		 * Call a lifecycle action endpoint and reload.
		 *
		 * @param {string} action The action path segment.
		 * @param {object} body The optional request body.
		 * @param {string} successMessage The success toast.
		 * @return {boolean} Whether the action succeeded.
		 */
		async lifecycle(action, body, successMessage) {
			this.busy = true
			try {
				const response = await fetch(
					generateUrl(`/apps/pipelinq/api/pos-refunds/${this.refundId}/${action}`),
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
		/**
		 * Confirm the refund.
		 */
		confirm() {
			this.lifecycle('confirm', {}, t('pipelinq', 'Refund completed.'))
		},
		/**
		 * Reject the refund with a reason.
		 *
		 * @param {string} reason The rejection reason.
		 */
		async reject(reason) {
			const ok = await this.lifecycle('reject', { reason }, t('pipelinq', 'Refund rejected.'))
			if (ok) {
				this.showReject = false
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

.pos-refund-detail__lines {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 12px;
}

.pos-refund-detail__lines th {
	text-align: left;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.pos-refund-detail__lines td {
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
