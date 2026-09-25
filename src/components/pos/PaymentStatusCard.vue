<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - PaymentStatusCard — shows the payment provider, method, session id and
  - paymentStatus badge for a posTransaction detail view, plus context-
  - sensitive action buttons (capture / refund / retry) wired through
  - PosPaymentService.
  -
  - @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-009
  -->
<template>
	<div class="payment-status-card">
		<header class="payment-status-card__header">
			<h4>{{ t('pipelinq', 'Payment provider') }}</h4>
			<CnStatusBadge :label="statusLabel" :variant="statusVariant" size="small" />
		</header>
		<dl class="payment-status-card__grid">
			<template v-if="provider">
				<dt>{{ t('pipelinq', 'Provider') }}</dt>
				<dd>{{ providerLabel }}</dd>
			</template>
			<template v-if="method">
				<dt>{{ t('pipelinq', 'Method') }}</dt>
				<dd>{{ methodLabel }}</dd>
			</template>
			<template v-if="sessionId">
				<dt>{{ t('pipelinq', 'Session') }}</dt>
				<dd class="payment-status-card__session">
					{{ sessionId }}
				</dd>
			</template>
		</dl>

		<div v-if="hasActions" class="payment-status-card__actions">
			<NcButton
				v-if="status === 'pending'"
				variant="primary"
				:disabled="busy"
				@click="onCapture">
				{{ t('pipelinq', 'Complete') }}
			</NcButton>
			<NcButton
				v-if="canRefund"
				variant="warning"
				:disabled="busy"
				@click="onRefund">
				{{ t('pipelinq', 'Reverse') }}
			</NcButton>
			<NcButton
				v-if="status === 'failed'"
				variant="secondary"
				:disabled="busy"
				@click="onRetry">
				{{ t('pipelinq', 'Try again') }}
			</NcButton>
		</div>
		<ReversalReasonDialog
			v-if="showReversalDialog"
			@confirm="performRefund"
			@cancel="showReversalDialog = false" />
	</div>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton } from '@nextcloud/vue'
import ReversalReasonDialog from '../../dialogs/ReversalReasonDialog.vue'
import { capturePayment, refundPayment } from '../../services/posPaymentApi.js'

export default {
	name: 'PaymentStatusCard',
	components: { CnStatusBadge, NcButton, ReversalReasonDialog },
	props: {
		transaction: {
			type: Object,
			required: true,
		},

		isManager: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['retry', 'updated'],

	data() {
		return {
			busy: false,
			showReversalDialog: false,
		}
	},

	computed: {
		provider() {
			return this.transaction.paymentProvider || ''
		},

		method() {
			return this.transaction.paymentMethod || ''
		},

		sessionId() {
			return this.transaction.paymentSessionId || ''
		},

		status() {
			return this.transaction.paymentStatus || ''
		},

		/**
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-009
		 */
		providerLabel() {
			const map = {
				mollie: 'Mollie',
				ccv: 'CCV',
				adyen: 'Adyen',
				stripe: 'Stripe',
				cash: t('pipelinq', 'Cash'),
				voucher: t('pipelinq', 'Gift voucher'),
				account: t('pipelinq', 'Account'),
			}
			return map[this.provider] || this.provider
		},

		/**
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-009
		 */
		methodLabel() {
			const map = {
				ideal: 'iDEAL',
				bancontact: 'Bancontact',
				card: t('pipelinq', 'Card'),
				creditcard: t('pipelinq', 'Credit card'),
				cash: t('pipelinq', 'Cash'),
			}
			return map[this.method] || this.method
		},

		statusLabel() {
			const map = {
				pending: t('pipelinq', 'In progress'),
				captured: t('pipelinq', 'Authorized'),
				settled: t('pipelinq', 'Settled'),
				failed: t('pipelinq', 'Failed'),
				refunded: t('pipelinq', 'Reversed'),
			}
			return map[this.status] || this.status || t('pipelinq', 'Unknown')
		},

		/**
		 * The badge colour per payment status. The tinted (not solid) variant
		 * keeps the text readable in both light and dark themes.
		 *
		 * @return {string} A CnStatusBadge variant.
		 */
		statusVariant() {
			const map = {
				settled: 'success',
				captured: 'primary',
				pending: 'warning',
				failed: 'error',
				refunded: 'default',
			}
			return map[this.status] || 'default'
		},

		canRefund() {
			return (
				this.isManager
				&& (this.status === 'settled' || this.status === 'captured')
			)
		},

		hasActions() {
			return (
				this.status === 'pending'
				|| this.status === 'failed'
				|| this.canRefund
			)
		},
	},

	methods: {
		/**
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-009
		 */
		async onCapture() {
			this.busy = true
			try {
				const result = await capturePayment(
					this.transaction.id || this.transaction['@self']?.id,
				)
				this.$emit('updated', result.transaction || result)
				showSuccess(t('pipelinq', 'Payment completed.'))
			} catch (e) {
				showError(
					t('pipelinq', 'Completion failed: {error}', {
						error: e.message || 'unknown',
					}),
				)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Open the reversal-reason dialog.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-009
		 */
		onRefund() {
			this.showReversalDialog = true
		},

		/**
		 * Reverse the payment with the reason the dialog collected.
		 *
		 * @param {string} reason Why the payment is being reversed.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-009
		 */
		async performRefund(reason) {
			this.showReversalDialog = false
			if (!reason) {
				return
			}
			this.busy = true
			try {
				const result = await refundPayment(
					this.transaction.id || this.transaction['@self']?.id,
					reason,
				)
				this.$emit('updated', result.transaction || result)
				showSuccess(t('pipelinq', 'Payment reversed.'))
			} catch (e) {
				showError(
					t('pipelinq', 'Reversal failed: {error}', {
						error: e.message || 'unknown',
					}),
				)
			} finally {
				this.busy = false
			}
		},

		onRetry() {
			this.$emit('retry')
		},
	},
}
</script>

<style scoped>
.payment-status-card {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.payment-status-card__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.payment-status-card__header h4 {
	margin: 0;
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.payment-status-card__grid {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 12px;
	margin: 0;
}

.payment-status-card__grid dt {
	font-weight: 600;
}

.payment-status-card__grid dd {
	margin: 0;
}

.payment-status-card__session {
	font-family: monospace;
	font-size: 0.9em;
	word-break: break-all;
}

.payment-status-card__actions {
	display: flex;
	gap: 8px;
}
</style>
