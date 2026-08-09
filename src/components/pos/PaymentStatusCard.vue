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
			<h3>{{ t('pipelinq', 'Payment') }}</h3>
			<span :class="statusClass" class="payment-status-card__badge">{{ statusLabel }}</span>
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
		<ReversalReasonDialog v-if="showReversalDialog"
			@confirm="performRefund"
			@cancel="showReversalDialog = false" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import ReversalReasonDialog from '../../dialogs/ReversalReasonDialog.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import {
	capturePayment,
	refundPayment,
} from '../../services/posPaymentApi.js'

export default {
	name: 'PaymentStatusCard',
	components: { NcButton, ReversalReasonDialog },
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
		statusClass() {
			return {
				'payment-status-card__badge--settled': this.status === 'settled',
				'payment-status-card__badge--captured': this.status === 'captured',
				'payment-status-card__badge--pending': this.status === 'pending',
				'payment-status-card__badge--failed': this.status === 'failed',
				'payment-status-card__badge--refunded': this.status === 'refunded',
			}
		},
		canRefund() {
			return this.isManager && (this.status === 'settled' || this.status === 'captured')
		},
		hasActions() {
			return this.status === 'pending' || this.status === 'failed' || this.canRefund
		},
	},
	methods: {
		async onCapture() {
			this.busy = true
			try {
				const result = await capturePayment(this.transaction.id || this.transaction['@self']?.id)
				this.$emit('updated', result.transaction || result)
				showSuccess(t('pipelinq', 'Payment completed.'))
			} catch (e) {
				showError(t('pipelinq', 'Completion failed: {error}', { error: e.message || 'onbekend' }))
			} finally {
				this.busy = false
			}
		},
		onRefund() {
			this.showReversalDialog = true
		},
		async performRefund(reason) {
			this.showReversalDialog = false
			if (!reason) {
				return
			}
			this.busy = true
			try {
				const result = await refundPayment(this.transaction.id || this.transaction['@self']?.id, reason)
				this.$emit('updated', result.transaction || result)
				showSuccess(t('pipelinq', 'Payment reversed.'))
			} catch (e) {
				showError(t('pipelinq', 'Reversal failed: {error}', { error: e.message || 'onbekend' }))
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
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	background-color: var(--color-main-background);
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.payment-status-card__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.payment-status-card__header h3 {
	margin: 0;
	font-size: 1.05em;
}

.payment-status-card__badge {
	font-size: 0.85em;
	padding: 4px 10px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.payment-status-card__badge--settled {
	background-color: var(--color-success);
	color: var(--color-main-background);
}

.payment-status-card__badge--captured {
	background-color: var(--color-primary-element-light);
	color: var(--color-main-text);
}

.payment-status-card__badge--pending {
	background-color: var(--color-warning);
	color: var(--color-main-background);
}

.payment-status-card__badge--failed {
	background-color: var(--color-error);
	color: var(--color-main-background);
}

.payment-status-card__badge--refunded {
	background-color: var(--color-background-darker);
	color: var(--color-main-text);
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
