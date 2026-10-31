<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - PaymentMethodSelector: the payment-method dropdown in PosTransactionForm
  - before Confirm. Shows Cash, Gift voucher, Account (with a client) and the
  - methods of every active payment provider, which any cashier may read from
  - GET /api/pos-payments/methods. Emits the choice as { paymentMethod,
  - providerName } for the parent to hand to initiatePayment().
  -
  - @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-008
  -->
<template>
	<div class="payment-method-selector">
		<NcSelect
			:modelValue="selection"
			:options="combinedOptions"
			:inputLabel="t('pipelinq', 'Payment method')"
			label="label"
			:reduce="(o) => o.value"
			:loading="loading"
			@update:modelValue="onSelect" />
		<p
			v-if="selectedHint"
			class="payment-method-selector__hint"
			data-testid="payment-method-hint">
			{{ selectedHint }}
		</p>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import { listPaymentMethods } from '../../services/posPaymentApi.js'

/** The tenders the till always offers; `Account` only with a client. */
const STATIC_OPTIONS = [
	{ value: 'cash', label: 'Cash', provider: 'cash' },
	{ value: 'voucher', label: 'Gift voucher', provider: 'voucher' },
	{ value: 'account', label: 'Account', provider: 'account' },
]

export default {
	name: 'PaymentMethodSelector',
	components: { NcSelect },
	props: {
		// Selected value, format: provider:method (e.g. "mollie:ideal", "cash").
		modelValue: {
			type: String,
			default: '',
		},

		// Only show option when a client is selected.
		clientSelected: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:modelValue', 'change'],
	data() {
		return {
			methods: [],
			loading: true,
		}
	},

	computed: {
		/**
		 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-008
		 */
		selection() {
			return this.modelValue || null
		},

		/**
		 * The static tenders, then one option per provider method.
		 *
		 * @return {Array<object>} The options.
		 * @spec openspec/specs/pos-payment-methods/spec.md#requirement-a-cashier-reads-the-payment-methods-of-the-active-providers
		 */
		combinedOptions() {
			const opts = STATIC_OPTIONS.filter(
				(o) => o.value !== 'account' || this.clientSelected,
			).map((o) => ({ ...o, label: t('pipelinq', o.label) }))
			for (const entry of this.methods) {
				opts.push({
					value: `${entry.provider}:${entry.method}`,
					label: t('pipelinq', '{provider}: {method}', {
						provider: entry.providerLabel || entry.provider,
						method: this.methodLabel(entry.method),
					}),
					provider: entry.provider,
					providerLabel: entry.providerLabel || entry.provider,
					type: entry.type,
					method: entry.method,
				})
			}
			return opts
		},

		/**
		 * What happens next for the chosen provider, by its type: an online
		 * provider takes the customer to its own page, a terminal provider
		 * takes the card at the till.
		 *
		 * @return {string} The hint, or '' for none.
		 * @spec openspec/specs/pos-payment-methods/spec.md#requirement-a-cashier-reads-the-payment-methods-of-the-active-providers
		 */
		selectedHint() {
			const option = this.combinedOptions.find((o) => o.value === this.selection)
			if (!option || !option.type) {
				return ''
			}
			if (option.type === 'terminal') {
				return t('pipelinq', 'The customer pays at the {provider} card terminal.', { provider: option.providerLabel })
			}
			return t('pipelinq', 'The customer is sent to {provider} to finish the payment.', { provider: option.providerLabel })
		},
	},

	/**
	 * @spec openspec/specs/pos-payment-methods/spec.md#requirement-a-cashier-reads-the-payment-methods-of-the-active-providers
	 */
	async mounted() {
		try {
			this.methods = await listPaymentMethods()
		} catch {
			this.methods = []
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * A method's name: brand names as written, the rest translated.
		 *
		 * @param {string} method The method id.
		 * @return {string} The label.
		 * @spec openspec/specs/pos-payment-methods/spec.md#requirement-a-cashier-reads-the-payment-methods-of-the-active-providers
		 */
		methodLabel(method) {
			const labels = {
				ideal: 'iDEAL',
				bancontact: 'Bancontact',
				card: t('pipelinq', 'Card'),
				creditcard: t('pipelinq', 'Credit card'),
			}
			return labels[method] || method
		},

		providerOf(combined) {
			if (!combined) return ''
			const idx = combined.indexOf(':')
			return idx === -1 ? combined : combined.slice(0, idx)
		},

		methodOf(combined) {
			if (!combined) return ''
			const idx = combined.indexOf(':')
			return idx === -1 ? combined : combined.slice(idx + 1)
		},

		/**
		 * @param {string} value The payment method the user picked.
		 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-008
		 */
		onSelect(value) {
			const combined = value && typeof value === 'object' ? value.value : value
			const providerName = this.providerOf(combined)
			const paymentMethod = this.methodOf(combined)
			this.$emit('update:modelValue', combined)
			this.$emit('change', { providerName, paymentMethod, combined })
		},
	},
}
</script>

<style scoped>
.payment-method-selector {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 12px;
}

.payment-method-selector__hint {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
