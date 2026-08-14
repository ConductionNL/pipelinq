<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - PaymentMethodSelector — dropdown that appears in PosTransactionForm
  - before the "Bevestigen" button. Shows Contant + Cadeaubon + Rekening
  - + every configured-and-active payment provider (Mollie, CCV, Adyen,
  - Stripe). Emits @input with { paymentMethod, providerName } for the
  - parent to forward into PosPaymentService.initiatePayment().
  -
  - @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-008
  -->
<template>
	<div class="payment-method-selector">
		<NcSelect
			:model-value="selection"
			:options="combinedOptions"
			:input-label="t('pipelinq', 'Payment method')"
			label="label"
			:reduce="(o) => o.value"
			:loading="loading"
			@update:model-value="onSelect" />
		<p
			v-if="selection && providerOf(selection) === 'mollie'"
			class="payment-method-selector__hint">
			{{
				t(
					'pipelinq',
					'Customer is redirected to Mollie to complete the iDEAL/Bancontact payment.',
				)
			}}
		</p>
		<p
			v-if="selection && providerOf(selection) === 'ccv'"
			class="payment-method-selector__hint">
			{{ t('pipelinq', 'Customer pays at the CCV PIN terminal.') }}
		</p>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import { listProviders } from '../../services/posPaymentApi.js'

const STATIC_OPTIONS = [
	{ value: 'cash', label: 'Contant', provider: 'cash' },
	{ value: 'voucher', label: 'Cadeaubon', provider: 'voucher' },
	{ value: 'account', label: 'Rekening', provider: 'account' },
]

export default {
	name: 'PaymentMethodSelector',
	components: { NcSelect },
	props: {
		// Selected value, format: provider:method (e.g. "mollie:ideal", "cash").
		//
		// ⚠️ This was `value` + `$emit('input')` — the Vue 2 v-model contract.
		// Its only consumer (PosTransactionForm) binds it with `v-model`, and in
		// Vue 3 `v-model` means `modelValue` + `update:modelValue`, so BOTH
		// halves went dead: the prop stayed at its `''` default (the dropdown
		// never showed the chosen method) and the parent's `paymentSelection`
		// never updated. Neither half errors — and the separate `change` emit
		// still fired, so checkout would limp along looking almost right.
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
	// Declared explicitly so Vue 3 does not also fall these through onto the
	// root element as attributes, and so the v-model contract is self-evident.
	emits: ['update:modelValue', 'change'],
	data() {
		return {
			providers: [],
			loading: true,
		}
	},
	computed: {
		selection() {
			return this.modelValue || null
		},
		combinedOptions() {
			const opts = STATIC_OPTIONS.filter(
				(o) => o.value !== 'account' || this.clientSelected,
			).map((o) => ({ ...o, label: t('pipelinq', o.label) }))
			for (const p of this.activeProviders) {
				if (p.name === 'mollie') {
					opts.push({
						value: 'mollie:ideal',
						label: t('pipelinq', '{name} — iDEAL', {
							name: p.displayName,
						}),
						provider: 'mollie',
						method: 'ideal',
					})
					opts.push({
						value: 'mollie:bancontact',
						label: t('pipelinq', '{name} — Bancontact', {
							name: p.displayName,
						}),
						provider: 'mollie',
						method: 'bancontact',
					})
					opts.push({
						value: 'mollie:creditcard',
						label: t('pipelinq', '{name} — Credit card', {
							name: p.displayName,
						}),
						provider: 'mollie',
						method: 'creditcard',
					})
				} else if (p.name === 'ccv') {
					opts.push({
						value: 'ccv:card',
						label: t('pipelinq', '{name} (PIN-terminal)', {
							name: p.displayName,
						}),
						provider: 'ccv',
						method: 'card',
					})
				} else if (p.name === 'adyen') {
					opts.push({
						value: 'adyen:card',
						label: t('pipelinq', '{name} — Card', {
							name: p.displayName,
						}),
						provider: 'adyen',
						method: 'card',
					})
				} else if (p.name === 'stripe') {
					opts.push({
						value: 'stripe:card',
						label: t('pipelinq', '{name} — Card / Wallet', {
							name: p.displayName,
						}),
						provider: 'stripe',
						method: 'card',
					})
				}
			}
			return opts
		},
		activeProviders() {
			return this.providers.filter((p) => p.isActive)
		},
	},
	async mounted() {
		try {
			this.providers = await listProviders()
		} catch (e) {
			// Non-admin user — endpoint returns 403; fall back to static options.
			this.providers = []
		} finally {
			this.loading = false
		}
	},
	methods: {
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
