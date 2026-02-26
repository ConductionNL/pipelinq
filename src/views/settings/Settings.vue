<template>
	<div class="pipelinq-settings">
		<h2>{{ t('pipelinq', 'Pipelinq') }}</h2>

		<NcLoadingIcon v-if="loading" />

		<div v-else>
			<!-- Register Status -->
			<div class="status-section">
				<h3>{{ t('pipelinq', 'Register Status') }}</h3>

				<div v-if="isConfigured" class="status-card">
					<span class="status-indicator status-green" />
					<div class="status-info">
						<strong>{{ t('pipelinq', 'Connected') }}</strong>
						<p>{{ t('pipelinq', 'Register') }}: pipelinq ({{ config.register }})</p>
					</div>
				</div>

				<div v-else class="status-card">
					<span class="status-indicator status-orange" />
					<div class="status-info">
						<strong>{{ t('pipelinq', 'Not configured') }}</strong>
						<p>{{ t('pipelinq', 'OpenRegister is required. Install and enable it, then click Re-import.') }}</p>
					</div>
				</div>
			</div>

			<!-- Schema List -->
			<div class="schema-section">
				<h3>{{ t('pipelinq', 'Schemas') }}</h3>
				<table class="schema-table">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Name') }}</th>
							<th>{{ t('pipelinq', 'ID') }}</th>
							<th>{{ t('pipelinq', 'Status') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="schema in schemas" :key="schema.slug">
							<td>{{ schema.label }}</td>
							<td>{{ schema.id || '—' }}</td>
							<td>
								<span v-if="schema.id" class="status-indicator status-green" />
								<span v-else class="status-indicator status-orange" />
							</td>
						</tr>
					</tbody>
				</table>
			</div>

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

			<!-- Re-import Action -->
			<div class="actions-section">
				<NcButton type="primary"
					:disabled="reimporting"
					@click="reimport">
					<template #icon>
						<NcLoadingIcon v-if="reimporting" :size="20" />
					</template>
					{{ t('pipelinq', 'Re-import configuration') }}
				</NcButton>

				<NcNoteCard v-if="message" :type="messageType">
					{{ message }}
				</NcNoteCard>
			</div>

			<!-- Manual Configuration -->
			<details class="manual-config">
				<summary>{{ t('pipelinq', 'Manual configuration') }}</summary>
				<div class="form-group">
					<label>{{ t('pipelinq', 'Register') }}</label>
					<NcTextField
						:value="form.register"
						:label="t('pipelinq', 'Register')"
						@update:value="v => form.register = v" />
				</div>
				<div v-for="schema in schemas" :key="schema.slug" class="form-group">
					<label>{{ schema.label }}</label>
					<NcTextField
						:value="form[schema.key]"
						:label="schema.label"
						@update:value="v => form[schema.key] = v" />
				</div>

				<NcButton type="secondary" @click="save">
					{{ t('pipelinq', 'Save') }}
				</NcButton>
			</details>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard, NcTextField } from '@nextcloud/vue'
import { useSettingsStore } from '../../store/modules/settings.js'
import { useLeadSourcesStore } from '../../store/modules/leadSources.js'
import { useRequestChannelsStore } from '../../store/modules/requestChannels.js'
import { useObjectStore } from '../../store/modules/object.js'
import PipelineManager from './PipelineManager.vue'
import TagManager from './TagManager.vue'

export default {
	name: 'Settings',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		PipelineManager,
		TagManager,
	},
	data() {
		return {
			form: {
				register: '',
				client_schema: '',
				contact_schema: '',
				lead_schema: '',
				request_schema: '',
				pipeline_schema: '',
			},
			config: {},
			reimporting: false,
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
		loading() {
			return this.settingsStore.isLoading
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
		schemas() {
			return [
				{ slug: 'client', key: 'client_schema', label: t('pipelinq', 'Client'), id: this.config.client_schema },
				{ slug: 'contact', key: 'contact_schema', label: t('pipelinq', 'Contact'), id: this.config.contact_schema },
				{ slug: 'lead', key: 'lead_schema', label: t('pipelinq', 'Lead'), id: this.config.lead_schema },
				{ slug: 'request', key: 'request_schema', label: t('pipelinq', 'Request'), id: this.config.request_schema },
				{ slug: 'pipeline', key: 'pipeline_schema', label: t('pipelinq', 'Pipeline'), id: this.config.pipeline_schema },
			]
		},
	},
	async mounted() {
		const config = await this.settingsStore.fetchSettings()
		if (config) {
			this.config = config
			this.form = { ...this.form, ...config }
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
					this.form = { ...this.form, ...this.config }
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
		async save() {
			this.message = ''
			const result = await this.settingsStore.saveSettings(this.form)
			if (result) {
				this.config = result
				this.message = t('pipelinq', 'Configuration saved')
				this.messageType = 'success'
			}
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
.pipelinq-settings {
	padding: 20px;
	max-width: 900px;
}

.status-section,
.schema-section,
.actions-section {
	margin-bottom: 24px;
}

.status-card {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	border-radius: var(--border-radius-large);
	border: 1px solid var(--color-border);
}

.status-info p {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}

.status-indicator {
	display: inline-block;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	flex-shrink: 0;
}

.status-green {
	background-color: var(--color-success);
}

.status-orange {
	background-color: var(--color-warning);
}

.schema-table {
	width: 100%;
	border-collapse: collapse;
}

.schema-table th,
.schema-table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.schema-table th {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.actions-section .notecard {
	margin-top: 12px;
}

.manual-config {
	margin-top: 24px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.manual-config summary {
	cursor: pointer;
	font-weight: bold;
	margin-bottom: 12px;
}

.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}
</style>
