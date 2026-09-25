<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Detail-grid widget for PosTransactionDetail: how the transaction is paid.
  - The split tenders with their balance, and beside them the payment
  - provider's status when a provider handled the payment.
  -
  - Tender changes do not write to the transaction, so adding or removing one
  - reloads only the tenders. A page refresh reloads them too, quietly, so the
  - table stays on screen.
  -
  - @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-002
  - @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-009
  -->
<template>
	<CnWidgetWrapper
		class="pos-tx-payment"
		:title="title || t('pipelinq', 'Payment')"
		titleIconPosition="left"
		:showActions="false"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="CreditCardOutline" :size="20" />
		</template>
		<template v-if="canAddTender" #actions>
			<NcButton variant="secondary" @click="$refs.tenders.openAddDialog()">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('pipelinq', 'Add tender') }}
			</NcButton>
		</template>

		<div class="pos-tx-payment__body" :class="{ 'pos-tx-payment__body--split': hasPaymentInfo }">
			<section class="pos-tx-payment__tenders">
				<h4 class="pos-tx-payment__heading">
					{{ t('pipelinq', 'Tenders') }}
				</h4>
				<TenderEntryPanel
					v-if="transactionId"
					ref="tenders"
					:key="transactionId"
					:transactionId="transactionId"
					:transactionStatus="status" />
			</section>

			<PaymentStatusCard
				v-if="hasPaymentInfo"
				class="pos-tx-payment__provider"
				:transaction="transaction"
				:isManager="canRefundPayment"
				@updated="refreshPage" />
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import { emit, subscribe, unsubscribe } from '@nextcloud/event-bus'
import { NcButton } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import PaymentStatusCard from './PaymentStatusCard.vue'
import TenderEntryPanel from './TenderEntryPanel.vue'

export default {
	name: 'PosTransactionPaymentWidget',
	components: { CnIcon, CnWidgetWrapper, NcButton, PaymentStatusCard, Plus, TenderEntryPanel },
	// The host also hands over register / schema / store and the widget
	// content; none of them belong on the root element.
	inheritAttrs: false,

	props: {
		/** The transaction, from the detail page. */
		objectData: {
			type: Object,
			default: null,
		},

		/** The transaction id, from the detail page. */
		objectId: {
			type: String,
			default: '',
		},

		/** The manifest widget title. */
		title: {
			type: String,
			default: '',
		},
	},

	computed: {
		transaction() {
			return this.objectData || {}
		},

		transactionId() {
			return this.objectId || this.transaction.id || ''
		},

		status() {
			return this.transaction.status || 'draft'
		},

		/** @return {boolean} Mirrors TenderEntryPanel's own edit gate. */
		canAddTender() {
			return Boolean(this.transactionId) && !['settled', 'refunded'].includes(this.status)
		},

		/**
		 * Whether the current user is treated as a manager in the UI. The
		 * server authorises the provider refund.
		 *
		 * @return {boolean} Whether to show manager-only actions.
		 */
		isManager() {
			return typeof window.OC?.isUserAdmin === 'function'
				? window.OC.isUserAdmin()
				: false
		},

		/**
		 * The provider refund is offered on a confirmed or settled transaction,
		 * to a manager: the same gate as the header's Reverse.
		 *
		 * @return {boolean} Whether the provider card may offer a refund.
		 */
		canRefundPayment() {
			return this.isManager && ['confirmed', 'settled'].includes(this.status)
		},

		/** @return {boolean} Whether a payment provider handled this transaction. */
		hasPaymentInfo() {
			const tx = this.transaction
			return Boolean(tx.paymentProvider || tx.paymentSessionId || tx.paymentStatus || tx.paymentMethod)
		},
	},

	created() {
		subscribe('cn:page:refresh', this.onPageRefresh)
	},

	beforeUnmount() {
		unsubscribe('cn:page:refresh', this.onPageRefresh)
	},

	methods: {
		/**
		 * Reload the tenders along with the rest of the page.
		 *
		 * @param {object} [payload] The refresh payload.
		 */
		onPageRefresh(payload) {
			const panel = this.$refs.tenders
			if (!panel) {
				return
			}
			const done = panel.loadTenders({ silent: true })
			payload?.waitUntil?.(done)
		},

		/** A provider capture or refund changes the transaction itself. */
		refreshPage() {
			emit('cn:page:refresh', {})
		},
	},
}
</script>

<style scoped>
.pos-tx-payment {
	height: 100%;
	display: flex;
	flex-direction: column;
}

.pos-tx-payment__body {
	display: grid;
	grid-template-columns: minmax(0, 1fr);
	gap: 24px;
}

.pos-tx-payment__body--split {
	grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
}

.pos-tx-payment__heading {
	margin: 0 0 6px;
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.pos-tx-payment__provider {
	padding-inline-start: 24px;
	border-inline-start: 1px solid var(--color-border);
}

@media (max-width: 900px) {
	.pos-tx-payment__body--split {
		grid-template-columns: minmax(0, 1fr);
	}

	.pos-tx-payment__provider {
		padding-inline-start: 0;
		padding-top: 16px;
		border-inline-start: none;
		border-top: 1px solid var(--color-border);
	}
}
</style>
