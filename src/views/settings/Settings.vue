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

		<!-- Lead Settings -->
		<NcSettingsSection v-if="isConfigured"
			:name="t('pipelinq', 'Lead Settings')"
			:description="t('pipelinq', 'Configure lead pipeline behaviour')">
			<div class="lead-settings-field">
				<NcTextField
					type="number"
					:label="t('pipelinq', 'Stale after (days)')"
					:value.sync="staleThresholdInput"
					min="1"
					@update:value="onStaleThresholdChange" />
				<p class="lead-settings-hint">
					{{ t('pipelinq', 'Leads with no activity for this many days are marked stale.') }}
				</p>
			</div>
		</NcSettingsSection>

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
import { NcButton, NcLoadingIcon, NcNoteCard, NcSettingsSection, NcTextField } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
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

export default {
	name: 'Settings',
	components: {
		CnRegisterMapping,
		CnVersionInfoCard,
		NcButton,
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
	},
	data() {
		return {
			config: {},
			appVersion: loadState('pipelinq', 'version', 'Unknown'),
			reimporting: false,
			saving: false,
			message: '',
			messageType: 'success',
			staleThresholdInput: '14',
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
		const config = await this.settingsStore.fetchSettings()
		if (config) {
			this.config = config
			this.staleThresholdInput = String(config.lead_stale_threshold_days || '14')
		}

		if (this.isConfigured) {
			this.leadSourcesStore.fetchSources()
			this.requestChannelsStore.fetchChannels()
		}
	},
	methods: {
		/**
		 * Persist the lead stale threshold (days) via the settings API.
		 *
		 * @param {string} value The new threshold value.
		 * @return {Promise<void>}
		 * @spec openspec/changes/lead-management/tasks.md#2.1
		 */
		async onStaleThresholdChange(value) {
			const days = parseInt(value, 10)
			if (Number.isNaN(days) || days < 1) {
				return
			}
			this.staleThresholdInput = String(days)
			try {
				const updated = await this.settingsStore.saveSettings({
					...this.config,
					lead_stale_threshold_days: String(days),
				})
				if (updated) {
					this.config = updated
				} else {
					this.messageType = 'error'
					this.message = this.t('pipelinq', 'Failed to save settings. Please try again.')
				}
			} catch (e) {
				this.messageType = 'error'
				this.message = this.t('pipelinq', 'Failed to save settings. Please try again.')
				console.error('Pipelinq: failed to save stale threshold', e)
			}
		},
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
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-86
		 */
		async save(configuration) {
			this.saving = true
			this.message = ''
			const result = await this.settingsStore.saveSettings(configuration)
			if (result) {
				this.config = result
				this.message = t('pipelinq', 'Configuration saved')
				this.messageType = 'success'
			}
			this.saving = false
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-67
		 */
		async addLeadSource(name) {
			await this.leadSourcesStore.addSource(name)
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-79
		 */
		async removeLeadSource(id) {
			await this.leadSourcesStore.removeSource(id)
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-81
		 */
		async renameLeadSource(id, name) {
			await this.leadSourcesStore.renameSource(id, name)
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-68
		 */
		async addRequestChannel(name) {
			await this.requestChannelsStore.addChannel(name)
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-80
		 */
		async removeRequestChannel(id) {
			await this.requestChannelsStore.removeChannel(id)
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-82
		 */
		async renameRequestChannel(id, name) {
			await this.requestChannelsStore.renameChannel(id, name)
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-69
		 */
		async checkLeadSourceUsage(sourceName) {
			return this.countObjectsWithField('lead', 'source', sourceName)
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-70
		 */
		async checkRequestChannelUsage(channelName) {
			return this.countObjectsWithField('request', 'channel', channelName)
		},
		/**
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
</style>
