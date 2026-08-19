<template>
	<div>
		<!-- Page Title -->
		<NcSettingsSection
			:name="t('pipelinq', 'Pipelinq Settings')"
			:description="t('pipelinq', 'Configure your Pipelinq installation')"
			doc-url="https://pipelinq.conduction.nl/docs/intro" />

		<!-- Version Information -->
		<CnVersionInfoCard
			:app-name="'Pipelinq'"
			:app-version="appVersion"
			:is-up-to-date="true"
			:show-update-button="true"
			:title="t('pipelinq', 'Version Information')"
			:description="t('pipelinq', 'Information about the current Pipelinq installation')">
			<template #actions>
				<NcButton variant="primary"
					:disabled="reimporting"
					@click="reimport">
					<template #icon>
						<NcLoadingIcon v-if="reimporting" :size="20" />
						<Refresh v-else :size="20" />
					</template>
					{{ reimporting ? t('pipelinq', 'Importing...') : t('pipelinq', 'Re-import configuration') }}
				</NcButton>
			</template>
			<template #footer>
				<div class="cn-support-info">
					<h4>{{ t('pipelinq', 'Support') }}</h4>
					<p>
						{{ t('pipelinq', 'For support, contact us at') }}
						<a href="mailto:support@conduction.nl">support@conduction.nl</a>
					</p>
					<p>
						{{ t('pipelinq', 'For a Service Level Agreement (SLA), contact') }}
						<a href="mailto:sales@conduction.nl">sales@conduction.nl</a>
					</p>
				</div>
			</template>
		</CnVersionInfoCard>

		<!-- Register & Schema Mapping -->
		<CnRegisterMapping
			:name="t('pipelinq', 'Register Configuration')"
			:description="t('pipelinq', 'Map Pipelinq object types to OpenRegister registers and schemas')"
			:groups="registerGroups"
			:configuration="config"
			:saving="saving"
			@save="save" />

		<!-- Pipeline Management -->
		<PipelineManager v-if="isConfigured" />

		<!-- Product Categories -->
		<ProductCategoryManager v-if="isConfigured" />

		<!-- Queue Management -->
		<QueueSettings v-if="isConfigured" />

		<!-- Skill Management -->
		<SkillSettings v-if="isConfigured" />

		<!-- Agent Profile Management -->
		<AgentProfileSettings v-if="isConfigured" />

		<!-- Forecast Configuration -->
		<ForecastSettings v-if="isConfigured" />

		<!-- Lead Sources -->
		<TagManager v-if="isConfigured"
			:title="t('pipelinq', 'Lead Sources')"
			:tags="leadSourceTags"
			:loading="leadSourcesLoading"
			:add-label="t('pipelinq', '+ Add Source')"
			:add-placeholder="t('pipelinq', 'Enter source name...')"
			:usage-check="checkLeadSourceUsage"
			@add="addLeadSource"
			@remove="removeLeadSource"
			@rename="renameLeadSource" />

		<!-- Request Channels -->
		<TagManager v-if="isConfigured"
			:title="t('pipelinq', 'Request Channels')"
			:tags="requestChannelTags"
			:loading="requestChannelsLoading"
			:add-label="t('pipelinq', '+ Add Channel')"
			:add-placeholder="t('pipelinq', 'Enter channel name...')"
			:usage-check="checkRequestChannelUsage"
			@add="addRequestChannel"
			@remove="removeRequestChannel"
			@rename="renameRequestChannel" />

		<!-- Prospect Discovery Settings -->
		<ProspectSettings v-if="isConfigured" />

		<!-- BI Export Configuration -->
		<ExportConfigurationSettings v-if="isAdmin"
			:config="config"
			@saved="onExportConfigSaved" />

		<!-- Lead Management — stale threshold (REQ-LM-002).
		     Persisted via IAppConfig under key `lead_stale_threshold_days`. -->
		<NcSettingsSection v-if="isAdmin"
			:name="t('pipelinq', 'Lead Management')"
			:description="t('pipelinq', 'Tune lead staleness detection for kanban badges and the lead list filter.')">
			<NcTextField v-model="staleThresholdInput"
				type="number"
				:label="t('pipelinq', 'Stale after (days)')"
				placeholder="14"
				:helper-text="t('pipelinq', 'Number of days a lead can stay untouched before it is flagged as stale. Default: 14.')" />
			<NcButton variant="primary"
				:disabled="savingStale"
				@click="saveStaleThreshold">
				<template #icon>
					<NcLoadingIcon v-if="savingStale" :size="16" />
				</template>
				{{ t('pipelinq', 'Save lead settings') }}
			</NcButton>
			<NcNoteCard v-if="staleMessage" :type="staleMessageType">
				{{ staleMessage }}
			</NcNoteCard>
		</NcSettingsSection>

		<!-- Shillinq Integration -->
		<NcSettingsSection v-if="isAdmin"
			:name="t('pipelinq', 'Shillinq Integration')"
			:description="t('pipelinq', 'The HTTPS endpoint of the Shillinq project ledger. Leave empty to disable ledger sync.')">
			<NcTextField v-model="config.shillinq_ledger_webhook_url"
				:label="t('pipelinq', 'Shillinq Ledger Webhook URL')"
				placeholder="https://shillinq.example.com/ledger/webhook"
				:error="shillinqUrlInvalid"
				:helper-text="shillinqUrlInvalid ? t('pipelinq', 'Please enter a valid HTTPS URL') : ''" />
			<NcTextField v-model="config.shillinq_wip_webhook_url"
				:label="t('pipelinq', 'Shillinq WIP webhook URL')"
				placeholder="https://shillinq.example.com/api/wip/events"
				:error="wipUrlInvalid"
				:helper-text="wipUrlInvalid ? t('pipelinq', 'Please enter a valid HTTPS URL') : ''" />
			<!-- Real time-intake emit (time-billing-handoff-emit). Default off — an
			     unconfigured install keeps the deep-link-only handoff unchanged. -->
			<NcCheckboxRadioSwitch v-model="shillinqTimeIntakeEnabled" type="switch">
				{{ t('pipelinq', 'Send approved hours to Shillinq as draft invoices') }}
			</NcCheckboxRadioSwitch>
			<NcTextField v-model="config.billing_handoff_manager_group"
				:label="t('pipelinq', 'Billing handoff manager group')"
				placeholder="billing-managers"
				:helper-text="t('pipelinq', 'Nextcloud group allowed to trigger \'Send to billing\'. Leave empty to restrict it to Nextcloud administrators.')" />
			<NcButton variant="primary"
				:disabled="savingShillinq || shillinqUrlInvalid || wipUrlInvalid"
				@click="saveShillinq">
				<template #icon>
					<NcLoadingIcon v-if="savingShillinq" :size="16" />
				</template>
				{{ t('pipelinq', 'Save Shillinq Configuration') }}
			</NcButton>
			<NcNoteCard v-if="shillinqMessage" :type="shillinqMessageType">
				{{ shillinqMessage }}
			</NcNoteCard>
		</NcSettingsSection>

		<!-- Integraties — Shillinq AP webhook (REQ-AP-004) -->
		<NcSettingsSection v-if="isAdmin"
			:name="t('pipelinq', 'Integrations')"
			:description="t('pipelinq', 'Enter the webhook URL for the Shillinq AP integration. Leave empty to keep it disabled.')">
			<NcTextField v-model="config.shillinq_ap_webhook_url"
				:label="t('pipelinq', 'Shillinq AP webhook URL')"
				placeholder="https://shillinq.example.com/ap-webhook"
				:error="shillinqApUrlInvalid"
				:helper-text="shillinqApUrlInvalid ? t('pipelinq', 'Enter a valid HTTPS URL, e.g. https://shillinq.example.com/webhook') : ''" />
			<NcButton variant="primary"
				:disabled="savingShillinqAp || shillinqApUrlInvalid"
				@click="saveShillinqAp">
				<template #icon>
					<NcLoadingIcon v-if="savingShillinqAp" :size="16" />
				</template>
				{{ t('pipelinq', 'Save Shillinq AP Configuration') }}
			</NcButton>
			<NcNoteCard v-if="shillinqApMessage" :type="shillinqApMessageType">
				{{ shillinqApMessage }}
			</NcNoteCard>
		</NcSettingsSection>

		<!-- xWiki Integration (xwiki-integration) -->
		<NcSettingsSection v-if="isAdmin"
			:name="t('pipelinq', 'xWiki integration')"
			:description="t('pipelinq', 'Configure the xWiki knowledge base proxy used by the dashboard widget and the detail sidebar tabs. When the xWiki Nextcloud app is installed its settings take precedence over the fallback URL below.')">
			<NcNoteCard v-if="xwikiStatus" :type="xwikiStatus.available ? 'success' : 'warning'">
				<span v-if="xwikiStatus.available">
					{{ t('pipelinq', 'xWiki reachable at {url}', { url: xwikiStatus.baseUrl }) }}
					<template v-if="xwikiStatus.version">
						({{ t('pipelinq', 'version {ver}', { ver: xwikiStatus.version }) }})
					</template>
				</span>
				<span v-else>
					{{ t('pipelinq', 'xWiki not reachable. The xWiki Nextcloud app is not installed and the direct URL is empty or unreachable.') }}
				</span>
			</NcNoteCard>
			<NcNoteCard v-if="!xwikiAppInstalled" type="warning">
				{{ t('pipelinq', 'The optional xWiki Nextcloud app is not installed. Pipelinq is falling back to the configured direct URL.') }}
			</NcNoteCard>
			<NcTextField v-model="config.xwiki_default_space"
				:label="t('pipelinq', 'Default xWiki space')"
				placeholder="Kennisbank"
				:helper-text="t('pipelinq', 'Space name pre-selected by the dashboard widget. Leave empty to search across all spaces.')" />
			<NcTextField v-model="config.xwiki_cache_ttl"
				type="number"
				:label="t('pipelinq', 'Cache TTL (seconds)')"
				placeholder="300"
				:helper-text="t('pipelinq', 'How long search and page results are cached server-side. Default: 300.')" />
			<NcTextField v-model="config.xwiki_direct_url"
				:label="t('pipelinq', 'Direct xWiki URL (fallback)')"
				placeholder="http://xwiki:8080/xwiki"
				:helper-text="t('pipelinq', 'Used only when the xWiki Nextcloud app is unavailable. Should point at the xWiki base URL without trailing slash.')" />
			<div class="xwiki-actions">
				<NcButton variant="primary"
					:disabled="savingXwiki"
					@click="saveXwiki">
					<template #icon>
						<NcLoadingIcon v-if="savingXwiki" :size="16" />
					</template>
					{{ t('pipelinq', 'Save xWiki settings') }}
				</NcButton>
				<NcButton variant="secondary"
					:disabled="testingXwiki"
					@click="testXwiki">
					<template #icon>
						<NcLoadingIcon v-if="testingXwiki" :size="16" />
					</template>
					{{ t('pipelinq', 'Test connection') }}
				</NcButton>
			</div>
			<NcNoteCard v-if="xwikiMessage" :type="xwikiMessageType">
				{{ xwikiMessage }}
			</NcNoteCard>
		</NcSettingsSection>

		<!-- Channels & telephony. Configuration, not an operator surface, so it
		     lives here rather than in the app nav (nav-ia-cleanup). -->
		<MessagingSettings v-if="isAdmin && isConfigured" />
		<CtiPage v-if="isAdmin && isConfigured" />

		<!-- Point of Sale configuration. `PaymentSettingsForm` (PSP providers —
		     who processes the money) and `PosTenderTypeList` (tender types — how
		     the customer pays at the register) read as duplicates but are two
		     different things; the labels are what confused them. -->
		<PaymentSettingsForm v-if="isAdmin && isConfigured" />
		<PosTenderTypeManager v-if="isAdmin && isConfigured" />
		<PosStaffManager v-if="isAdmin && isConfigured" />
		<PosRoleManager v-if="isAdmin && isConfigured" />

		<!-- Re-import Status -->
		<div v-if="message" class="actions-section">
			<NcNoteCard :type="messageType">
				{{ message }}
			</NcNoteCard>
		</div>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { CnRegisterMapping, CnVersionInfoCard } from '@conduction/nextcloud-vue'
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcSettingsSection, NcTextField } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import { useSettingsStore } from '../../store/modules/settings.js'
import { useLeadSourcesStore } from '../../store/modules/leadSources.js'
import { useRequestChannelsStore } from '../../store/modules/requestChannels.js'
import { useObjectStore } from '../../store/modules/object.js'
import { objectTypeGroups, objectTypes } from '../../config/objectTypes.js'
import PipelineManager from './PipelineManager.vue'
import ProductCategoryManager from './ProductCategoryManager.vue'
import ProspectSettings from './ProspectSettings.vue'
import TagManager from './TagManager.vue'
import QueueSettings from '../../components/admin/QueueSettings.vue'
import SkillSettings from '../../components/admin/SkillSettings.vue'
import AgentProfileSettings from '../../components/admin/AgentProfileSettings.vue'
import ForecastSettings from '../../components/admin/ForecastSettings.vue'
import ExportConfigurationSettings from './ExportConfigurationSettings.vue'
// Configuration surfaces moved off the app nav onto this admin page
// (nav-ia-cleanup): channels, telephony, and the POS master-data.
import MessagingSettings from './MessagingSettings.vue'
import CtiPage from './CtiPage.vue'
import PaymentSettingsForm from './PaymentSettingsForm.vue'
import PosTenderTypeManager from './PosTenderTypeManager.vue'
import PosStaffManager from './PosStaffManager.vue'
import PosRoleManager from './PosRoleManager.vue'

export default {
	name: 'Settings',
	components: {
		MessagingSettings,
		CtiPage,
		PaymentSettingsForm,
		PosTenderTypeManager,
		PosStaffManager,
		PosRoleManager,
		CnRegisterMapping,
		CnVersionInfoCard,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSettingsSection,
		NcTextField,
		Refresh,
		PipelineManager,
		ProductCategoryManager,
		ProspectSettings,
		TagManager,
		QueueSettings,
		SkillSettings,
		AgentProfileSettings,
		ForecastSettings,
		ExportConfigurationSettings,
	},
	data() {
		return {
			config: {},
			appVersion: loadState('pipelinq', 'version', 'Unknown'),
			reimporting: false,
			saving: false,
			message: '',
			messageType: 'success',
			isAdmin: false,
			// Shillinq ledger integration.
			savingShillinq: false,
			shillinqMessage: '',
			shillinqMessageType: 'success',
			// Shillinq AP integration (REQ-AP-004).
			savingShillinqAp: false,
			shillinqApMessage: '',
			shillinqApMessageType: 'success',
			// Lead management — stale threshold (REQ-LM-002).
			staleThresholdInput: 14,
			savingStale: false,
			staleMessage: '',
			staleMessageType: 'success',
			// xWiki integration (xwiki-integration).
			savingXwiki: false,
			testingXwiki: false,
			xwikiMessage: '',
			xwikiMessageType: 'success',
			xwikiStatus: null,
			xwikiAppInstalled: true,
		}
	},
	computed: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-87
		 */
		settingsStore() {
			return useSettingsStore()
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-74
		 */
		leadSourcesStore() {
			return useLeadSourcesStore()
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-85
		 */
		requestChannelsStore() {
			return useRequestChannelsStore()
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-76
		 */
		objectStore() {
			return useObjectStore()
		},
		isConfigured() {
			return !!this.config.register
		},
		/**
		 * The real time-intake emit flag (time-billing-handoff-emit), converted
		 * between the backend's string 'true'/'false' config value and a
		 * checkbox boolean.
		 *
		 * @spec openspec/specs/time-approval-workflow/spec.md
		 */
		shillinqTimeIntakeEnabled: {
			get() {
				return this.config.shillinq_time_intake_enabled === 'true'
			},
			set(value) {
				this.config = { ...this.config, shillinq_time_intake_enabled: value ? 'true' : 'false' }
			},
		},
		/**
		 * Whether the entered Shillinq webhook URL is present but not a valid HTTPS URL.
		 * An empty value is valid (disables the integration).
		 */
		shillinqUrlInvalid() {
			const url = (this.config.shillinq_ledger_webhook_url || '').trim()
			if (url === '') {
				return false
			}
			try {
				return new URL(url).protocol !== 'https:'
			} catch (e) {
				return true
			}
		},
		/**
		 * Whether the entered Shillinq WIP webhook URL is present but not a valid HTTPS URL.
		 * An empty value is valid (disables the integration).
		 *
		 * @spec openspec/changes/pipelinq-time-to-shillinq-wip/specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-004
		 */
		wipUrlInvalid() {
			const url = (this.config.shillinq_wip_webhook_url || '').trim()
			if (url === '') {
				return false
			}
			try {
				return new URL(url).protocol !== 'https:'
			} catch (e) {
				return true
			}
		},
		/**
		 * Whether the entered Shillinq AP webhook URL is present but not a valid HTTPS URL.
		 * An empty value is valid (disables the integration). REQ-AP-004 Scenario 13.
		 *
		 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-004
		 */
		shillinqApUrlInvalid() {
			const url = (this.config.shillinq_ap_webhook_url || '').trim()
			if (url === '') {
				return false
			}
			try {
				return new URL(url).protocol !== 'https:'
			} catch (e) {
				return true
			}
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-72
		 */
		leadSourceTags() {
			return this.leadSourcesStore.tags
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-73
		 */
		leadSourcesLoading() {
			return this.leadSourcesStore.loading
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-83
		 */
		requestChannelTags() {
			return this.requestChannelsStore.tags
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-84
		 */
		requestChannelsLoading() {
			return this.requestChannelsStore.loading
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-77
		 */
		registerGroups() {
			const types = objectTypes()
			return objectTypeGroups()
				.map((group) => ({
					name: group.name,
					description: group.description,
					registerConfigKey: group.registerConfigKey,
					types: types
						.filter((type) => type.group === group.key)
						.map(({ slug, label, description }) => ({ slug, label, description })),
				}))
				.filter((group) => group.types.length > 0)
		},
	},
	/**
	 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-75
	 */
	async mounted() {
		const data = await this.settingsStore.fetchSettings()
		if (data) {
			this.config = data.config || {}
			this.isAdmin = this.settingsStore.isAdmin
			// Seed the stale-threshold input from the persisted config
			// (REQ-LM-002). Falls back to 14 days when unset.
			const parsed = parseInt(this.config.lead_stale_threshold_days, 10)
			this.staleThresholdInput = Number.isFinite(parsed) && parsed > 0 ? parsed : 14
		}

		if (this.isConfigured) {
			this.leadSourcesStore.fetchSources()
			this.requestChannelsStore.fetchChannels()
		}

		if (this.isAdmin) {
			// xWiki integration (xwiki-integration): probe status so the
			// admin section shows the reachable / unreachable badge on load.
			this.testXwiki()
		}
	},
	methods: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-78
		 */
		async reimport() {
			this.reimporting = true
			this.message = ''

			try {
				const response = await fetch(generateUrl('/apps/pipelinq/api/settings/reimport'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
				})

				const data = await response.json()

				if (data.success) {
					this.config = data.config || {}
					this.message = t('pipelinq', 'Configuration re-imported successfully')
					this.messageType = 'success'
				} else {
					this.message = data.message || t('pipelinq', 'Re-import failed')
					this.messageType = 'error'
				}
			} catch (error) {
				this.message = error.message || t('pipelinq', 'Re-import failed')
				this.messageType = 'error'
			} finally {
				this.reimporting = false
			}
		},
		/**
		 * @param configuration
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-86
		 */
		async save(configuration) {
			this.saving = true
			this.message = ''
			const result = await this.settingsStore.saveSettings(configuration)
			if (result) {
				this.config = this.settingsStore.config || result
				this.message = t('pipelinq', 'Configuration saved')
				this.messageType = 'success'
			}
			this.saving = false
		},
		/**
		 * @param name
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-67
		 */
		async addLeadSource(name) {
			await this.leadSourcesStore.addSource(name)
		},
		/**
		 * @param id
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-79
		 */
		async removeLeadSource(id) {
			await this.leadSourcesStore.removeSource(id)
		},
		/**
		 * @param id
		 * @param name
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-81
		 */
		async renameLeadSource(id, name) {
			await this.leadSourcesStore.renameSource(id, name)
		},
		/**
		 * @param name
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-68
		 */
		async addRequestChannel(name) {
			await this.requestChannelsStore.addChannel(name)
		},
		/**
		 * @param id
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-80
		 */
		async removeRequestChannel(id) {
			await this.requestChannelsStore.removeChannel(id)
		},
		/**
		 * @param id
		 * @param name
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-82
		 */
		async renameRequestChannel(id, name) {
			await this.requestChannelsStore.renameChannel(id, name)
		},
		/**
		 * @param sourceName
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-69
		 */
		async checkLeadSourceUsage(sourceName) {
			return this.countObjectsWithField('lead', 'source', sourceName)
		},
		/**
		 * @param channelName
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-70
		 */
		async checkRequestChannelUsage(channelName) {
			// A request is a `ticket` narrowed by ticketType (unify-ticket-supertype),
			// so the usage count must exclude complaints and contactmomenten.
			return this.countObjectsWithField('ticket', 'channel', channelName, { ticketType: 'request' })
		},
		/**
		 * Persist the Shillinq ledger webhook URL through the standard settings endpoint.
		 *
		 * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-006-03
		 */
		async saveShillinq() {
			if (this.shillinqUrlInvalid || this.wipUrlInvalid) {
				return
			}
			this.savingShillinq = true
			this.shillinqMessage = ''
			try {
				const result = await this.settingsStore.saveSettings({
					...this.config,
					shillinq_ledger_webhook_url: (this.config.shillinq_ledger_webhook_url || '').trim(),
					shillinq_wip_webhook_url: (this.config.shillinq_wip_webhook_url || '').trim(),
				})
				if (result) {
					this.config = this.settingsStore.config || result
				}
				this.shillinqMessage = t('pipelinq', 'Shillinq configuration saved.')
				this.shillinqMessageType = 'success'
			} catch (e) {
				this.shillinqMessage = e.response?.data?.message || t('pipelinq', 'Failed to save Shillinq configuration.')
				this.shillinqMessageType = 'error'
			} finally {
				this.savingShillinq = false
			}
		},
		/**
		 * Reflect a saved export-configuration payload back into local state.
		 *
		 * @param {object} updated The updated config returned by the section.
		 * @spec openspec/changes/bi-export-and-data-warehouse-sink/tasks.md#task-14.1
		 */
		onExportConfigSaved(updated) {
			if (updated && typeof updated === 'object') {
				this.config = { ...this.config, ...updated }
			}
		},
		/**
		 * Persist the Shillinq AP webhook URL through the standard settings endpoint.
		 *
		 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-004
		 */
		async saveShillinqAp() {
			if (this.shillinqApUrlInvalid) {
				return
			}
			this.savingShillinqAp = true
			this.shillinqApMessage = ''
			try {
				const result = await this.settingsStore.saveSettings({
					...this.config,
					shillinq_ap_webhook_url: (this.config.shillinq_ap_webhook_url || '').trim(),
				})
				if (result) {
					this.config = this.settingsStore.config || result
				}
				this.shillinqApMessage = t('pipelinq', 'Shillinq AP configuration saved.')
				this.shillinqApMessageType = 'success'
			} catch (e) {
				this.shillinqApMessage = e.response?.data?.message || t('pipelinq', 'Failed to save Shillinq AP configuration.')
				this.shillinqApMessageType = 'error'
			} finally {
				this.savingShillinqAp = false
			}
		},
		/**
		 * Persist the lead stale threshold through the standard settings endpoint.
		 *
		 * @spec openspec/specs/lead-management/spec.md
		 */
		async saveStaleThreshold() {
			this.savingStale = true
			this.staleMessage = ''
			const days = parseInt(this.staleThresholdInput, 10)
			if (!Number.isFinite(days) || days <= 0) {
				this.staleMessage = t('pipelinq', 'Stale threshold must be a positive number of days.')
				this.staleMessageType = 'error'
				this.savingStale = false
				return
			}
			try {
				const result = await this.settingsStore.saveSettings({
					...this.config,
					lead_stale_threshold_days: String(days),
				})
				if (result) {
					this.config = this.settingsStore.config || result
				}
				this.staleMessage = t('pipelinq', 'Lead settings saved.')
				this.staleMessageType = 'success'
			} catch (e) {
				this.staleMessage = e.response?.data?.message || t('pipelinq', 'Failed to save lead settings.')
				this.staleMessageType = 'error'
			} finally {
				this.savingStale = false
			}
		},
		/**
		 * Persist xWiki integration settings (xwiki-integration).
		 *
		 * @spec openspec/changes/xwiki-integration/tasks.md#8.1
		 */
		async saveXwiki() {
			this.savingXwiki = true
			this.xwikiMessage = ''
			try {
				const result = await this.settingsStore.saveSettings({
					...this.config,
					xwiki_default_space: (this.config.xwiki_default_space || '').trim(),
					xwiki_cache_ttl: String(this.config.xwiki_cache_ttl || 300),
					xwiki_direct_url: (this.config.xwiki_direct_url || '').trim(),
				})
				if (result) {
					this.config = this.settingsStore.config || result
				}
				this.xwikiMessage = t('pipelinq', 'xWiki settings saved.')
				this.xwikiMessageType = 'success'
				await this.testXwiki()
			} catch (e) {
				this.xwikiMessage = e.response?.data?.message || t('pipelinq', 'Failed to save xWiki settings.')
				this.xwikiMessageType = 'error'
			} finally {
				this.savingXwiki = false
			}
		},
		/**
		 * Test the xWiki connection via /api/xwiki/status.
		 *
		 * @spec openspec/changes/xwiki-integration/tasks.md#8.1
		 */
		async testXwiki() {
			this.testingXwiki = true
			try {
				const response = await fetch(generateUrl('/apps/pipelinq/api/xwiki/status'), {
					headers: {
						requesttoken: typeof OC !== 'undefined' && OC.requestToken ? OC.requestToken : '',
						'OCS-APIREQUEST': 'true',
					},
				})
				const data = await response.json()
				this.xwikiStatus = data
				this.xwikiAppInstalled = data && data.source === 'xwiki-app'
				if (data.available) {
					this.xwikiMessage = t('pipelinq', 'xWiki connection successful.')
					this.xwikiMessageType = 'success'
				} else {
					this.xwikiMessage = t('pipelinq', 'xWiki connection failed. Check the direct URL or install the xWiki Nextcloud app.')
					this.xwikiMessageType = 'warning'
				}
			} catch (e) {
				this.xwikiMessage = e.message || t('pipelinq', 'Failed to reach the xWiki status endpoint.')
				this.xwikiMessageType = 'error'
			} finally {
				this.testingXwiki = false
			}
		},
		/**
		 * @param type
		 * @param field
		 * @param value
		 * @param {object} [extraFilters] Additional query filters, e.g. the
		 *   `ticketType` discriminator needed to narrow the `ticket` supertype
		 *   down to one of its subtypes (unify-ticket-supertype).
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-71
		 */
		async countObjectsWithField(type, field, value, extraFilters = {}) {
			const config = this.objectStore.objectTypeRegistry[type]
			if (!config) return 0
			const extra = Object.entries(extraFilters)
				.map(([k, v]) => `${k}=${encodeURIComponent(v)}&`)
				.join('')
			const url = generateUrl(`/apps/openregister/api/objects/${config.register}/${config.schema}?${extra}${field}=${encodeURIComponent(value)}&_limit=1`)
			const response = await fetch(url, {
				headers: {
					'Content-Type': 'application/json',
					requesttoken: OC.requestToken,
					'OCS-APIREQUEST': 'true',
				},
			})
			if (!response.ok) return 0
			const data = await response.json()
			return data.total || 0
		},
	},
}
</script>

<style scoped>
.actions-section {
	margin-top: 16px;
}
</style>
