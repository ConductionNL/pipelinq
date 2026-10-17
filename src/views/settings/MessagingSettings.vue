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
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('pipelinq', 'Providers')"
			:description="
				t(
					'pipelinq',
					'WhatsApp and SMS senders. Credentials live on the OpenConnector source (sourceId) — never here.',
				)
			">
			<div class="messaging-settings__toolbar">
				<NcButton :disabled="loadingProviders" @click="fetchProviders">
					{{ t('pipelinq', 'Refresh') }}
				</NcButton>
				<NcButton variant="primary" @click="startNewProvider">
					{{ t('pipelinq', 'Add provider') }}
				</NcButton>
			</div>

			<NcLoadingIcon v-if="loadingProviders" :size="28" />

			<NcEmptyContent
				v-else-if="providers.length === 0"
				:description="
					t('pipelinq', 'No channel providers configured yet.')
				" />

			<ul v-else class="messaging-settings__records">
				<li
					v-for="provider in providers"
					:key="provider.id"
					class="messaging-settings__record"
					data-testid="messaging-provider">
					<div class="messaging-settings__record-head">
						<h3 class="messaging-settings__record-name">
							{{ provider.displayName }}
						</h3>
						<span
							class="messaging-settings__badge"
							:class="
								provider.active
									? 'messaging-settings__badge--on'
									: 'messaging-settings__badge--off'
							">
							{{
								provider.active
									? t('pipelinq', 'Active')
									: t('pipelinq', 'Inactive')
							}}
						</span>
						<span
							v-if="provider.sandbox"
							class="messaging-settings__badge messaging-settings__badge--sandbox">
							{{ t('pipelinq', 'Sandbox') }}
						</span>
					</div>
					<dl class="messaging-settings__facts">
						<div>
							<dt>{{ t('pipelinq', 'Kind') }}</dt>
							<dd>{{ kindLabel(provider.kind) }}</dd>
						</div>
						<div>
							<dt>{{ t('pipelinq', 'Vendor') }}</dt>
							<dd>{{ vendorLabel(provider.vendor) }}</dd>
						</div>
						<div>
							<dt>{{ t('pipelinq', 'Source') }}</dt>
							<dd>
								<code>{{ provider.sourceId || '—' }}</code>
							</dd>
						</div>
						<div>
							<dt>{{ t('pipelinq', 'Phone / account') }}</dt>
							<dd>{{ provider.phoneNumber || '—' }}</dd>
						</div>
						<div>
							<dt>{{ t('pipelinq', 'Priority') }}</dt>
							<dd>{{ provider.priority ?? 10 }}</dd>
						</div>
						<div class="messaging-settings__fact--wide">
							<dt>{{ t('pipelinq', 'Webhook URL') }}</dt>
							<dd class="messaging-settings__webhook-cell">
								<code class="messaging-settings__webhook-url">{{
									/**
									 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
									 */
									webhookUrl(provider)
								}}</code>
								<NcButton
									variant="tertiary"
									:aria-label="t('pipelinq', 'Copy webhook URL')"
									@click="copyWebhookUrl(provider)">
									<template #icon>
										<ContentCopy :size="18" />
									</template>
								</NcButton>
							</dd>
						</div>
					</dl>
					<div class="messaging-settings__record-actions">
						<NcButton
							:disabled="testingProviderId === provider.id"
							@click="testProviderConnection(provider)">
							{{
								testingProviderId === provider.id
									? t('pipelinq', 'Testing…')
									: t('pipelinq', 'Test connection')
							}}
						</NcButton>
						<NcButton @click="editProvider(provider)">
							{{ t('pipelinq', 'Edit') }}
						</NcButton>
						<NcButton variant="error" @click="deleteProvider(provider)">
							{{ t('pipelinq', 'Delete') }}
						</NcButton>
						<span
							v-if="testResults[provider.id]"
							class="messaging-settings__test-result">
							<span
								v-if="testResults[provider.id].reachable"
								class="messaging-settings__badge messaging-settings__badge--on">
								{{
									testResults[provider.id].mock
										? t('pipelinq', 'Reachable (mock mode)')
										: t('pipelinq', 'Reachable')
								}}
							</span>
							<span
								v-else
								class="messaging-settings__badge messaging-settings__badge--error">
								{{
									t('pipelinq', 'Degraded: {cause}', {
										cause:
											testResults[provider.id].cause
											|| t('pipelinq', 'unknown'),
									})
								}}
							</span>
						</span>
					</div>
				</li>
			</ul>

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
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('pipelinq', 'Send budgets')"
			:description="
				t(
					'pipelinq',
					'Per-provider send caps. Hard-stop refuses further sends past the cap; alert-only just notifies once per period.',
				)
			">
			<div class="messaging-settings__toolbar">
				<NcButton :disabled="loadingBudgets" @click="fetchBudgets">
					{{ t('pipelinq', 'Refresh') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="providers.length === 0"
					@click="startNewBudget">
					{{ t('pipelinq', 'Add budget') }}
				</NcButton>
			</div>

			<NcLoadingIcon v-if="loadingBudgets" :size="28" />

			<NcEmptyContent
				v-else-if="budgets.length === 0"
				:description="t('pipelinq', 'No send budgets configured yet.')" />

			<ul v-else class="messaging-settings__records">
				<li
					v-for="budget in budgets"
					:key="budget.id"
					class="messaging-settings__record"
					data-testid="messaging-budget">
					<div class="messaging-settings__record-head">
						<h3 class="messaging-settings__record-name">
							{{ providerDisplayName(budget.providerId) }}
						</h3>
					</div>
					<dl class="messaging-settings__facts">
						<div>
							<dt>{{ t('pipelinq', 'Used this period') }}</dt>
							<dd>
								{{ budget.currentPeriodMessages || 0 }}
								{{ t('pipelinq', 'msgs') }} / €{{
									(budget.currentPeriodCostEur || 0).toFixed(2)
								}}
							</dd>
						</div>
					</dl>
					<div class="messaging-settings__fields">
						<NcSelect
							v-model="budget.period"
							:options="periodOptions"
							:inputLabel="t('pipelinq', 'Period')"
							label="label"
							:reduce="(o) => o.value" />
						<NcTextField
							v-model.number="budget.maxMessages"
							:label="t('pipelinq', 'Max messages')"
							type="number"
							min="0" />
						<NcTextField
							v-model.number="budget.maxCostEur"
							:label="t('pipelinq', 'Max cost (EUR)')"
							type="number"
							min="0"
							step="0.01" />
						<NcTextField
							v-model.number="budget.alertThresholdPct"
							:label="t('pipelinq', 'Alert threshold (0-1)')"
							type="number"
							min="0"
							max="1"
							step="0.05" />
						<NcCheckboxRadioSwitch
							v-model="budget.hardStop"
							type="switch">
							{{ t('pipelinq', 'Hard stop') }}
						</NcCheckboxRadioSwitch>
					</div>
					<div class="messaging-settings__record-actions">
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
						<NcButton variant="error" @click="deleteBudget(budget)">
							{{ t('pipelinq', 'Delete') }}
						</NcButton>
					</div>
				</li>
			</ul>

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
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('pipelinq', 'Templates')"
			:description="
				t(
					'pipelinq',
					'Local mirror of provider-side WhatsApp HSM templates. Approval status and sync time come from the provider; templates are synced, not authored here.',
				)
			">
			<div class="messaging-settings__toolbar">
				<NcButton :disabled="loadingTemplates" @click="fetchTemplates">
					{{ t('pipelinq', 'Refresh') }}
				</NcButton>
			</div>

			<NcLoadingIcon v-if="loadingTemplates" :size="28" />

			<NcEmptyContent
				v-else-if="templates.length === 0"
				:description="t('pipelinq', 'No templates synced yet.')" />

			<ul v-else class="messaging-settings__records">
				<li
					v-for="template in templates"
					:key="template.id"
					class="messaging-settings__record"
					data-testid="messaging-template">
					<div class="messaging-settings__record-head">
						<h3 class="messaging-settings__record-name">
							<code>{{ template.externalId }}</code>
						</h3>
						<span
							class="messaging-settings__badge"
							:class="templateStatusClass(template.status)">
							{{ templateStatusLabel(template.status) }}
						</span>
					</div>
					<dl class="messaging-settings__facts">
						<div>
							<dt>{{ t('pipelinq', 'Provider') }}</dt>
							<dd>{{ providerDisplayName(template.providerId) }}</dd>
						</div>
						<div>
							<dt>{{ t('pipelinq', 'Language') }}</dt>
							<dd>{{ template.language }}</dd>
						</div>
						<div>
							<dt>{{ t('pipelinq', 'Last synced') }}</dt>
							<dd>{{ formatDate(template.lastSyncedAt) }}</dd>
						</div>
					</dl>
				</li>
			</ul>
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
	approved: 'messaging-settings__badge--on',
	pending: 'messaging-settings__badge--sandbox',
	rejected: 'messaging-settings__badge--error',
	disabled: 'messaging-settings__badge--off',
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
				TEMPLATE_STATUS_CLASSES[status] || 'messaging-settings__badge--off'
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

.messaging-settings__toolbar {
	display: flex;
	gap: 8px;
	margin-bottom: 12px;
}

.messaging-settings__records {
	display: flex;
	flex-direction: column;
	margin: 0;
	padding: 0;
	list-style: none;
}

.messaging-settings__record {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 14px 0;
	border-top: 1px solid var(--color-border);
}

.messaging-settings__record:last-child {
	border-bottom: 1px solid var(--color-border);
}

.messaging-settings__record-head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px 8px;
}

.messaging-settings__record-name {
	margin: 0;
	font-size: 15px;
	font-weight: 600;
	overflow-wrap: anywhere;
}

.messaging-settings__facts {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
	gap: 8px 16px;
	margin: 0;
}

.messaging-settings__facts dt,
.messaging-settings__facts dd {
	display: block;
	width: auto;
	margin: 0;
	padding: 0;
	text-align: start;
}

.messaging-settings__facts dt {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.messaging-settings__facts dd {
	overflow-wrap: break-word;
}

.messaging-settings__fact--wide {
	grid-column: 1 / -1;
}

.messaging-settings__fields {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
	align-items: end;
	gap: 8px 16px;
}

.messaging-settings__record-actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.messaging-settings__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 12px);
	font-size: 11px;
	font-weight: 600;
	margin-inline-end: 4px;
}

.messaging-settings__badge--on {
	background: var(--color-success);
	color: var(--color-primary-element-text);
}

.messaging-settings__badge--off {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.messaging-settings__badge--sandbox {
	background: var(--color-warning);
	color: var(--color-primary-element-text);
}

.messaging-settings__badge--error {
	background: var(--color-error);
	color: var(--color-primary-element-text);
}

.messaging-settings__facts dd.messaging-settings__webhook-cell {
	display: flex;
	align-items: center;
	gap: 4px;
}

.messaging-settings__webhook-url {
	min-width: 0;
	overflow-wrap: anywhere;
	font-size: 11px;
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
