<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - MessagingSettings is the admin page for the outbound WhatsApp/SMS
  - messaging feature (outbound-messaging-provider-wiring). It manages the
  - channelProvider / messageSendBudget / messageTemplate OpenRegister
  - objects directly via createObjectStore (there is no bespoke REST CRUD for
  - these — only POST /api/messaging/providers/{id}/test is a custom
  - endpoint) and exposes the inbound webhook URL each provider console needs.
  -
  - Provider rows deliberately have NO credential field: vendor secrets live
  - on the OpenConnector source addressed by sourceId (the MessageDispatch
  - leaf resolves credentials server-side from that source), never on this
  - object — see channelProvider.credentials in the register schema, which is
  - kept only for backward compatibility and is never written here.
  -
  - @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
  -->
<template>
	<div class="messaging-settings">
		<NcSettingsSection
			class="cn-settings-section--wide"
			:name="t('pipelinq', 'Incoming messages')"
			:description="
				t(
					'pipelinq',
					'SMS and WhatsApp providers call Pipelinq without a Nextcloud login. After the signature checks out, Pipelinq saves what they send as the account below.',
				)
			">
			<ServiceAccountPicker
				:url="serviceAccountUrl"
				inputId="messaging-service-account"
				:legend="t('pipelinq', 'SMS and WhatsApp service account')"
				:help="
					(group) =>
						t(
							'pipelinq',
							'Incoming messages, conversations, STOP replies and messages from unknown numbers are saved as this account. It joins the group {group}, which may create and change messages, conversations and contact moments.',
							{ group },
						)
				"
				:consequence="
					t(
						'pipelinq',
						'Until you choose one, incoming SMS and WhatsApp messages are not saved, and a STOP reply is not recorded.',
					)
				"
				:savedMessage="
					(user) =>
						t('pipelinq', 'Incoming messages are now saved as {user}.', {
							user,
						})
				"
				:loadError="
					t(
						'pipelinq',
						'Could not load the SMS and WhatsApp service account.',
					)
				"
				:saveError="
					t(
						'pipelinq',
						'Could not save the SMS and WhatsApp service account.',
					)
				"
				defaultGroup="pipelinq-messaging-service" />

			<hr class="messaging-settings__hair">
			<div class="messaging-settings__head">
				<div class="messaging-settings__head-text">
					<h3>{{ t('pipelinq', 'Providers') }}</h3>
					<p>
						{{
							t(
								'pipelinq',
								'WhatsApp and SMS senders. Credentials live on the OpenConnector source (sourceId) — never here.',
							)
						}}
					</p>
				</div>
				<span class="messaging-settings__head-actions">
					<NcButton :disabled="loadingProviders" @click="fetchProviders">
						<template #icon>
							<Refresh :size="20" />
						</template>
						{{ t('pipelinq', 'Refresh') }}
					</NcButton>
					<NcButton variant="primary" @click="startNewProvider">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('pipelinq', 'Add provider') }}
					</NcButton>
				</span>
			</div>

			<NcLoadingIcon v-if="loadingProviders" :size="28" />

			<NcEmptyContent
				v-else-if="providers.length === 0"
				:description="
					t('pipelinq', 'No channel providers configured yet.')
				" />

			<table
				v-else
				class="messaging-settings__table"
				data-testid="messaging-providers">
				<thead>
					<tr>
						<th scope="col">{{ t('pipelinq', 'Name') }}</th>
						<th scope="col">{{ t('pipelinq', 'Kind') }}</th>
						<th scope="col">{{ t('pipelinq', 'Vendor') }}</th>
						<th scope="col">{{ t('pipelinq', 'Source') }}</th>
						<th scope="col">{{ t('pipelinq', 'Phone / account') }}</th>
						<th scope="col">{{ t('pipelinq', 'Priority') }}</th>
						<th scope="col">{{ t('pipelinq', 'Status') }}</th>
						<th scope="col">{{ t('pipelinq', 'Webhook URL') }}</th>
						<th scope="col">{{ t('pipelinq', 'Actions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="provider in providers"
						:key="provider.id"
						data-testid="messaging-provider">
						<td><strong>{{ provider.displayName }}</strong></td>
						<td>{{ kindLabel(provider.kind) }}</td>
						<td>{{ vendorLabel(provider.vendor) }}</td>
						<td><code>{{ provider.sourceId || '—' }}</code></td>
						<td class="messaging-settings__nowrap">{{ provider.phoneNumber || '—' }}</td>
						<td>{{ provider.priority ?? 10 }}</td>
						<td>
							<span class="messaging-settings__status">
								<span
									class="cn-status-badge"
									:class="provider.active ? 'cn-status-badge--success' : 'cn-status-badge--default'">
									{{
										provider.active
											? t('pipelinq', 'Active')
											: t('pipelinq', 'Inactive')
									}}
								</span>
								<span
									v-if="provider.sandbox"
									class="cn-status-badge cn-status-badge--warning">
									{{ t('pipelinq', 'Sandbox') }}
								</span>
								<span
									v-if="testResults[provider.id]"
									class="messaging-settings__test-result"
									:class="testResults[provider.id].reachable ? 'messaging-settings__test-result--ok' : 'messaging-settings__test-result--error'">
									{{ testResultLabel(testResults[provider.id]) }}
								</span>
							</span>
						</td>
						<td>
							<code
								class="messaging-settings__webhook-url"
								:title="webhookUrl(provider)">{{ shortWebhookUrl(provider) }}</code>
						</td>
						<td class="messaging-settings__row-actions">
							<NcButton
								:aria-label="t('pipelinq', 'Copy webhook URL')"
								:title="t('pipelinq', 'Copy webhook URL')"
								@click="copyWebhookUrl(provider)">
								<template #icon>
									<ContentCopy :size="18" />
								</template>
							</NcButton>
							<NcButton
								:disabled="testingProviderId === provider.id"
								@click="testProviderConnection(provider)">
								{{
									testingProviderId === provider.id
										? t('pipelinq', 'Testing…')
										: t('pipelinq', 'Test connection')
								}}
							</NcButton>
							<NcButton variant="tertiary" @click="editProvider(provider)">
								{{ t('pipelinq', 'Edit') }}
							</NcButton>
							<NcButton
								variant="tertiary"
								class="messaging-settings__delete"
								@click="deleteProvider(provider)">
								{{ t('pipelinq', 'Delete') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>

			<div v-if="showProviderForm" class="messaging-settings__form-panel">
				<h3>
					{{
						editingProvider
							? t('pipelinq', 'Edit provider')
							: t('pipelinq', 'Add provider')
					}}
				</h3>
				<NcTextField
					v-model="providerForm.displayName"
					:label="t('pipelinq', 'Display name')"
					:placeholder="t('pipelinq', 'e.g. Twilio SMS (NL)')" />
				<NcSelect
					v-model="providerForm.kind"
					:options="kindOptions"
					:inputLabel="t('pipelinq', 'Kind')"
					label="label"
					:reduce="(o) => o.value" />
				<NcSelect
					v-model="providerForm.vendor"
					:options="vendorOptions"
					:inputLabel="t('pipelinq', 'Vendor')"
					label="label"
					:reduce="(o) => o.value" />
				<NcTextField
					v-model="providerForm.sourceId"
					:label="t('pipelinq', 'OpenConnector source ID')"
					:placeholder="t('pipelinq', 'e.g. twilio-sms')" />
				<p class="messaging-settings__hint">
					{{
						t(
							'pipelinq',
							'Credentials live on the OpenConnector source above, not on this provider row. Configure the vendor API key/secret on that source.',
						)
					}}
				</p>
				<NcTextField
					v-model="providerForm.phoneNumber"
					:label="t('pipelinq', 'Phone number / account ID')"
					placeholder="+31600000000" />
				<NcTextField
					:modelValue="providerForm.webhookSecret"
					:label="t('pipelinq', 'Webhook secret')"
					type="password"
					@update:modelValue="(v) => (providerForm.webhookSecret = v)" />
				<NcTextField
					v-model.number="providerForm.priority"
					:label="t('pipelinq', 'Priority (lower wins failover)')"
					type="number"
					min="0" />
				<NcCheckboxRadioSwitch v-model="providerForm.active" type="switch">
					{{ t('pipelinq', 'Active (participates in send routing)') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="providerForm.sandbox" type="switch">
					{{ t('pipelinq', 'Sandbox / test account') }}
				</NcCheckboxRadioSwitch>
				<div class="messaging-settings__form-actions">
					<NcButton @click="cancelProviderForm">
						{{ t('pipelinq', 'Cancel') }}
					</NcButton>
					<NcButton
						variant="primary"
						:disabled="
							!providerForm.displayName
							|| !providerForm.kind
							|| !providerForm.vendor
							|| savingProvider
						"
						@click="saveProvider">
						{{
							savingProvider
								? t('pipelinq', 'Saving…')
								: t('pipelinq', 'Save')
						}}
					</NcButton>
				</div>
			</div>

			<hr class="messaging-settings__hair">
			<div class="messaging-settings__head">
				<div class="messaging-settings__head-text">
					<h3>{{ t('pipelinq', 'Send budgets') }}</h3>
					<p>
						{{
							t(
								'pipelinq',
								'Per-provider send caps. Hard-stop refuses further sends past the cap; alert-only just notifies once per period.',
							)
						}}
					</p>
				</div>
				<span class="messaging-settings__head-actions">
					<NcButton :disabled="loadingBudgets" @click="fetchBudgets">
						<template #icon>
							<Refresh :size="20" />
						</template>
						{{ t('pipelinq', 'Refresh') }}
					</NcButton>
					<NcButton
						variant="primary"
						:disabled="providers.length === 0"
						@click="startNewBudget">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('pipelinq', 'Add budget') }}
					</NcButton>
				</span>
			</div>

			<NcLoadingIcon v-if="loadingBudgets" :size="28" />

			<NcEmptyContent
				v-else-if="budgets.length === 0"
				:description="t('pipelinq', 'No send budgets configured yet.')" />

			<table
				v-else
				class="messaging-settings__table"
				data-testid="messaging-budgets">
				<thead>
					<tr>
						<th scope="col">{{ t('pipelinq', 'Provider') }}</th>
						<th scope="col">{{ t('pipelinq', 'Period') }}</th>
						<th scope="col">{{ t('pipelinq', 'Max messages') }}</th>
						<th scope="col">{{ t('pipelinq', 'Max cost (EUR)') }}</th>
						<th scope="col">{{ t('pipelinq', 'Alert threshold (0-1)') }}</th>
						<th scope="col">{{ t('pipelinq', 'Hard stop') }}</th>
						<th scope="col">{{ t('pipelinq', 'Used this period') }}</th>
						<th scope="col">{{ t('pipelinq', 'Actions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="budget in budgets"
						:key="budget.id"
						data-testid="messaging-budget">
						<td><strong>{{ providerDisplayName(budget.providerId) }}</strong></td>
						<td class="messaging-settings__period">
							<NcSelect
								v-model="budget.period"
								:options="periodOptions"
								:ariaLabelCombobox="t('pipelinq', 'Period')"
								:clearable="false"
								label="label"
								:reduce="(o) => o.value" />
						</td>
						<td>
							<input
								v-model.number="budget.maxMessages"
								class="messaging-settings__cell-input"
								type="number"
								min="0"
								:aria-label="t('pipelinq', 'Max messages')">
						</td>
						<td>
							<input
								v-model.number="budget.maxCostEur"
								class="messaging-settings__cell-input"
								type="number"
								min="0"
								step="0.01"
								:aria-label="t('pipelinq', 'Max cost (EUR)')">
						</td>
						<td>
							<input
								v-model.number="budget.alertThresholdPct"
								class="messaging-settings__cell-input messaging-settings__cell-input--short"
								type="number"
								min="0"
								max="1"
								step="0.05"
								:aria-label="t('pipelinq', 'Alert threshold (0-1)')">
						</td>
						<td>
							<NcCheckboxRadioSwitch
								v-model="budget.hardStop"
								type="switch"
								:aria-label="t('pipelinq', 'Hard stop')" />
						</td>
						<td>
							<span class="messaging-settings__usage">
								<span>
									{{ budget.currentPeriodMessages || 0 }}
									{{ t('pipelinq', 'msgs') }} / €{{
										(budget.currentPeriodCostEur || 0).toFixed(2)
									}}
								</span>
								<span class="messaging-settings__bar" aria-hidden="true">
									<span
										class="messaging-settings__bar-fill"
										:class="{ 'messaging-settings__bar-fill--alert': budgetUsageShare(budget) >= (budget.alertThresholdPct || 1) }"
										:style="{ width: Math.round(budgetUsageShare(budget) * 100) + '%' }" />
								</span>
							</span>
						</td>
						<td class="messaging-settings__row-actions">
							<NcButton
								variant="primary"
								:disabled="savingBudgetId === budget.id"
								@click="saveBudget(budget)">
								{{
									savingBudgetId === budget.id
										? t('pipelinq', 'Saving…')
										: t('pipelinq', 'Save')
								}}
							</NcButton>
							<NcButton
								variant="tertiary"
								class="messaging-settings__delete"
								@click="deleteBudget(budget)">
								{{ t('pipelinq', 'Delete') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>

			<div v-if="showBudgetForm" class="messaging-settings__form-panel">
				<h3>{{ t('pipelinq', 'Add budget') }}</h3>
				<NcTextField
					v-model="budgetForm.tenantId"
					:label="t('pipelinq', 'Tenant ID')"
					:placeholder="
						t('pipelinq', 'Defaults to this Nextcloud instance')
					" />
				<NcSelect
					v-model="budgetForm.providerId"
					:options="providerOptions"
					:inputLabel="t('pipelinq', 'Provider')"
					label="label"
					:reduce="(o) => o.value" />
				<NcSelect
					v-model="budgetForm.period"
					:options="periodOptions"
					:inputLabel="t('pipelinq', 'Period')"
					label="label"
					:reduce="(o) => o.value" />
				<NcTextField
					v-model.number="budgetForm.maxMessages"
					:label="t('pipelinq', 'Max messages (0 = no cap)')"
					type="number"
					min="0" />
				<NcTextField
					v-model.number="budgetForm.maxCostEur"
					:label="t('pipelinq', 'Max cost EUR (0 = no cap)')"
					type="number"
					min="0"
					step="0.01" />
				<NcCheckboxRadioSwitch v-model="budgetForm.hardStop" type="switch">
					{{ t('pipelinq', 'Hard stop (refuse sends past the cap)') }}
				</NcCheckboxRadioSwitch>
				<div class="messaging-settings__form-actions">
					<NcButton @click="cancelBudgetForm">
						{{ t('pipelinq', 'Cancel') }}
					</NcButton>
					<NcButton
						variant="primary"
						:disabled="!budgetForm.providerId || creatingBudget"
						@click="createBudget">
						{{
							creatingBudget
								? t('pipelinq', 'Saving…')
								: t('pipelinq', 'Save')
						}}
					</NcButton>
				</div>
			</div>

			<hr class="messaging-settings__hair">
			<div class="messaging-settings__head">
				<div class="messaging-settings__head-text">
					<h3>{{ t('pipelinq', 'Templates') }}</h3>
					<p>
						{{
							t(
								'pipelinq',
								'Local mirror of provider-side WhatsApp HSM templates. Approval status and sync time come from the provider; templates are synced, not authored here.',
							)
						}}
					</p>
				</div>
				<span class="messaging-settings__head-actions">
					<NcButton :disabled="loadingTemplates" @click="fetchTemplates">
						<template #icon>
							<Refresh :size="20" />
						</template>
						{{ t('pipelinq', 'Refresh') }}
					</NcButton>
				</span>
			</div>

			<NcLoadingIcon v-if="loadingTemplates" :size="28" />

			<NcEmptyContent
				v-else-if="templates.length === 0"
				:description="t('pipelinq', 'No templates synced yet.')" />

			<table
				v-else
				class="messaging-settings__table"
				data-testid="messaging-templates">
				<thead>
					<tr>
						<th scope="col">{{ t('pipelinq', 'Provider') }}</th>
						<th scope="col">{{ t('pipelinq', 'External ID') }}</th>
						<th scope="col">{{ t('pipelinq', 'Language') }}</th>
						<th scope="col">{{ t('pipelinq', 'Status') }}</th>
						<th scope="col">{{ t('pipelinq', 'Last synced') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="template in templates"
						:key="template.id"
						data-testid="messaging-template">
						<td>{{ providerDisplayName(template.providerId) }}</td>
						<td><code>{{ template.externalId }}</code></td>
						<td>{{ template.language }}</td>
						<td>
							<span class="cn-status-badge" :class="templateStatusClass(template.status)">
								{{ templateStatusLabel(template.status) }}
							</span>
						</td>
						<td class="messaging-settings__muted">{{ formatDate(template.lastSyncedAt) }}</td>
					</tr>
				</tbody>
			</table>
		</NcSettingsSection>
		<ConfirmDialog
			v-if="pendingDeleteProvider"
			:name="t('pipelinq', 'Delete provider')"
			:message="deleteProviderMessage"
			:confirmLabel="t('pipelinq', 'Delete')"
			@confirm="performDeleteProvider"
			@cancel="pendingDeleteProvider = null" />
		<ConfirmDialog
			v-if="pendingDeleteBudget"
			:name="t('pipelinq', 'Delete send budget')"
			:message="t('pipelinq', 'Delete this send budget?')"
			:confirmLabel="t('pipelinq', 'Delete')"
			@confirm="performDeleteBudget"
			@cancel="pendingDeleteBudget = null" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcEmptyContent,
	NcLoadingIcon,
	NcSelect,
	NcSettingsSection,
	NcTextField,
} from '@nextcloud/vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import ServiceAccountPicker from '../../components/admin/ServiceAccountPicker.vue'
import ConfirmDialog from '../../dialogs/ConfirmDialog.vue'
import { useObjectStore } from '../../store/modules/object.js'

const KIND_LABELS = {
	'whatsapp-cloud-api': 'WhatsApp Cloud API',
	'whatsapp-bsp': 'WhatsApp (BSP)',
	sms: 'SMS',
}

const VENDOR_LABELS = {
	meta: 'Meta',
	twilio: 'Twilio',
	messagebird: 'MessageBird',
	'cm-com': 'CM.com',
	'360dialog': '360dialog',
	vonage: 'Vonage',
}

const TEMPLATE_STATUS_CLASSES = {
	approved: 'cn-status-badge--success',
	pending: 'cn-status-badge--warning',
	rejected: 'cn-status-badge--error',
	disabled: 'cn-status-badge--default',
}

/**
 *
 */
function DEFAULT_PROVIDER_FORM() {
	return {
		id: null,
		displayName: '',
		kind: 'sms',
		vendor: 'twilio',
		sourceId: '',
		phoneNumber: '',
		webhookSecret: '',
		priority: 10,
		active: true,
		sandbox: false,
	}
}

/**
 *
 */
function DEFAULT_BUDGET_FORM() {
	return {
		tenantId: '',
		providerId: null,
		period: 'monthly',
		maxMessages: 0,
		maxCostEur: 0,
		hardStop: false,
	}
}

export default {
	name: 'MessagingSettings',
	components: {
		ConfirmDialog,
		NcButton,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcSettingsSection,
		NcTextField,
		ContentCopy,
		Plus,
		Refresh,
		ServiceAccountPicker,
	},

	data() {
		return {
			providers: [],
			budgets: [],
			templates: [],
			pendingDeleteProvider: null,
			pendingDeleteBudget: null,
			loadingProviders: false,
			loadingBudgets: false,
			loadingTemplates: false,
			showProviderForm: false,
			editingProvider: null,
			providerForm: DEFAULT_PROVIDER_FORM(),
			savingProvider: false,
			showBudgetForm: false,
			budgetForm: DEFAULT_BUDGET_FORM(),
			creatingBudget: false,
			savingBudgetId: null,
			testingProviderId: null,
			testResults: {},
		}
	},

	computed: {
		/**
		 * The admin endpoint for the SMS and WhatsApp service account.
		 *
		 * @return {string} The url.
		 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
		 */
		serviceAccountUrl() {
			return generateUrl('/apps/pipelinq/api/messaging/service-account')
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		objectStore() {
			return useObjectStore()
		},

		/**
		 * Built here rather than inline in the template so the t() key stays
		 * byte-identical to the one the old window.confirm used. Escaping the
		 * quotes as &quot; in the attribute would have minted a NEW key and
		 * orphaned every existing translation of this string.
		 *
		 * @return {string} The confirmation message.
		 *
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		deleteProviderMessage() {
			if (!this.pendingDeleteProvider) {
				return ''
			}
			return t('pipelinq', 'Delete provider "{name}"?', {
				name: this.pendingDeleteProvider.displayName,
			})
		},

		kindOptions() {
			return [
				{
					value: 'whatsapp-cloud-api',
					label: t('pipelinq', 'WhatsApp Cloud API'),
				},
				{ value: 'whatsapp-bsp', label: t('pipelinq', 'WhatsApp (BSP)') },
				{ value: 'sms', label: t('pipelinq', 'SMS') },
			]
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		vendorOptions() {
			return [
				{ value: 'meta', label: t('pipelinq', 'Meta') },
				{ value: 'twilio', label: t('pipelinq', 'Twilio') },
				{ value: 'messagebird', label: t('pipelinq', 'MessageBird') },
				{ value: 'cm-com', label: t('pipelinq', 'CM.com') },
				{ value: '360dialog', label: t('pipelinq', '360dialog') },
				{ value: 'vonage', label: t('pipelinq', 'Vonage') },
			]
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		periodOptions() {
			return [
				{ value: 'daily', label: t('pipelinq', 'Daily') },
				{ value: 'weekly', label: t('pipelinq', 'Weekly') },
				{ value: 'monthly', label: t('pipelinq', 'Monthly') },
			]
		},

		providerOptions() {
			return this.providers.map((p) => ({ value: p.id, label: p.displayName }))
		},
	},

	/**
	 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
	 */
	async mounted() {
		await Promise.all([
			this.fetchProviders(),
			this.fetchBudgets(),
			this.fetchTemplates(),
		])
	},

	methods: {
		/**
		 * @param {string} kind The provider kind.
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		kindLabel(kind) {
			return KIND_LABELS[kind] || kind || '—'
		},

		vendorLabel(vendor) {
			return VENDOR_LABELS[vendor] || vendor || '—'
		},

		templateStatusLabel(status) {
			const labels = {
				approved: t('pipelinq', 'Approved'),
				pending: t('pipelinq', 'Pending'),
				rejected: t('pipelinq', 'Rejected'),
				disabled: t('pipelinq', 'Disabled'),
			}
			return labels[status] || status || '—'
		},

		/**
		 * @param {string} status The template status.
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		templateStatusClass(status) {
			return (
				TEMPLATE_STATUS_CLASSES[status] || 'cn-status-badge--default'
			)
		},

		/**
		 * @param {string} providerId Identifier of the messaging provider.
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		providerDisplayName(providerId) {
			const provider = this.providers.find((p) => p.id === providerId)
			return provider ? provider.displayName : providerId || '—'
		},

		/**
		 * @param {string} value The stored date.
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		formatDate(value) {
			if (!value) {
				return '—'
			}
			const parsed = new Date(value)
			if (Number.isNaN(parsed.getTime())) {
				return value
			}
			return parsed.toLocaleString()
		},

		webhookUrl(provider) {
			const channel = provider.kind === 'sms' ? 'sms' : 'whatsapp'
			const path = generateUrl(
				'/apps/pipelinq/api/messaging-webhooks/{channel}/{id}',
				{
					channel,
					id: provider.id,
				},
			)
			return window.location.origin + path
		},

		/**
		 * The webhook URL as the table shows it: its last two path parts after
		 * an ellipsis. The full URL is the cell's title and what Copy copies.
		 *
		 * @param {object} provider The provider.
		 * @return {string} The shortened URL.
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		shortWebhookUrl(provider) {
			const parts = this.webhookUrl(provider).split('/').filter(Boolean)
			return '…/' + parts.slice(-2).join('/')
		},

		/**
		 * The words for a connection test's result.
		 *
		 * @param {object} result The test result.
		 * @return {string} Reachable, reachable in mock mode, or degraded with its cause.
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		testResultLabel(result) {
			if (result.reachable) {
				return result.mock
					? t('pipelinq', 'Reachable (mock mode)')
					: t('pipelinq', 'Reachable')
			}
			return t('pipelinq', 'Degraded: {cause}', {
				cause: result.cause || t('pipelinq', 'unknown'),
			})
		},

		/**
		 * How much of a budget's message cap this period used, 0 to 1.
		 *
		 * @param {object} budget The send budget.
		 * @return {number} The share; 0 without a cap.
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		budgetUsageShare(budget) {
			const cap = Number(budget.maxMessages) || 0
			if (cap <= 0) {
				return 0
			}
			return Math.min(1, (Number(budget.currentPeriodMessages) || 0) / cap)
		},

		/**
		 * @param {object} provider The provider whose webhook URL to copy.
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		async copyWebhookUrl(provider) {
			try {
				await navigator.clipboard.writeText(this.webhookUrl(provider))
				showSuccess(t('pipelinq', 'Webhook URL copied to clipboard.'))
			} catch {
				showError(t('pipelinq', 'Could not copy the webhook URL.'))
			}
		},

		/**
		 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-001
		 */
		async fetchProviders() {
			this.loadingProviders = true
			try {
				this.providers =
					(await this.objectStore.fetchCollection('channelProvider', {
						_limit: 200,
					})) || []
			} catch {
				this.providers = []
				showError(t('pipelinq', 'Failed to load channel providers.'))
			} finally {
				this.loadingProviders = false
			}
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		startNewProvider() {
			this.editingProvider = null
			this.providerForm = DEFAULT_PROVIDER_FORM()
			this.showProviderForm = true
		},

		/**
		 * @param {object} provider The provider to edit.
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		editProvider(provider) {
			this.editingProvider = provider
			this.providerForm = { ...DEFAULT_PROVIDER_FORM(), ...provider }
			this.showProviderForm = true
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		cancelProviderForm() {
			this.showProviderForm = false
			this.editingProvider = null
			this.providerForm = DEFAULT_PROVIDER_FORM()
		},

		/**
		 * Create or update a channel provider.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		async saveProvider() {
			this.savingProvider = true
			try {
				await this.objectStore.saveObject('channelProvider', {
					...this.providerForm,
				})
				showSuccess(t('pipelinq', 'Provider saved.'))
				this.cancelProviderForm()
				await this.fetchProviders()
			} catch (e) {
				showError(e.message || t('pipelinq', 'Failed to save the provider.'))
			} finally {
				this.savingProvider = false
			}
		},

		/**
		 * Open the delete confirmation for a provider.
		 *
		 * @param {object} provider The provider to delete.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		deleteProvider(provider) {
			this.pendingDeleteProvider = provider
		},

		/**
		 * Delete the pending provider once the dialog confirms.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		async performDeleteProvider() {
			const provider = this.pendingDeleteProvider
			this.pendingDeleteProvider = null
			if (!provider) {
				return
			}
			try {
				await this.objectStore.deleteObject('channelProvider', provider.id)
				showSuccess(t('pipelinq', 'Provider deleted.'))
				await this.fetchProviders()
			} catch (e) {
				showError(
					e.message || t('pipelinq', 'Failed to delete the provider.'),
				)
			}
		},

		/**
		 * @param {object} provider The provider to probe.
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		async testProviderConnection(provider) {
			this.testingProviderId = provider.id
			try {
				const { data } = await axios.post(
					generateUrl('/apps/pipelinq/api/messaging/providers/{id}/test', {
						id: provider.id,
					}),
				)
				this.testResults[provider.id] = data
			} catch (e) {
				const data = (e.response && e.response.data) || {
					reachable: false,
					cause: 'request-failed',
				}
				this.testResults[provider.id] = data
			} finally {
				this.testingProviderId = null
			}
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		async fetchBudgets() {
			this.loadingBudgets = true
			try {
				this.budgets =
					(await this.objectStore.fetchCollection('messageSendBudget', {
						_limit: 200,
					})) || []
			} catch {
				this.budgets = []
				showError(t('pipelinq', 'Failed to load send budgets.'))
			} finally {
				this.loadingBudgets = false
			}
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		startNewBudget() {
			this.budgetForm = DEFAULT_BUDGET_FORM()
			this.showBudgetForm = true
		},

		/**
		 * Close the send-budget form and reset it.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		cancelBudgetForm() {
			this.showBudgetForm = false
			this.budgetForm = DEFAULT_BUDGET_FORM()
		},

		/**
		 * Create a message send budget.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		async createBudget() {
			this.creatingBudget = true
			try {
				await this.objectStore.saveObject('messageSendBudget', {
					...this.budgetForm,
				})
				showSuccess(t('pipelinq', 'Budget created.'))
				this.cancelBudgetForm()
				await this.fetchBudgets()
			} catch (e) {
				showError(e.message || t('pipelinq', 'Failed to create the budget.'))
			} finally {
				this.creatingBudget = false
			}
		},

		/**
		 * @param {object} budget The budget to persist.
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		async saveBudget(budget) {
			this.savingBudgetId = budget.id
			try {
				await this.objectStore.saveObject('messageSendBudget', { ...budget })
				showSuccess(t('pipelinq', 'Budget saved.'))
			} catch (e) {
				showError(e.message || t('pipelinq', 'Failed to save the budget.'))
			} finally {
				this.savingBudgetId = null
			}
		},

		/**
		 * Open the delete confirmation for a send budget.
		 *
		 * @param {object} budget The budget to delete.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		deleteBudget(budget) {
			this.pendingDeleteBudget = budget
		},

		/**
		 * Delete the pending send budget once the dialog confirms.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		async performDeleteBudget() {
			const budget = this.pendingDeleteBudget
			this.pendingDeleteBudget = null
			if (!budget) {
				return
			}
			try {
				await this.objectStore.deleteObject('messageSendBudget', budget.id)
				showSuccess(t('pipelinq', 'Budget deleted.'))
				await this.fetchBudgets()
			} catch (e) {
				showError(e.message || t('pipelinq', 'Failed to delete the budget.'))
			}
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
		 */
		async fetchTemplates() {
			this.loadingTemplates = true
			try {
				this.templates =
					(await this.objectStore.fetchCollection('messageTemplate', {
						_limit: 200,
					})) || []
			} catch {
				this.templates = []
				showError(t('pipelinq', 'Failed to load templates.'))
			} finally {
				this.loadingTemplates = false
			}
		},
	},
}
</script>

<style scoped>
.messaging-settings {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.messaging-settings__hair {
	width: 100%;
	margin: 6px 0;
	border: 0;
	border-top: 1px solid var(--color-border);
}

/* A sub-head inside the card: a 17px title and its line, the actions at the
   end of the row, as the board draws Providers, Send budgets and Templates. */
.messaging-settings__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 12px 16px;
}

.messaging-settings__head-text {
	flex: 1 1 420px;
	min-width: 0;
}

.messaging-settings__head-text h3 {
	margin: 0;
	font-size: 17px;
	font-weight: 700;
}

.messaging-settings__head-text p {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}

.messaging-settings__head-actions {
	display: inline-flex;
	flex-wrap: wrap;
	gap: 8px;
}

.messaging-settings__table {
	width: 100%;
	border-collapse: collapse;
}

.messaging-settings__nowrap {
	white-space: nowrap;
}

.messaging-settings__muted {
	color: var(--color-text-maxcontrast);
}

.messaging-settings__status {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 4px;
}

.messaging-settings__test-result {
	font-size: 12px;
	font-weight: 600;
}

.messaging-settings__test-result--ok {
	color: var(--color-success-text);
}

.messaging-settings__test-result--error {
	color: var(--color-error-text);
}

.messaging-settings__webhook-url {
	white-space: nowrap;
	font-size: 12px;
}

.messaging-settings__row-actions {
	white-space: nowrap;
}

.messaging-settings__row-actions > * {
	display: inline-flex;
	vertical-align: middle;
}

.messaging-settings__row-actions > * + * {
	margin-inline-start: 4px;
}

.messaging-settings__delete {
	color: var(--color-error-text) !important;
}

.messaging-settings__period {
	min-width: 130px;
}

.messaging-settings__cell-input {
	width: 100px;
	height: 36px;
	min-height: 36px;
	margin: 0;
}

.messaging-settings__cell-input--short {
	width: 76px;
}

.messaging-settings__usage {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 150px;
}

.messaging-settings__bar {
	display: block;
	height: 6px;
	border-radius: 3px;
	background: var(--color-background-dark);
	overflow: hidden;
}

.messaging-settings__bar-fill {
	display: block;
	height: 100%;
	background: var(--color-success);
}

.messaging-settings__bar-fill--alert {
	background: var(--color-warning);
}

.messaging-settings__hint {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.messaging-settings__form-panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 560px;
	margin-top: 16px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.messaging-settings__form-panel h3 {
	margin: 0;
}

.messaging-settings__form-actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}
</style>
