<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - POS Customer-link admin settings panel — toggles which fields the lookup
  - searches, history depth, marketing-consent sync, and the on-account-
  - requires-customer invariant. Persists via /api/admin/pos-customer-settings.
  -
  - @spec openspec/specs/pos-customer-link/spec.md#REQ-PCL-006
  -->
<template>
	<div class="pos-customer-settings-cards">
		<NcSettingsSection
			:name="t('pipelinq', 'Customer lookup at the till')"
			:description="
				t(
					'pipelinq',
					'Set how cashiers search for customers, how much purchase history they see at checkout and how marketing consent goes to the linked contact.',
				)
			">
			<NcLoadingIcon v-if="loading" :size="24" />

			<div v-else class="pos-customer-settings">
				<div
					class="pos-customer-settings__group"
					role="group"
					aria-labelledby="pos-lookup-fields">
					<span id="pos-lookup-fields" class="pos-customer-settings__label">
						{{ t('pipelinq', 'Search fields') }}
					</span>
					<NcCheckboxRadioSwitch
						v-for="field in availableFields"
						:key="field.id"
						:modelValue="form.customerSearchFields.includes(field.id)"
						:data-testid="`customer-settings-field-${field.id}`"
						@update:modelValue="(checked) => toggleField(field.id, checked)">
						{{ field.label }}
					</NcCheckboxRadioSwitch>
					<p class="pos-customer-settings__hint">
						{{ t('pipelinq', 'At least one search field is required.') }}
					</p>
				</div>

				<NcSelect
					v-model="form.customerHistoryDepth"
					class="pos-customer-settings__depth"
					:inputLabel="t('pipelinq', 'Purchase history depth')"
					:options="depthOptions"
					:reduce="(o) => o.value"
					label="label"
					:clearable="false"
					data-testid="customer-settings-depth" />

				<div
					class="pos-customer-settings__group"
					role="group"
					aria-labelledby="pos-lookup-sync">
					<span id="pos-lookup-sync" class="pos-customer-settings__label">
						{{ t('pipelinq', 'Pipelinq integration') }}
					</span>
					<NcCheckboxRadioSwitch v-model="form.enablePipelinqSync">
						{{
							t(
								'pipelinq',
								'Automatically sync marketing consent to the linked contact.',
							)
						}}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="form.requireCustomerForOnAccount">
						{{
							t(
								'pipelinq',
								'Require a customer for on-account transactions.',
							)
						}}
					</NcCheckboxRadioSwitch>
				</div>

				<div class="pos-customer-settings__actions">
					<NcButton
						variant="primary"
						:disabled="saving"
						data-testid="customer-settings-save"
						@click="save">
						{{ t('pipelinq', 'Save') }}
					</NcButton>
					<p
						v-if="statusMessage"
						class="pos-customer-settings__status"
						:class="{ 'pos-customer-settings__status--error': statusError }"
						role="status">
						{{ statusMessage }}
					</p>
				</div>
			</div>
		</NcSettingsSection>

		<NcSettingsSection
			class="pos-customer-settings__note"
			:name="t('pipelinq', 'Where cashiers see this')"
			:description="
				t(
					'pipelinq',
					'The customer card at the till: the search, the purchase history and the consent question.',
				)
			">
			<a
				:href="tillUrl"
				class="pos-customer-settings__till-link"
				data-testid="customer-settings-open-till">
				{{ t('pipelinq', 'Open the till') }}
			</a>
		</NcSettingsSection>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcSelect,
	NcSettingsSection,
} from '@nextcloud/vue'
import {
	getCustomerSettings,
	updateCustomerSettings,
} from '../../services/posCustomerApi.js'

const ALL_FIELDS = ['name', 'email', 'phone']
const ALL_DEPTHS = [5, 10, 20, 50]

export default {
	name: 'PosCustomerSettings',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcSelect,
		NcSettingsSection,
	},

	data() {
		return {
			form: {
				customerSearchFields: [...ALL_FIELDS],
				customerHistoryDepth: 10,
				enablePipelinqSync: true,
				requireCustomerForOnAccount: true,
			},

			loading: false,
			saving: false,
			statusMessage: '',
			statusError: false,
		}
	},

	computed: {
		/**
		 * Available search-field options with labels.
		 *
		 * @return {Array<object>} The options.
		 * @spec openspec/specs/pos-customer-link/spec.md#REQ-PCL-006
		 */
		availableFields() {
			return [
				{ id: 'name', label: t('pipelinq', 'Name') },
				{ id: 'email', label: t('pipelinq', 'Email address') },
				{ id: 'phone', label: t('pipelinq', 'Phone') },
			]
		},

		/**
		 * The history depths as select options ("Last 5 transactions").
		 *
		 * @return {Array<{value: number, label: string}>} The options.
		 * @spec openspec/specs/pos-customer-link/spec.md#REQ-PCL-006
		 */
		depthOptions() {
			return ALL_DEPTHS.map((n) => ({ value: n, label: t('pipelinq', 'Last {n} transactions', { n }) }))
		},

		/**
		 * The till, where cashiers see the customer card.
		 *
		 * @return {string} The URL.
		 * @spec openspec/specs/pos-customer-link/spec.md#REQ-PCL-006
		 */
		tillUrl() {
			return generateUrl('/apps/pipelinq/pos/new')
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Load the persisted settings.
		 */
		async load() {
			this.loading = true
			try {
				const settings = await getCustomerSettings()
				this.form = {
					customerSearchFields:
						Array.isArray(settings.customerSearchFields)
						&& settings.customerSearchFields.length
							? settings.customerSearchFields
							: [...ALL_FIELDS],

					customerHistoryDepth: ALL_DEPTHS.includes(
						Number(settings.customerHistoryDepth),
					)
						? Number(settings.customerHistoryDepth)
						: 10,

					enablePipelinqSync: settings.enablePipelinqSync !== false,
					requireCustomerForOnAccount:
						settings.requireCustomerForOnAccount !== false,
				}
			} catch {
				this.statusMessage = t('pipelinq', 'Could not load settings.')
				this.statusError = true
			} finally {
				this.loading = false
			}
		},

		/**
		 * Toggle a search field on / off.
		 *
		 * @param {string}  field   The field id.
		 * @param {boolean} checked Whether the checkbox is checked.
		 */
		toggleField(field, checked) {
			const current = new Set(this.form.customerSearchFields)
			if (checked) {
				current.add(field)
			} else {
				current.delete(field)
			}
			this.form.customerSearchFields = ALL_FIELDS.filter((f) => current.has(f))
		},

		/**
		 * Persist the form.
		 */
		async save() {
			if (this.form.customerSearchFields.length === 0) {
				this.statusMessage = t(
					'pipelinq',
					'At least one search field is required.',
				)
				this.statusError = true
				return
			}
			this.saving = true
			this.statusMessage = ''
			this.statusError = false
			try {
				await updateCustomerSettings({
					customerSearchFields: this.form.customerSearchFields,
					customerHistoryDepth: this.form.customerHistoryDepth,
					enablePipelinqSync: this.form.enablePipelinqSync,
					requireCustomerForOnAccount:
						this.form.requireCustomerForOnAccount,
				})
				this.statusMessage = t('pipelinq', 'Settings saved.')
			} catch {
				this.statusMessage = t('pipelinq', 'Could not save settings.')
				this.statusError = true
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.pos-customer-settings-cards.pos-customer-settings-cards {
	display: grid;
	grid-template-columns: minmax(0, 1fr) 300px;
	gap: 20px;
	align-items: start;
}

@media (max-width: 1024px) {
	.pos-customer-settings-cards.pos-customer-settings-cards {
		grid-template-columns: minmax(0, 1fr);
	}
}

.pos-customer-settings {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.pos-customer-settings__group {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.pos-customer-settings__label {
	font-weight: 600;
}

.pos-customer-settings__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.pos-customer-settings__depth {
	width: 100%;
}

.pos-customer-settings__actions {
	display: flex;
	flex-direction: row-reverse;
	align-items: center;
	gap: 16px;
}

.pos-customer-settings__note :deep(.settings-section__name) {
	color: var(--color-text-maxcontrast) !important;
	font-size: var(--cn-board-side-heading-size, 15px);
	font-weight: 600;
}

.pos-customer-settings__till-link {
	color: var(--color-primary-element);
	font-weight: 600;
	text-decoration: underline;
}

.pos-customer-settings__status {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.pos-customer-settings__status--error {
	color: var(--color-error);
}
</style>
