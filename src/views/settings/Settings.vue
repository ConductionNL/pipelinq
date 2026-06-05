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
				<NcButton type="primary"
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
			@update:configuration="config = $event"
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

		<!-- Objects API Access -->
		<NcSettingsSection v-if="isAdmin"
			:name="t('pipelinq', 'Objects API Access')"
			:description="t('pipelinq', 'Restrict access to specific object types by Nextcloud group')">
			<div v-if="!objectenAccessEntries.length" class="settings-empty-state">
				{{ t('pipelinq', 'No schemas registered. Run re-import first.') }}
			</div>
			<table v-else class="settings-table">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Schema') }}</th>
						<th>{{ t('pipelinq', 'Allowed Groups') }}</th>
						<th>{{ t('pipelinq', 'Actions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="entry in objectenAccessEntries" :key="entry.slug">
						<td>
							<strong>{{ entry.slug }}</strong>
						</td>
						<td>
							<NcSelect v-model="entry.selectedGroups"
								:input-label="t('pipelinq', 'Allowed Groups')"
								:options="groupOptions"
								:multiple="true"
								:searchable="true"
								label="displayName"
								track-by="id"
								:placeholder="t('pipelinq', 'All authenticated users (open)')" />
						</td>
						<td>
							<NcButton :disabled="savingAccess === entry.slug"
								type="primary"
								@click="saveSchemaAccess(entry)">
								<template #icon>
									<NcLoadingIcon v-if="savingAccess === entry.slug" :size="16" />
								</template>
								{{ t('pipelinq', 'Save') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<NcNoteCard v-if="accessMessage" :type="accessMessageType">
				{{ accessMessage }}
			</NcNoteCard>
		</NcSettingsSection>

		<!-- REST API Authentication -->
		<NcSettingsSection v-if="isAdmin"
			:name="t('pipelinq', 'REST API Authentication')"
			:description="t('pipelinq', 'Manage API tokens and OAuth 2.0 configuration for external integrations')">
			<div class="auth-tabs">
				<NcButton :type="authTab === 'tokens' ? 'primary' : 'secondary'" @click="authTab = 'tokens'">
					{{ t('pipelinq', 'Tokens') }}
				</NcButton>
				<NcButton :type="authTab === 'oauth' ? 'primary' : 'secondary'" @click="authTab = 'oauth'">
					{{ t('pipelinq', 'OAuth 2.0') }}
				</NcButton>
			</div>

			<!-- Tokens tab -->
			<div v-if="authTab === 'tokens'">
				<NcButton type="primary"
					class="auth-generate-btn"
					@click="showGenerateTokenDialog = true">
					{{ t('pipelinq', 'Generate Token') }}
				</NcButton>
				<table v-if="apiTokens.length" class="settings-table">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Label') }}</th>
							<th>{{ t('pipelinq', 'Created') }}</th>
							<th>{{ t('pipelinq', 'Last Used') }}</th>
							<th>{{ t('pipelinq', 'Actions') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="token in apiTokens" :key="token.id">
							<td>{{ token.label }}</td>
							<td>{{ token.created }}</td>
							<td>{{ token.lastUsed || t('pipelinq', 'Never') }}</td>
							<td>
								<NcButton type="error"
									:disabled="revokingToken === token.id"
									@click="revokeToken(token.id)">
									<template #icon>
										<NcLoadingIcon v-if="revokingToken === token.id" :size="16" />
									</template>
									{{ t('pipelinq', 'Revoke') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
				<p v-else class="settings-empty-state">
					{{ t('pipelinq', 'No tokens generated yet.') }}
				</p>

				<GenerateTokenDialog v-if="showGenerateTokenDialog"
					@close="showGenerateTokenDialog = false"
					@generated="onTokenGenerated" />
			</div>

			<!-- OAuth 2.0 tab -->
			<div v-if="authTab === 'oauth'">
				<NcTextField v-model="oauthForm.oauth_client_id"
					:label="t('pipelinq', 'Client ID')" />
				<NcTextField v-model="oauthForm.oauth_client_secret"
					:label="t('pipelinq', 'Client Secret')"
					:placeholder="oauthConfig.oauth_secret_configured ? '••••••••' : ''"
					type="password" />
				<NcTextField v-model="oauthForm.oauth_token_endpoint"
					:label="t('pipelinq', 'Token Endpoint')" />
				<NcTextField v-model="oauthForm.oauth_auth_endpoint"
					:label="t('pipelinq', 'Authorization Endpoint')" />
				<NcTextField v-model="oauthForm.oauth_scopes"
					:label="t('pipelinq', 'Scopes')"
					:placeholder="t('pipelinq', 'e.g. openid profile email')" />
				<NcCheckboxRadioSwitch v-model="oauthForm.oauth_id_token_forwarding">
					{{ t('pipelinq', 'Forward idToken (OpenID Connect)') }}
				</NcCheckboxRadioSwitch>
				<NcButton type="primary"
					:disabled="savingOAuth"
					@click="saveOAuth">
					<template #icon>
						<NcLoadingIcon v-if="savingOAuth" :size="16" />
					</template>
					{{ t('pipelinq', 'Save OAuth Configuration') }}
				</NcButton>
				<NcNoteCard v-if="oauthMessage" :type="oauthMessageType">
					{{ oauthMessage }}
				</NcNoteCard>
			</div>
		</NcSettingsSection>

		<!-- MCP Server Administration -->
		<NcSettingsSection v-if="isAdmin"
			:name="t('pipelinq', 'MCP Server Administration')"
			:description="t('pipelinq', 'Configure the MCP server endpoint and authentication credentials')">
			<NcTextField v-model="mcpForm.mcp_endpoint"
				:label="t('pipelinq', 'Endpoint URL')"
				:placeholder="t('pipelinq', 'https://mcp.example.com')" />
			<NcSelect v-model="mcpForm.mcp_auth_mode"
				:input-label="t('pipelinq', 'Authentication Mode')"
				:options="mcpAuthModeOptions"
				label="label"
				track-by="value"
				:placeholder="t('pipelinq', 'Select auth mode')" />
			<NcTextField v-if="mcpForm.mcp_auth_mode && mcpForm.mcp_auth_mode.value === 'apikey'"
				v-model="mcpForm.mcp_api_key"
				:label="t('pipelinq', 'API Key')"
				:placeholder="mcpConfig.mcp_api_key_configured ? '••••••••' : ''"
				type="password" />
			<NcTextField v-if="mcpForm.mcp_auth_mode && mcpForm.mcp_auth_mode.value === 'oauth2'"
				v-model="mcpForm.mcp_oauth_client_id"
				:label="t('pipelinq', 'OAuth Client ID')" />
			<NcTextField v-if="mcpForm.mcp_auth_mode && mcpForm.mcp_auth_mode.value === 'oauth2'"
				v-model="mcpForm.mcp_oauth_client_secret"
				:label="t('pipelinq', 'OAuth Client Secret')"
				:placeholder="mcpConfig.mcp_oauth_secret_configured ? '••••••••' : ''"
				type="password" />
			<NcButton type="primary"
				:disabled="savingMcp"
				@click="saveMcp">
				<template #icon>
					<NcLoadingIcon v-if="savingMcp" :size="16" />
				</template>
				{{ t('pipelinq', 'Save MCP Configuration') }}
			</NcButton>
			<NcNoteCard v-if="mcpMessage" :type="mcpMessageType">
				{{ mcpMessage }}
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
			<NcButton type="primary"
				:disabled="savingShillinq || shillinqUrlInvalid"
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
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcSelect, NcSettingsSection, NcTextField } from '@nextcloud/vue'
import GenerateTokenDialog from '../../dialogs/GenerateTokenDialog.vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import { useSettingsStore } from '../../store/modules/settings.js'
import { useLeadSourcesStore } from '../../store/modules/leadSources.js'
import { useRequestChannelsStore } from '../../store/modules/requestChannels.js'
import { useObjectStore } from '../../store/modules/object.js'
import PipelineManager from './PipelineManager.vue'
import ProductCategoryManager from './ProductCategoryManager.vue'
import ProspectSettings from './ProspectSettings.vue'
import TagManager from './TagManager.vue'
import QueueSettings from '../../components/admin/QueueSettings.vue'
import SkillSettings from '../../components/admin/SkillSettings.vue'
import AgentProfileSettings from '../../components/admin/AgentProfileSettings.vue'
import ForecastSettings from '../../components/admin/ForecastSettings.vue'

export default {
	name: 'Settings',
	components: {
		CnRegisterMapping,
		CnVersionInfoCard,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		GenerateTokenDialog,
		NcNoteCard,
		NcSelect,
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
			// Objects API Access.
			objectenAccess: {},
			groupOptions: [],
			savingAccess: null,
			accessMessage: '',
			accessMessageType: 'success',
			// REST API tokens.
			apiTokens: [],
			authTab: 'tokens',
			showGenerateTokenDialog: false,
			revokingToken: null,
			// OAuth.
			oauthConfig: {},
			oauthForm: {
				oauth_client_id: '',
				oauth_client_secret: '',
				oauth_token_endpoint: '',
				oauth_auth_endpoint: '',
				oauth_scopes: '',
				oauth_id_token_forwarding: false,
			},
			savingOAuth: false,
			oauthMessage: '',
			oauthMessageType: 'success',
			// MCP.
			mcpConfig: {},
			mcpForm: {
				mcp_endpoint: '',
				mcp_auth_mode: null,
				mcp_api_key: '',
				mcp_oauth_client_id: '',
				mcp_oauth_client_secret: '',
			},
			mcpAuthModeOptions: [
				{ value: 'apikey', label: t('pipelinq', 'API Key') },
				{ value: 'oauth2', label: t('pipelinq', 'OAuth 2.0') },
			],
			savingMcp: false,
			mcpMessage: '',
			mcpMessageType: 'success',
			// Shillinq ledger integration.
			savingShillinq: false,
			shillinqMessage: '',
			shillinqMessageType: 'success',
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
		objectenAccessEntries() {
			return Object.entries(this.objectenAccess).map(([slug, groupIds]) => ({
				slug,
				selectedGroups: this.groupOptions.filter(g => groupIds.includes(g.id)),
			}))
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
			return [{
				name: t('pipelinq', 'Pipelinq Objects'),
				description: t('pipelinq', 'Core CRM object types used by Pipelinq'),
				registerConfigKey: 'register',
				types: [
					{ slug: 'client', label: t('pipelinq', 'Client'), description: t('pipelinq', 'Companies and organisations') },
					{ slug: 'contact', label: t('pipelinq', 'Contact'), description: t('pipelinq', 'Contact persons') },
					{ slug: 'lead', label: t('pipelinq', 'Lead'), description: t('pipelinq', 'Sales leads') },
					{ slug: 'request', label: t('pipelinq', 'Request'), description: t('pipelinq', 'Customer requests') },
					{ slug: 'pipeline', label: t('pipelinq', 'Pipeline'), description: t('pipelinq', 'Pipeline stages') },
					{ slug: 'product', label: t('pipelinq', 'Product'), description: t('pipelinq', 'Products and services') },
					{ slug: 'productCategory', label: t('pipelinq', 'Product Category'), description: t('pipelinq', 'Product categories') },
					{ slug: 'leadProduct', label: t('pipelinq', 'Lead Product'), description: t('pipelinq', 'Product line items on leads') },
					{ slug: 'relationship', label: t('pipelinq', 'Relationship'), description: t('pipelinq', 'Typed relationships between contacts and clients') },
					{ slug: 'queue', label: t('pipelinq', 'Queue'), description: t('pipelinq', 'Work queues for routing') },
					{ slug: 'skill', label: t('pipelinq', 'Skill'), description: t('pipelinq', 'Skills for agent routing') },
					{ slug: 'agentProfile', label: t('pipelinq', 'Agent Profile'), description: t('pipelinq', 'Agent skill profiles') },
				],
			}]
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

			if (this.isAdmin) {
				this.objectenAccess = this.settingsStore.objectenAccess
				this.apiTokens = this.settingsStore.apiTokens
				this.oauthConfig = this.settingsStore.oauthConfig
				this.mcpConfig = this.settingsStore.mcpConfig

				this.oauthForm.oauth_client_id = this.oauthConfig.oauth_client_id || ''
				this.oauthForm.oauth_token_endpoint = this.oauthConfig.oauth_token_endpoint || ''
				this.oauthForm.oauth_auth_endpoint = this.oauthConfig.oauth_auth_endpoint || ''
				this.oauthForm.oauth_scopes = this.oauthConfig.oauth_scopes || ''
				this.oauthForm.oauth_id_token_forwarding = this.oauthConfig.oauth_id_token_forwarding === 'true'

				this.mcpForm.mcp_endpoint = this.mcpConfig.mcp_endpoint || ''
				this.mcpForm.mcp_oauth_client_id = this.mcpConfig.mcp_oauth_client_id || ''
				const storedMode = this.mcpConfig.mcp_auth_mode
				this.mcpForm.mcp_auth_mode = this.mcpAuthModeOptions.find(o => o.value === storedMode) || null

				await this.loadGroupOptions()
			}
		}

		if (this.isConfigured) {
			this.leadSourcesStore.fetchSources()
			this.requestChannelsStore.fetchChannels()
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
			return this.countObjectsWithField('request', 'channel', channelName)
		},
		/**
		 * @spec openspec/changes/admin-settings/tasks.md#task-5
		 */
		async loadGroupOptions() {
			try {
				await axios.get(generateUrl('/apps/pipelinq/api/settings'))
				// Fetch groups using Nextcloud's groups API.
				const resp = await axios.get(generateUrl('/ocs/v2.php/cloud/groups'), {
					params: { limit: 200, format: 'json' },
					headers: { 'OCS-APIRequest': 'true' },
				})
				const groups = resp.data?.ocs?.data?.groups || []
				this.groupOptions = groups.map(g => ({ id: g, displayName: g }))
			} catch (e) {
				this.groupOptions = []
			}
		},
		/**
		 * @param entry
		 * @spec openspec/changes/admin-settings/tasks.md#task-5.1
		 */
		async saveSchemaAccess(entry) {
			this.savingAccess = entry.slug
			this.accessMessage = ''
			try {
				await axios.post(generateUrl('/apps/pipelinq/api/settings/objecten-access'), {
					schemaSlug: entry.slug,
					groupIds: entry.selectedGroups.map(g => g.id),
				})
				this.accessMessage = t('pipelinq', 'Access settings saved.')
				this.accessMessageType = 'success'
			} catch (e) {
				this.accessMessage = e.response?.data?.message || t('pipelinq', 'Failed to save access settings.')
				this.accessMessageType = 'error'
			} finally {
				this.savingAccess = null
			}
		},
		/**
		 * @param token
		 * @spec openspec/changes/admin-settings/tasks.md#task-5.2
		 */
		onTokenGenerated(token) {
			this.apiTokens = [...this.apiTokens, { id: token.id, label: token.label, created: token.created, lastUsed: null }]
		},
		/**
		 * @param id
		 * @spec openspec/changes/admin-settings/tasks.md#task-5.2
		 */
		async revokeToken(id) {
			this.revokingToken = id
			try {
				await axios.delete(generateUrl(`/apps/pipelinq/api/settings/api-tokens/${encodeURIComponent(id)}`))
				this.apiTokens = this.apiTokens.filter(t => t.id !== id)
			} catch (e) {
				alert(e.response?.data?.message || t('pipelinq', 'Failed to revoke token.'))
			} finally {
				this.revokingToken = null
			}
		},
		/**
		 * @spec openspec/changes/admin-settings/tasks.md#task-5.3
		 */
		async saveOAuth() {
			this.savingOAuth = true
			this.oauthMessage = ''
			try {
				const payload = { ...this.oauthForm }
				payload.oauth_id_token_forwarding = payload.oauth_id_token_forwarding ? 'true' : 'false'
				const { data } = await axios.post(generateUrl('/apps/pipelinq/api/settings/oauth'), payload)
				this.oauthConfig = data.oauthConfig || {}
				this.oauthMessage = t('pipelinq', 'OAuth configuration saved.')
				this.oauthMessageType = 'success'
			} catch (e) {
				this.oauthMessage = e.response?.data?.message || t('pipelinq', 'Failed to save OAuth configuration.')
				this.oauthMessageType = 'error'
			} finally {
				this.savingOAuth = false
			}
		},
		/**
		 * @spec openspec/changes/admin-settings/tasks.md#task-5.4
		 */
		async saveMcp() {
			this.savingMcp = true
			this.mcpMessage = ''
			try {
				const payload = {
					mcp_endpoint: this.mcpForm.mcp_endpoint,
					mcp_auth_mode: this.mcpForm.mcp_auth_mode?.value || '',
					mcp_api_key: this.mcpForm.mcp_api_key,
					mcp_oauth_client_id: this.mcpForm.mcp_oauth_client_id,
					mcp_oauth_client_secret: this.mcpForm.mcp_oauth_client_secret,
				}
				const { data } = await axios.post(generateUrl('/apps/pipelinq/api/settings/mcp'), payload)
				this.mcpConfig = data.mcpConfig || {}
				this.mcpMessage = t('pipelinq', 'MCP configuration saved.')
				this.mcpMessageType = 'success'
			} catch (e) {
				this.mcpMessage = e.response?.data?.message || t('pipelinq', 'Failed to save MCP configuration.')
				this.mcpMessageType = 'error'
			} finally {
				this.savingMcp = false
			}
		},
		/**
		 * Persist the Shillinq ledger webhook URL through the standard settings endpoint.
		 *
		 * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-006-03
		 */
		async saveShillinq() {
			if (this.shillinqUrlInvalid) {
				return
			}
			this.savingShillinq = true
			this.shillinqMessage = ''
			try {
				const result = await this.settingsStore.saveSettings({
					...this.config,
					shillinq_ledger_webhook_url: (this.config.shillinq_ledger_webhook_url || '').trim(),
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
		 * @param type
		 * @param field
		 * @param value
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-71
		 */
		async countObjectsWithField(type, field, value) {
			const config = this.objectStore.objectTypeRegistry[type]
			if (!config) return 0
			const url = generateUrl(`/apps/openregister/api/objects/${config.register}/${config.schema}?${field}=${encodeURIComponent(value)}&_limit=1`)
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

.settings-table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 16px;
}

.settings-table th,
.settings-table td {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
	vertical-align: middle;
}

.settings-empty-state {
	color: var(--color-text-maxcontrast);
	padding: 8px 0;
}

.auth-tabs {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.auth-generate-btn {
	margin-bottom: 12px;
}

.token-display {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
</style>
