<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<NcDialog
		:name="isEdit ? t('pipelinq', 'Edit variant') : t('pipelinq', 'Add variant')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="product-variant-dialog">
			<NcTextField
				:label="t('pipelinq', 'SKU')"
				:model-value="form.sku"
				:error="!!skuError"
				:helper-text="skuError"
				@update:model-value="
					(v) => {
						form.sku = v
						skuError = ''
					}
				" />
			<NcTextField
				:label="t('pipelinq', 'Variant name')"
				:model-value="form.name"
				@update:model-value="(v) => (form.name = v)" />
			<NcTextField
				:label="t('pipelinq', 'Attributes (e.g. maat=S, kleur=Wit)')"
				:model-value="form.attributesText"
				@update:model-value="(v) => (form.attributesText = v)" />
			<NcTextField
				:label="t('pipelinq', 'Unit price')"
				:model-value="form.unitPrice"
				type="number"
				@update:model-value="(v) => (form.unitPrice = v)" />
			<NcTextField
				:label="t('pipelinq', 'Barcode (EAN/UPC)')"
				:model-value="form.barcode"
				@update:model-value="(v) => (form.barcode = v)" />
			<NcSelect
				v-model="form.status"
				input-id="variant-status"
				:input-label="t('pipelinq', 'Status')"
				:aria-label-combobox="t('pipelinq', 'Status')"
				:options="statusOptions" />
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" @click="submit">
				{{ t('pipelinq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcTextField, NcSelect } from '@nextcloud/vue'

export default {
	name: 'ProductVariantDialog',
	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcSelect,
	},
	props: {
		variant: {
			type: Object,
			default: null,
		},
		defaultPrice: {
			type: [Number, String],
			default: 0,
		},
		existingSkus: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['close', 'save'],
	data() {
		return {
			statusOptions: ['active', 'inactive'],
			skuError: '',
			form: {
				sku: '',
				name: '',
				attributesText: '',
				unitPrice: String(this.defaultPrice ?? 0),
				barcode: '',
				status: 'active',
			},
		}
	},
	computed: {
		isEdit() {
			return !!this.variant
		},
	},
	mounted() {
		if (this.variant) {
			this.form = {
				sku: this.variant.sku || '',
				name: this.variant.name || '',
				attributesText: this.attributesToText(this.variant.attributes),
				unitPrice:
					this.variant.unitPrice !== undefined
						? String(this.variant.unitPrice)
						: String(this.defaultPrice ?? 0),
				barcode: this.variant.barcode || '',
				status: this.variant.status || 'active',
			}
		}
	},
	methods: {
		/**
		 * Serialize an attributes map into "key=value, key=value" text.
		 *
		 * @param {object} attributes The attributes map.
		 * @return {string} The serialized text.
		 */
		attributesToText(attributes) {
			if (!attributes || typeof attributes !== 'object') {
				return ''
			}
			return Object.entries(attributes)
				.map(([k, v]) => `${k}=${v}`)
				.join(', ')
		},
		/**
		 * Parse "key=value, key=value" text into an attributes map.
		 *
		 * @param {string} text The text.
		 * @return {object} The attributes map.
		 */
		textToAttributes(text) {
			const result = {}
			text.split(',').forEach((pair) => {
				const [k, ...rest] = pair.split('=')
				const key = (k || '').trim()
				const value = rest.join('=').trim()
				if (key) {
					result[key] = value
				}
			})
			return result
		},
		/**
		 * Validate SKU uniqueness and emit the variant on success.
		 */
		submit() {
			const sku = this.form.sku.trim()
			if (sku === '') {
				this.skuError = t('pipelinq', 'SKU is required')
				return
			}
			const originalSku = this.variant ? this.variant.sku : null
			const clash = this.existingSkus.some(
				(s) => s === sku && s !== originalSku,
			)
			if (clash) {
				this.skuError = t(
					'pipelinq',
					'SKU {sku} is already used by another variant',
					{ sku },
				)
				return
			}
			this.$emit('save', {
				sku,
				name: this.form.name.trim(),
				attributes: this.textToAttributes(this.form.attributesText),
				unitPrice: Number(this.form.unitPrice) || 0,
				barcode: this.form.barcode.trim(),
				status: this.form.status || 'active',
			})
		},
	},
}
</script>

<style scoped>
.product-variant-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}
</style>
