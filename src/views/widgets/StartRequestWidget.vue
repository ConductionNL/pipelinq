<template>
	<div class="start-request-widget">
		<div v-if="!success" class="widget-form">
			<NcTextField :value.sync="form.title"
				:label="t('pipelinq', 'Title')"
				:placeholder="t('pipelinq', 'Request title (required)')"
				:error="submitted && !form.title"
				@keyup.enter="onSubmit" />

			<ClientAutocomplete :value="selectedClient"
				:placeholder="t('pipelinq', 'Search client...')"
				:label="t('pipelinq', 'Client')"
				@input="onClientSelected" />

			<NcSelect v-model="form.category"
				:options="categoryOptions"
				:placeholder="t('pipelinq', 'Category')"
				input-id="request-category" />

			<NcSelect v-model="form.priority"
				:options="priorityOptions"
				:placeholder="t('pipelinq', 'Priority')"
				input-id="request-priority" />

			<NcSelect v-model="form.channel"
				:options="channelOptions"
				:placeholder="t('pipelinq', 'Channel')"
				input-id="request-channel" />

			<NcButton type="primary"
				:disabled="submitting"
				@click="onSubmit">
				{{ submitting ? t('pipelinq', 'Creating...') : t('pipelinq', 'Create request') }}
			</NcButton>
		</div>

		<div v-else class="widget-success">
			<NcNoteCard type="success">
				{{ t('pipelinq', 'Request created!') }}
				<a :href="successLink">{{ t('pipelinq', 'View request') }}</a>
			</NcNoteCard>
			<NcButton type="secondary" @click="resetForm">
				{{ t('pipelinq', 'Create another') }}
			</NcButton>
		</div>

		<div v-if="recentRequests.length > 0" class="recent-list">
			<h4>{{ t('pipelinq', 'Recent requests') }}</h4>
			<ul>
				<li v-for="req in recentRequests" :key="req.id">
					<a :href="'/index.php/apps/pipelinq/requests/' + req.id">
						{{ req.title || t('pipelinq', 'Untitled') }}
					</a>
					<span class="recent-status">{{ req.status }}</span>
				</li>
			</ul>
		</div>
	</div>
</template>

<script>
import { NcTextField, NcButton, NcSelect, NcNoteCard } from '@nextcloud/vue'
import ClientAutocomplete from '../../components/widgets/ClientAutocomplete.vue'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'StartRequestWidget',
	components: {
		NcTextField,
		NcButton,
		NcSelect,
		NcNoteCard,
		ClientAutocomplete,
	},
	props: {
		title: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			config: null,
			form: {
				title: '',
				category: null,
				priority: 'normal',
				channel: null,
			},
			selectedClient: null,
			submitted: false,
			submitting: false,
			success: false,
			successLink: '',
			recentRequests: [],
			categoryOptions: [
				t('pipelinq', 'General'),
				t('pipelinq', 'Technical'),
				t('pipelinq', 'Billing'),
				t('pipelinq', 'Support'),
			],
			priorityOptions: [
				{ id: 'low', label: t('pipelinq', 'Low') },
				{ id: 'normal', label: t('pipelinq', 'Normal') },
				{ id: 'high', label: t('pipelinq', 'High') },
				{ id: 'urgent', label: t('pipelinq', 'Urgent') },
			],
			channelOptions: [
				{ id: 'phone', label: t('pipelinq', 'Phone') },
				{ id: 'email', label: t('pipelinq', 'Email') },
				{ id: 'walk-in', label: t('pipelinq', 'Walk-in') },
				{ id: 'web', label: t('pipelinq', 'Web') },
			],
		}
	},
	async mounted() {
		try {
			const { objectStore } = await initializeStores()
			this.config = objectStore.objectTypeRegistry
			await this.fetchRecentRequests()
		} catch (err) {
			console.error('StartRequestWidget init error:', err)
		}
	},
	methods: {
		onClientSelected(client) {
			this.selectedClient = client
		},
		async onSubmit() {
			this.submitted = true
			if (!this.form.title) {
				return
			}
			if (!this.config?.request) {
				console.error('Request schema not configured')
				return
			}

			this.submitting = true
			try {
				const typeConfig = this.config.request
				const body = {
					title: this.form.title,
					status: 'new',
					priority: typeof this.form.priority === 'object'
						? this.form.priority.id
						: (this.form.priority || 'normal'),
					requestedAt: new Date().toISOString(),
				}

				if (this.selectedClient) {
					body.client = this.selectedClient.id
				}
				if (this.form.category) {
					body.category = typeof this.form.category === 'object'
						? this.form.category.id || this.form.category.label
						: this.form.category
				}
				if (this.form.channel) {
					body.channel = typeof this.form.channel === 'object'
						? this.form.channel.id
						: this.form.channel
				}

				const url = '/apps/openregister/api/objects/'
					+ typeConfig.register + '/' + typeConfig.schema

				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify(body),
				})

				if (!response.ok) throw new Error('Failed to create request')
				const created = await response.json()
				const id = created.id || created.uuid
				this.successLink = '/index.php/apps/pipelinq/requests/' + id
				this.success = true
			} catch (err) {
				console.error('StartRequestWidget create error:', err)
			} finally {
				this.submitting = false
			}
		},
		resetForm() {
			this.form = { title: '', category: null, priority: 'normal', channel: null }
			this.selectedClient = null
			this.submitted = false
			this.success = false
			this.successLink = ''
			this.fetchRecentRequests()
		},
		async fetchRecentRequests() {
			if (!this.config?.request) return
			try {
				const typeConfig = this.config.request
				const params = new URLSearchParams({ _limit: '3', _order: 'desc' })
				const url = '/apps/openregister/api/objects/'
					+ typeConfig.register + '/' + typeConfig.schema
					+ '?' + params.toString()

				const response = await fetch(url, {
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
				})

				if (!response.ok) return
				const data = await response.json()
				this.recentRequests = (data.results || data || []).slice(0, 3)
			} catch (err) {
				console.error('Failed to fetch recent requests:', err)
			}
		},
	},
}
</script>

<style scoped>
.start-request-widget {
	padding: 12px 16px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.widget-form {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.widget-success {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.recent-list {
	margin-top: 8px;
	border-top: 1px solid var(--color-border);
	padding-top: 8px;
}

.recent-list h4 {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin: 0 0 4px 0;
}

.recent-list ul {
	list-style: none;
	padding: 0;
	margin: 0;
}

.recent-list li {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 4px 0;
	font-size: 13px;
}

.recent-list a {
	color: var(--color-primary-element);
	text-decoration: none;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	flex: 1;
}

.recent-status {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	margin-left: 8px;
}
</style>
