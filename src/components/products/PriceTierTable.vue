<template>
	<div class="price-tier-table">
		<div v-if="rows.length === 0" class="section-empty">
			<p>{{ t('pipelinq', 'No price tiers defined') }}</p>
		</div>
		<div v-else class="viewTableContainer">
			<table class="viewTable">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'From quantity') }}</th>
						<th>{{ t('pipelinq', 'Unit price') }}</th>
						<th>{{ t('pipelinq', 'Label') }}</th>
						<th class="price-tier-table__actions-col" />
					</tr>
				</thead>
				<tbody>
					<tr v-for="(tier, index) in rows" :key="index" class="viewTableRow">
						<td>
							<NcTextField
								:label="t('pipelinq', 'From quantity')"
								:label-visible="false"
								:model-value="String(tier.minQuantity)"
								type="number"
								@update:model-value="v => updateTier(index, 'minQuantity', v)" />
						</td>
						<td>
							<NcTextField
								:label="t('pipelinq', 'Unit price')"
								:label-visible="false"
								:model-value="String(tier.unitPrice)"
								type="number"
								@update:model-value="v => updateTier(index, 'unitPrice', v)" />
						</td>
						<td>
							<NcTextField
								:label="t('pipelinq', 'Label')"
								:label-visible="false"
								:model-value="tier.label || ''"
								@update:model-value="v => updateTier(index, 'label', v)" />
						</td>
						<td class="price-tier-table__actions-col">
							<NcButton variant="tertiary" :aria-label="t('pipelinq', 'Remove tier')" @click="removeTier(index)">
								<template #icon>
									<Delete :size="20" />
								</template>
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="price-tier-table__toolbar">
			<NcButton @click="addTier">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('pipelinq', 'Add tier') }}
			</NcButton>
			<NcButton variant="primary" :disabled="saving" @click="save">
				{{ t('pipelinq', 'Save tiers') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import Plus from 'vue-material-design-icons/Plus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'PriceTierTable',
	components: {
		NcButton,
		NcTextField,
		Plus,
		Delete,
	},
	props: {
		product: {
			type: Object,
			required: true,
		},
	},
	emits: ['saved'],
	data() {
		return {
			rows: [],
			saving: false,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
	},
	watch: {
		product: {
			immediate: true,
			handler() {
				this.loadRows()
			},
		},
	},
	methods: {
		/**
		 * Load tiers from the product, sorted ascending by minQuantity.
		 */
		loadRows() {
			const tiers = Array.isArray(this.product.priceTiers) ? this.product.priceTiers : []
			this.rows = tiers
				.map(t => ({
					minQuantity: Number(t.minQuantity) || 1,
					unitPrice: Number(t.unitPrice) || 0,
					label: t.label || '',
				}))
				.sort((a, b) => a.minQuantity - b.minQuantity)
		},
		/**
		 * Update a field on a tier row.
		 *
		 * @param {number} index The row index.
		 * @param {string} field The field name.
		 * @param {string} value The new value.
		 */
		updateTier(index, field, value) {
			if (field === 'label') {
				this.rows[index][field] = value
			} else {
				this.rows[index][field] = Number(value)
			}
		},
		/**
		 * Append an empty tier row.
		 */
		addTier() {
			const nextQty = this.rows.length > 0 ? Math.max(...this.rows.map(r => r.minQuantity)) + 1 : 1
			this.rows.push({ minQuantity: nextQty, unitPrice: Number(this.product.unitPrice) || 0, label: '' })
		},
		/**
		 * Remove a tier row.
		 *
		 * @param {number} index The row index.
		 */
		removeTier(index) {
			this.rows.splice(index, 1)
		},
		/**
		 * Persist the sorted tiers to the product.
		 */
		async save() {
			this.saving = true
			const sorted = [...this.rows]
				.filter(r => r.minQuantity >= 1)
				.sort((a, b) => a.minQuantity - b.minQuantity)
			try {
				const result = await this.objectStore.saveObject('product', {
					...this.product,
					priceTiers: sorted,
				})
				if (result) {
					showSuccess(t('pipelinq', 'Price tiers saved'))
					this.$emit('saved', result)
				} else {
					showError(t('pipelinq', 'Failed to save price tiers'))
				}
			} catch (e) {
				showError(t('pipelinq', 'Failed to save price tiers'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.price-tier-table__toolbar {
	display: flex;
	gap: 12px;
	margin-top: 12px;
}

.price-tier-table__actions-col {
	width: 48px;
	text-align: right;
}

.viewTableContainer {
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	overflow: hidden;
	border: 1px solid var(--color-border);
}

.viewTable {
	width: 100%;
	border-collapse: collapse;
}

.viewTable th,
.viewTable td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.viewTable th {
	background-color: var(--color-background-dark);
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.section-empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 16px;
}
</style>
