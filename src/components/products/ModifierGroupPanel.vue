<template>
	<div class="modifier-group-panel">
		<div v-if="groups.length === 0" class="section-empty">
			<p>{{ t('pipelinq', 'No modifier groups defined') }}</p>
		</div>

		<div
			v-for="(group, gIndex) in groups"
			:key="gIndex"
			class="modifier-group-panel__card">
			<div class="modifier-group-panel__card-header">
				<NcTextField
					class="modifier-group-panel__name"
					:label="t('pipelinq', 'Group name')"
					:model-value="group.name || ''"
					@update:model-value="(v) => (group.name = v)" />
				<NcButton
					variant="tertiary"
					:aria-label="t('pipelinq', 'Remove group')"
					@click="removeGroup(gIndex)">
					<template #icon>
						<Delete :size="20" />
					</template>
				</NcButton>
			</div>

			<div class="modifier-group-panel__flags">
				<NcCheckboxRadioSwitch
					:model-value="!!group.required"
					@update:model-value="(v) => (group.required = v)">
					{{ t('pipelinq', 'Required') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:model-value="!!group.multiSelect"
					@update:model-value="(v) => (group.multiSelect = v)">
					{{ t('pipelinq', 'Multiple selection') }}
				</NcCheckboxRadioSwitch>
				<div class="modifier-group-panel__minmax">
					<NcTextField
						class="modifier-group-panel__minmax-field"
						:label="t('pipelinq', 'Min')"
						:model-value="String(group.min ?? 0)"
						type="number"
						@update:model-value="(v) => (group.min = Number(v))" />
					<NcTextField
						class="modifier-group-panel__minmax-field"
						:label="t('pipelinq', 'Max')"
						:model-value="String(group.max ?? 1)"
						type="number"
						@update:model-value="(v) => (group.max = Number(v))" />
				</div>
			</div>

			<table class="modifier-group-panel__options">
				<thead>
					<tr>
						<th scope="col">{{ t('pipelinq', 'Option') }}</th>
						<th scope="col">{{ t('pipelinq', 'Price adjustment') }}</th>
						<th scope="col" class="modifier-group-panel__actions-col" />
					</tr>
				</thead>
				<tbody>
					<tr v-for="(option, oIndex) in group.options" :key="oIndex">
						<td>
							<NcTextField
								:label="t('pipelinq', 'Option name')"
								:label-visible="false"
								:model-value="option.name || ''"
								@update:model-value="(v) => (option.name = v)" />
						</td>
						<td>
							<NcTextField
								:label="t('pipelinq', 'Price adjustment')"
								:label-visible="false"
								:model-value="String(option.priceAdjustment ?? 0)"
								type="number"
								@update:model-value="
									(v) => (option.priceAdjustment = Number(v))
								" />
							<span class="modifier-group-panel__hint">{{
								adjustmentLabel(option.priceAdjustment)
							}}</span>
						</td>
						<td class="modifier-group-panel__actions-col">
							<NcButton
								variant="tertiary"
								:aria-label="t('pipelinq', 'Remove option')"
								@click="removeOption(group, oIndex)">
								<template #icon>
									<Delete :size="20" />
								</template>
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>

			<NcButton variant="tertiary" @click="addOption(group)">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('pipelinq', 'Add option') }}
			</NcButton>
		</div>

		<div class="modifier-group-panel__toolbar">
			<NcButton @click="addGroup">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('pipelinq', 'Add group') }}
			</NcButton>
			<NcButton variant="primary" :disabled="saving" @click="save">
				{{ t('pipelinq', 'Save modifier groups') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import Plus from 'vue-material-design-icons/Plus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ModifierGroupPanel',
	components: {
		NcButton,
		NcTextField,
		NcCheckboxRadioSwitch,
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
			groups: [],
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
				this.loadGroups()
			},
		},
	},
	methods: {
		/**
		 * Deep-clone the product's modifier groups into local editable state.
		 */
		loadGroups() {
			const raw = Array.isArray(this.product.modifierGroups)
				? this.product.modifierGroups
				: []
			this.groups = raw.map((g) => ({
				name: g.name || '',
				required: !!g.required,
				multiSelect: !!g.multiSelect,
				min: Number(g.min ?? 0),
				max: Number(g.max ?? 1),
				options: Array.isArray(g.options)
					? g.options.map((o) => ({
							name: o.name || '',
							priceAdjustment: Number(o.priceAdjustment ?? 0),
						}))
					: [],
			}))
		},
		/**
		 * Human-readable price adjustment label.
		 *
		 * @param {number} value The adjustment amount.
		 * @return {string} The label.
		 */
		adjustmentLabel(value) {
			const amount = Number(value) || 0
			if (amount === 0) {
				return t('pipelinq', 'No surcharge')
			}
			const sign = amount > 0 ? '+' : '−'
			return `${sign}€${Math.abs(amount).toFixed(2)}`
		},
		/**
		 * Append a new empty modifier group.
		 */
		addGroup() {
			this.groups.push({
				name: '',
				required: false,
				multiSelect: false,
				min: 0,
				max: 1,
				options: [],
			})
		},
		/**
		 * Remove a modifier group.
		 *
		 * @param {number} index The group index.
		 */
		removeGroup(index) {
			this.groups.splice(index, 1)
		},
		/**
		 * Append an option to a group.
		 *
		 * @param {object} group The group.
		 */
		addOption(group) {
			group.options.push({ name: '', priceAdjustment: 0 })
		},
		/**
		 * Remove an option from a group.
		 *
		 * @param {object} group The group.
		 * @param {number} index The option index.
		 */
		removeOption(group, index) {
			group.options.splice(index, 1)
		},
		/**
		 * Persist the modifier groups to the product.
		 */
		async save() {
			this.saving = true
			try {
				const result = await this.objectStore.saveObject('product', {
					...this.product,
					modifierGroups: this.groups,
				})
				if (result) {
					showSuccess(t('pipelinq', 'Modifier groups saved'))
					this.$emit('saved', result)
				} else {
					showError(t('pipelinq', 'Failed to save modifier groups'))
				}
			} catch (e) {
				showError(t('pipelinq', 'Failed to save modifier groups'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.modifier-group-panel__card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 16px;
	margin-bottom: 16px;
	background: var(--color-main-background);
}

.modifier-group-panel__card-header {
	display: flex;
	align-items: flex-end;
	gap: 12px;
	margin-bottom: 12px;
}

.modifier-group-panel__name {
	flex: 1;
}

.modifier-group-panel__flags {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 16px;
	margin-bottom: 12px;
}

.modifier-group-panel__minmax {
	display: flex;
	gap: 8px;
}

.modifier-group-panel__minmax-field {
	width: 80px;
}

.modifier-group-panel__options {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 8px;
}

.modifier-group-panel__options th,
.modifier-group-panel__options td {
	padding: 6px 8px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.modifier-group-panel__actions-col {
	width: 48px;
	text-align: right;
}

.modifier-group-panel__hint {
	display: block;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.modifier-group-panel__toolbar {
	display: flex;
	gap: 12px;
	margin-top: 12px;
}

.section-empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 16px;
}
</style>
