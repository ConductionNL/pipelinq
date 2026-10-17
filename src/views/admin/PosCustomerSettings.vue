<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - POS customer-link admin settings (PqKassaKlantzoeken): which fields the
  - lookup searches, the history depth, marketing-consent sync and the
  - on-account-requires-customer rule, as a form card beside a card that says
  - where cashiers see it. Persists via /api/admin/pos-customer-settings. The
  - in-app route and the admin page's "pos-lookup" tab both mount it.
  -
  - @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-006
  -->
<template>
	<div class="pos-customer-settings">
		<section class="pos-customer-settings__card">
			<h2 class="pos-customer-settings__title">
				{{ t('pipelinq', 'Customer lookup at the till') }}
			</h2>
			<p class="pos-customer-settings__lead">
				{{ t('pipelinq', 'Configure how cashiers search for customers, how much history is shown at checkout, and how marketing consent is synced to the linked contact.') }}
			</p>

			<NcLoadingIcon v-if="loading" :size="24" />

			<template v-else>
				<fieldset class="pos-customer-settings__group">
					<legend>{{ t('pipelinq', 'Search fields') }}</legend>
					<NcCheckboxRadioSwitch
						v-for="field in availableFields"
						:key="field.id"
						:modelValue="form.customerSearchFields.includes(field.id)"
						@update:modelValue="toggleField(field.id, $event)">
						{{ field.label }}
					</NcCheckboxRadioSwitch>
					<p class="pos-customer-settings__hint">
						{{ t('pipelinq', 'At least one search field is required.') }}
					</p>
				</fieldset>

				<NcSelect
					:modelValue="selectedDepth"
					:options="depthOptions"
					:inputLabel="t('pipelinq', 'Purchase history depth')"
					label="label"
					:clearable="false"
					@update:modelValue="form.customerHistoryDepth = $event ? $event.id : form.customerHistoryDepth" />

				<fieldset class="pos-customer-settings__group">
					<legend>{{ t('pipelinq', 'Pipelinq integration') }}</legend>
					<NcCheckboxRadioSwitch v-model="form.enablePipelinqSync">
						{{ t('pipelinq', 'Automatically sync marketing consent to the linked contact.') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="form.requireCustomerForOnAccount">
						{{ t('pipelinq', 'Require a customer for on-account transactions.') }}
					</NcCheckboxRadioSwitch>
				</fieldset>

				<div class="pos-customer-settings__actions">
					<p
						v-if="statusMessage"
						class="pos-customer-settings__status"
						:class="{ 'pos-customer-settings__status--error': statusError }"
						role="status">
						{{ statusMessage }}
					</p>
					<NcButton
						variant="primary"
						:disabled="saving"
						data-testid="customer-settings-save"
						@click="save">
						{{ t('pipelinq', 'Save') }}
					</NcButton>
				</div>
			</template>
		</section>

		<aside class="pos-customer-settings__card pos-customer-settings__aside">
			<h3>{{ t('pipelinq', 'Where cashiers see this') }}</h3>
			<p>{{ t('pipelinq', 'The customer card on the till: searching, the purchase history and the consent question.') }}</p>
			<a :href="tillUrl" class="pos-customer-settings__link">{{ t('pipelinq', 'Open the till') }}</a>
		</aside>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
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
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcSelect,
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

		/**
		 * The history depths as select options ("Last 10 transactions").
		 *
		 * @return {Array<{id: number, label: string}>} The options.
		 */
		depthOptions() {
			return ALL_DEPTHS.map((n) => ({ id: n, label: t('pipelinq', 'Last {n} transactions', { n }) }))
		},

		/**
		 * The chosen depth as a select option.
		 *
		 * @return {{id: number, label: string}} The option.
		 */
		selectedDepth() {
			return this.depthOptions.find((o) => o.id === this.form.customerHistoryDepth) || this.depthOptions[0]
		},

		/**
		 * The till, opened from the side card.
		 *
		 * @return {string} The URL.
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
.pos-customer-settings {
	display: grid;
	grid-template-columns: minmax(0, 1fr) 300px;
	gap: 20px;
	align-items: start;
}

.pos-customer-settings__card {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 20px 24px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.pos-customer-settings__title,
.pos-customer-settings__aside h3 {
	margin: 0;
	font-size: 17px;
	font-weight: 700;
}

.pos-customer-settings__lead,
.pos-customer-settings__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.pos-customer-settings__group {
	border: 0;
	margin: 0;
	padding: 0;
}

.pos-customer-settings__group legend {
	font-weight: 600;
	margin-bottom: 4px;
}

.pos-customer-settings__actions {
	display: flex;
	align-items: center;
	justify-content: flex-end;
	gap: 16px;
}

.pos-customer-settings__aside p {
	margin: 0;
}

.pos-customer-settings__link {
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

@media (max-width: 1100px) {
	.pos-customer-settings {
		grid-template-columns: minmax(0, 1fr);
	}
}
</style>
