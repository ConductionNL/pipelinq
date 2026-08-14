<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - POS Customer-link admin settings panel — toggles which fields the lookup
  - searches, history depth, marketing-consent sync, and the on-account-
  - requires-customer invariant. Persists via /api/admin/pos-customer-settings.
  -
  - @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-006
  -->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'POS customer lookup')"
		:description="
			t(
				'pipelinq',
				'Configure how cashiers search for customers, how much history is shown at checkout, and how marketing consent is synced to the linked contact.',
			)
		">
		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else class="pos-customer-settings">
			<fieldset class="pos-customer-settings__group">
				<legend>{{ t('pipelinq', 'Search fields') }}</legend>
				<label v-for="field in availableFields" :key="field.id">
					<input
						type="checkbox"
						:checked="form.customerSearchFields.includes(field.id)"
						@change="toggleField(field.id, $event.target.checked)" />
					{{ field.label }}
				</label>
			</fieldset>

			<fieldset class="pos-customer-settings__group">
				<legend>{{ t('pipelinq', 'Purchase history depth') }}</legend>
				<label v-for="depth in availableDepths" :key="depth">
					<input
						type="radio"
						:value="depth"
						:checked="form.customerHistoryDepth === depth"
						name="history-depth"
						@change="form.customerHistoryDepth = depth" />
					{{ t('pipelinq', 'Last {n} transactions', { n: depth }) }}
				</label>
			</fieldset>

			<fieldset class="pos-customer-settings__group">
				<legend>{{ t('pipelinq', 'Pipelinq integration') }}</legend>
				<label>
					<input v-model="form.enablePipelinqSync" type="checkbox" />
					{{
						t(
							'pipelinq',
							'Automatically sync marketing consent to the linked contact.',
						)
					}}
				</label>
				<label>
					<input
						v-model="form.requireCustomerForOnAccount"
						type="checkbox" />
					{{
						t(
							'pipelinq',
							'Require a customer for on-account transactions.',
						)
					}}
				</label>
			</fieldset>

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
</template>

<script>
import { NcButton, NcLoadingIcon, NcSettingsSection } from '@nextcloud/vue'
import {
	getCustomerSettings,
	updateCustomerSettings,
} from '../../services/posCustomerApi.js'

const ALL_FIELDS = ['name', 'email', 'phone']
const ALL_DEPTHS = [10, 20, 50]

export default {
	name: 'PosCustomerSettings',
	components: {
		NcButton,
		NcLoadingIcon,
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
		 */
		availableFields() {
			return [
				{ id: 'name', label: t('pipelinq', 'Name') },
				{ id: 'email', label: t('pipelinq', 'E-mail') },
				{ id: 'phone', label: t('pipelinq', 'Phone') },
			]
		},
		/**
		 * Available history depths.
		 *
		 * @return {Array<number>} The depths.
		 */
		availableDepths() {
			return ALL_DEPTHS
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
.pos-customer-settings {
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-width: 720px;
}

.pos-customer-settings__group {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px 16px;
}

.pos-customer-settings__group legend {
	font-weight: 600;
	padding: 0 6px;
}

.pos-customer-settings__group label {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 6px 0;
}

.pos-customer-settings__actions {
	display: flex;
	align-items: center;
	gap: 16px;
}

.pos-customer-settings__status {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.pos-customer-settings__status--error {
	color: var(--color-error);
}
</style>
