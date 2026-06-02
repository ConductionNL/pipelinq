<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2024 Conduction B.V.
-->

<template>
	<div class="payment-method-selector">
		<label class="payment-method-selector__label" for="payment-method">
			{{ t('pipelinq', 'Payment method') }}
		</label>
		<select id="payment-method" v-model="selected" class="payment-method-selector__select">
			<option value="cash">{{ t('pipelinq', 'Cash') }}</option>
			<option value="voucher">{{ t('pipelinq', 'Voucher') }}</option>
			<option v-if="clientSelected" value="account">{{ t('pipelinq', 'Account sale') }}</option>
			<option
				v-for="provider in enabledProviders"
				:key="provider.name"
				:value="provider.name">
				{{ provider.displayName }}
			</option>
		</select>

		<div v-if="subMethods.length > 0" class="payment-method-selector__sub">
			<label
				v-for="method in subMethods"
				:key="method.value"
				class="payment-method-selector__sub-option">
				<input v-model="subMethod" type="radio" :value="method.value">
				{{ method.label }}
			</label>
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'PaymentMethodSelector',
	props: {
		clientSelected: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			selected: 'cash',
			subMethod: '',
			providers: [],
		}
	},
	computed: {
		/**
		 * Active providers only (the admin can disable a provider per REQ-PAY-007).
		 *
		 * @return {Array} The enabled providers.
		 */
		enabledProviders() {
			return this.providers.filter((provider) => provider.isActive)
		},
		/**
		 * The current provider config for the selected method, if any.
		 *
		 * @return {object|null} The selected provider.
		 */
		currentProvider() {
			return this.providers.find((provider) => provider.name === this.selected) || null
		},
		/**
		 * Sub-method radio options for the selected provider (Mollie offers a
		 * choice of iDEAL / Bancontact; others have none).
		 *
		 * @return {Array} The sub-method options.
		 */
		subMethods() {
			if (this.selected !== 'mollie') {
				return []
			}
			return [
				{ value: 'ideal', label: t('pipelinq', 'iDEAL') },
				{ value: 'bancontact', label: t('pipelinq', 'Bancontact') },
			]
		},
	},
	watch: {
		selected() {
			this.subMethod = this.subMethods.length > 0 ? this.subMethods[0].value : ''
			this.emitChange()
		},
		subMethod() {
			this.emitChange()
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		/**
		 * Load enabled providers so only active ones appear in the dropdown.
		 *
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-008
		 */
		async load() {
			try {
				const response = await fetch(generateUrl('/apps/pipelinq/api/payment-providers'), {
					headers: { requesttoken: OC.requestToken, 'OCS-APIREQUEST': 'true' },
				})
				if (!response.ok) {
					return
				}
				const data = await response.json().catch(() => ({}))
				this.providers = data.providers || []
			} catch (e) {
				this.providers = []
			}
		},
		/**
		 * Resolve the selected method + provider and emit @change for the parent.
		 *
		 * Cash / voucher / account are bookkeeping-only (no provider call); a
		 * provider selection carries the normalized method.
		 *
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-008
		 */
		emitChange() {
			const builtins = ['cash', 'voucher', 'account']
			if (builtins.includes(this.selected)) {
				this.$emit('change', { providerName: this.selected, paymentMethod: this.selected })
				return
			}
			let method = 'card'
			if (this.selected === 'mollie') {
				method = this.subMethod || 'ideal'
			}
			this.$emit('change', { providerName: this.selected, paymentMethod: method })
		},
	},
}
</script>

<style scoped>
.payment-method-selector { display: flex; flex-direction: column; gap: 8px; margin: 12px 0; }
.payment-method-selector__label { font-weight: 600; }
.payment-method-selector__select { max-width: 320px; padding: 6px 8px; }
.payment-method-selector__sub { display: flex; gap: 16px; }
.payment-method-selector__sub-option { display: flex; align-items: center; gap: 6px; }
</style>
