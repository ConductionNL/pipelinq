<template>
	<div>
		<!-- Page Title -->
		<NcSettingsSection
			:name="t('pipelinq', 'Pipelinq Settings')"
			:description="t('pipelinq', 'Configure your Pipelinq installation')"
			doc-url="https://pipelinq.app" />

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

		<!-- Re-import Status -->
		<div v-if="message" class="actions-section">
			<NcNoteCard :type="messageType">
				{{ message }}
			</NcNoteCard>
		</div>
	</div>
</template>

<script>
import { CnRegisterMapping, CnVersionInfoCard } from '@conduction/nextcloud-vue'
import { NcButton, NcLoadingIcon, NcNoteCard, NcSettingsSection } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import { useSettingsStore } from '../../store/modules/settings.js'
import { useLeadSourcesStore } from '../../store/modules/leadSources.js'
import { useRequestChannelsStore } from '../../store/modules/requestChannels.js'
import { useObjectStore } from '../../store/modules/object.js'
import PipelineManager from './PipelineManager.vue'
import TagManager from './TagManager.vue'

export default {
	name: 'Settings',
	components: {
		CnRegisterMapping,
		CnVersionInfoCard,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSettingsSection,
		Refresh,
		PipelineManager,
		TagManager,
	},
	data() {
		return {
			config: {},
			appVersion: document.getElementById('pipelinq-settings')?.dataset?.version || 'Unknown',
			reimporting: false,
			saving: false,
			message: '',
			messageType: 'success',
		}
	},
	computed: {
		settingsStore() {
			return useSettingsStore()
		},
		leadSourcesStore() {
			return useLeadSourcesStore()
		},
		requestChannelsStore() {
			return useRequestChannelsStore()
		},
		objectStore() {
			return useObjectStore()
		},
		isConfigured() {
			return !!this.config.register
		},
		leadSourceTags() {
			return this.leadSourcesStore.tags
		},
		leadSourcesLoading() {
			return this.leadSourcesStore.loading
		},
		requestChannelTags() {
			return this.requestChannelsStore.tags
		},
		requestChannelsLoading() {
			return this.requestChannelsStore.loading
		},
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
				],
			}]
		},
	},
	async mounted() {
		const config = await this.settingsStore.fetchSettings()
		if (config) {
			this.config = config
		}

		if (this.isConfigured) {
			this.leadSourcesStore.fetchSources()
			this.requestChannelsStore.fetchChannels()
		}
	},
	methods: {
		async reimport() {
			this.reimporting = true
			this.message = ''

			try {
				const response = await fetch('/apps/pipelinq/api/settings/reimport', {
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
		async addLeadSource(name) {
			await this.leadSourcesStore.addSource(name)
		},
		async removeLeadSource(id) {
			await this.leadSourcesStore.removeSource(id)
		},
		async renameLeadSource(id, name) {
			await this.leadSourcesStore.renameSource(id, name)
		},
		async addRequestChannel(name) {
			await this.requestChannelsStore.addChannel(name)
		},
		async removeRequestChannel(id) {
			await this.requestChannelsStore.removeChannel(id)
		},
		async renameRequestChannel(id, name) {
			await this.requestChannelsStore.renameChannel(id, name)
		},
		async checkLeadSourceUsage(sourceName) {
			return this.countObjectsWithField('lead', 'source', sourceName)
		},
		async checkRequestChannelUsage(channelName) {
			return this.countObjectsWithField('request', 'channel', channelName)
		},
		async countObjectsWithField(type, field, value) {
			const config = this.objectStore.objectTypeRegistry[type]
			if (!config) return 0
			const url = `/apps/openregister/api/objects/${config.register}/${config.schema}?${field}=${encodeURIComponent(value)}&_limit=1`
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
