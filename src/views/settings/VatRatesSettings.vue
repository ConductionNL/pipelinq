<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - The VAT rate per VAT class (pipelinq-forms-review, B4). Stored as the
  - `vat_rates` JSON setting; the product form labels its VAT class options
  - with these rates and the POS catalogue prices with them.
  -->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'VAT rates')"
		:description="
			t(
				'pipelinq',
				'Set the VAT rate for each VAT class. Products use these rates on receipts and invoices.',
			)
		">
		<div class="vat-rates">
			<NcTextField
				v-for="cls in classes"
				:key="cls.id"
				:label="cls.label"
				:modelValue="String(rates[cls.id])"
				type="number"
				min="0"
				max="100"
				step="0.1"
				@update:modelValue="(v) => (rates[cls.id] = v)" />
		</div>
		<NcButton variant="primary" :disabled="saving" @click="save">
			<template #icon>
				<NcLoadingIcon v-if="saving" :size="20" />
			</template>
			{{ t('pipelinq', 'Save VAT rates') }}
		</NcButton>
		<NcNoteCard v-if="message" :type="messageType">
			{{ message }}
		</NcNoteCard>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
	NcSettingsSection,
	NcTextField,
} from '@nextcloud/vue'

const DEFAULT_RATES = { high: 21, low: 9, zero: 0, exempt: 0 }

/**
 * @param {string} stored The stored JSON, or nothing.
 * @return {object} What it holds, or an empty object when it cannot be read.
 * @spec openspec/specs/product-catalog/spec.md
 */
function storedRates(stored) {
	try {
		return JSON.parse(stored || '{}') || {}
	} catch {
		return {}
	}
}

/**
 * The typed rates as numbers, or null when one is not a number from 0 to 100.
 *
 * @param {object} rates The rates per VAT class, as typed.
 * @return {object|null} The rates to store.
 * @spec openspec/specs/product-catalog/spec.md
 */
function checkedRates(rates) {
	const out = {}
	for (const cls of Object.keys(DEFAULT_RATES)) {
		const value = Number(rates[cls])
		if (!Number.isFinite(value) || value < 0 || value > 100) {
			return null
		}
		out[cls] = value
	}
	return out
}

/**
 * Read the stored `vat_rates` JSON over the Dutch defaults.
 *
 * @param {string} stored The stored JSON, or ''.
 * @return {object} Rate per class.
 * @spec openspec/specs/product-catalog/spec.md
 */
export function parseVatRates(stored) {
	const parsed = storedRates(stored)
	const rates = { ...DEFAULT_RATES }
	for (const cls of Object.keys(DEFAULT_RATES)) {
		const value = Number(parsed[cls])
		if (parsed[cls] !== undefined && Number.isFinite(value)) {
			rates[cls] = value
		}
	}
	return rates
}

export default {
	name: 'VatRatesSettings',
	components: {
		NcSettingsSection,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	props: {
		config: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			rates: parseVatRates(this.config.vat_rates),
			saving: false,
			message: '',
			messageType: 'success',
		}
	},

	computed: {
		/**
		 * The four classes with their labels.
		 *
		 * @return {Array<{id: string, label: string}>} The classes.
		 * @spec openspec/specs/product-catalog/spec.md
		 */
		classes() {
			return [
				{ id: 'high', label: t('pipelinq', 'High rate (%)') },
				{ id: 'low', label: t('pipelinq', 'Low rate (%)') },
				{ id: 'zero', label: t('pipelinq', 'Zero rate (%)') },
				{ id: 'exempt', label: t('pipelinq', 'Exempt (%)') },
			]
		},
	},

	watch: {
		'config.vat_rates': {
			/**
			 * Follow the stored value once the page has loaded it.
			 *
			 * @param {string} value The stored JSON.
			 * @spec openspec/specs/product-catalog/spec.md
			 */
			handler(value) {
				this.rates = parseVatRates(value)
			},
		},
	},

	methods: {
		/**
		 * Store the rates; a rate outside 0-100 is refused before saving.
		 *
		 * @spec openspec/specs/product-catalog/spec.md
		 */
		async save() {
			this.message = ''
			const out = checkedRates(this.rates)
			if (out === null) {
				this.showMessage(t('pipelinq', 'Each rate must be a number from 0 to 100.'), 'error')
				return
			}
			this.saving = true
			try {
				await axios.post(generateUrl('/apps/pipelinq/api/settings'), {
					vat_rates: JSON.stringify(out),
				})
				this.showMessage(t('pipelinq', 'VAT rates saved. Reload the app to see the new labels.'), 'success')
			} catch {
				this.showMessage(t('pipelinq', 'Could not save the VAT rates.'), 'error')
			} finally {
				this.saving = false
			}
		},

		/**
		 * @param {string} message The message to show.
		 * @param {string} type Its kind: `success` or `error`.
		 */
		showMessage(message, type) {
			this.message = message
			this.messageType = type
		},
	},
}
</script>

<style scoped>
.vat-rates {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
	gap: 12px;
	max-width: 700px;
	margin-bottom: 12px;
}
</style>
