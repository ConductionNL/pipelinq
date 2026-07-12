<template>
	<div class="find-client-widget">
		<div class="search-bar">
			<NcTextField :value.sync="searchQuery"
				:placeholder="t('pipelinq', 'Search clients...')"
				:label="t('pipelinq', 'Search')" />
			<NcButton type="secondary" @click="showNewClientForm = !showNewClientForm">
				{{ t('pipelinq', 'New client') }}
			</NcButton>
		</div>

		<!-- New client mini-form -->
		<div v-if="showNewClientForm" class="new-client-form">
			<NcTextField :value.sync="newClient.name"
				:label="t('pipelinq', 'Name')"
				:placeholder="t('pipelinq', 'Client name (required)')"
				:error="newClientSubmitted && !newClient.name" />
			<NcSelect v-model="newClient.type"
				:options="typeOptions"
				:input-label="t('pipelinq', 'Type')"
				:placeholder="t('pipelinq', 'Type')"
				input-id="new-client-type" />
			<NcTextField :value.sync="newClient.email"
				:label="t('pipelinq', 'Email')"
				:placeholder="t('pipelinq', 'Email address')"
				type="email" />
			<NcButton type="primary"
				:disabled="creatingClient"
				@click="createClient">
				{{ creatingClient ? t('pipelinq', 'Creating...') : t('pipelinq', 'Add client') }}
			</NcButton>
		</div>

		<!-- Loading state -->
		<div v-if="loading" class="loading-state">
			<NcLoadingIcon />
		</div>

		<!-- Client results -->
		<div v-else-if="filteredClients.length > 0" class="client-results">
			<div v-for="client in filteredClients"
				:key="client.id"
				class="client-row">
				<div class="client-info" @click="viewClient(client)">
					<span class="client-icon">
						<AccountGroup v-if="client.type === 'organization'" :size="18" />
						<Account v-else :size="18" />
					</span>
					<div class="client-details">
						<span class="client-name">{{ toText(client.name) || t('pipelinq', 'Unnamed') }}</span>
						<span class="client-contact">
							{{ [toText(client.email), toText(client.phone)].filter(Boolean).join(' · ') }}
						</span>
					</div>
				</div>
				<div class="client-actions">
					<NcButton type="tertiary"
						:aria-label="t('pipelinq', 'View client')"
						@click="viewClient(client)">
						<template #icon>
							<Eye :size="18" />
						</template>
					</NcButton>
					<NcButton type="tertiary"
						:aria-label="t('pipelinq', 'Create request for this client')"
						@click="createRequestForClient(client)">
						<template #icon>
							<FileDocumentOutline :size="18" />
						</template>
					</NcButton>
					<NcButton type="tertiary"
						:aria-label="t('pipelinq', 'Create lead for this client')"
						@click="createLeadForClient(client)">
						<template #icon>
							<TrendingUp :size="18" />
						</template>
					</NcButton>
					<NcButton v-if="client.email"
						type="tertiary"
						:aria-label="t('pipelinq', 'Copy email')"
						@click="copyEmail(client)">
						<template #icon>
							<ContentCopy :size="18" />
						</template>
					</NcButton>
				</div>
			</div>
		</div>

		<!-- Empty state -->
		<div v-else class="empty-state">
			<p>
				{{ searchQuery
					? t('pipelinq', 'No clients found for "{query}"', { query: searchQuery })
					: t('pipelinq', 'No clients found')
				}}
			</p>
		</div>

		<!-- Inline request/lead creation -->
		<div v-if="actionClient" class="inline-action">
			<NcNoteCard type="info">
				{{ actionType === 'request'
					? t('pipelinq', 'Creating request for {name}', { name: toText(actionClient.name) })
					: t('pipelinq', 'Creating lead for {name}', { name: toText(actionClient.name) })
				}}
			</NcNoteCard>
			<NcTextField :value.sync="actionTitle"
				:label="t('pipelinq', 'Title')"
				:placeholder="actionType === 'request'
					? t('pipelinq', 'Request title')
					: t('pipelinq', 'Lead title')"
				@keyup.enter="submitAction" />
			<div class="action-buttons">
				<NcButton type="primary"
					:disabled="!actionTitle || actionSubmitting"
					@click="submitAction">
					{{ t('pipelinq', 'Create') }}
				</NcButton>
				<NcButton type="secondary" @click="cancelAction">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
			</div>
		</div>

		<!-- Copy success feedback -->
		<div v-if="copyFeedback" class="copy-feedback">
			{{ t('pipelinq', 'Email copied!') }}
		</div>
	</div>
</template>

<script>
import { NcTextField, NcButton, NcSelect, NcNoteCard, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import Account from 'vue-material-design-icons/Account.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import { initializeStores } from '../../store/store.js'
import { resolveObjectType } from '../../services/pipelineUtils.js'
import { toText } from '../../utils/widgetText.js'

export default {
	name: 'FindClientWidget',
	components: {
		NcTextField,
		NcButton,
		NcSelect,
		NcNoteCard,
		NcLoadingIcon,
		Account,
		AccountGroup,
		Eye,
		FileDocumentOutline,
		TrendingUp,
		ContentCopy,
	},
	props: {
		title: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			loading: false,
			config: null,
			clients: [],
			searchQuery: '',
			showNewClientForm: false,
			newClient: { name: '', type: 'person', email: '' },
			newClientSubmitted: false,
			creatingClient: false,
			typeOptions: [
				{ id: 'person', label: t('pipelinq', 'Person') },
				{ id: 'organization', label: t('pipelinq', 'Organization') },
			],
			actionClient: null,
			actionType: '',
			actionTitle: '',
			actionSubmitting: false,
			copyFeedback: false,
		}
	},
	computed: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-40
		 */
		filteredClients() {
			if (!this.searchQuery) return this.clients.slice(0, 20)
			const query = this.searchQuery.toLowerCase()
			return this.clients.filter((client) => {
				const name = toText(client.name).toLowerCase()
				const email = toText(client.email).toLowerCase()
				const phone = toText(client.phone).toLowerCase()
				return name.includes(query) || email.includes(query) || phone.includes(query)
			}).slice(0, 20)
		},
	},
	async mounted() {
		await this.fetchData()
	},
	methods: {
		toText,
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-38
		 */
		async fetchData() {
			this.loading = true
			try {
				const { objectStore } = await initializeStores()
				this.config = objectStore.objectTypeRegistry

				if (this.config.client) {
					this.clients = await this.fetchRaw('client', { _limit: 200 })
				}
			} catch (err) {
				console.error('FindClientWidget fetch error:', err)
			} finally {
				this.loading = false
			}
		},
		/**
		 * @param type
		 * @param params
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-39
		 */
		async fetchRaw(type, params = {}) {
			const typeConfig = this.config[type]
			if (!typeConfig) return []

			const queryParams = new URLSearchParams()
			for (const [key, value] of Object.entries(params)) {
				if (value === undefined || value === null || value === '') continue
				queryParams.set(key, value)
			}

			const url = generateUrl('/apps/openregister/api/objects/' + typeConfig.register + '/' + typeConfig.schema
				+ (queryParams.toString() ? '?' + queryParams.toString() : ''))

			const response = await fetch(url, {
				headers: {
					'Content-Type': 'application/json',
					requesttoken: OC.requestToken,
					'OCS-APIREQUEST': 'true',
				},
			})

			if (!response.ok) throw new Error('Failed to fetch ' + type)
			const data = await response.json()
			return data.results || data || []
		},
		/**
		 * @param client
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-42
		 */
		viewClient(client) {
			window.location.href = generateUrl('/apps/pipelinq/clients/' + client.id)
		},
		/**
		 * @param client
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-37
		 */
		createRequestForClient(client) {
			this.actionClient = client
			this.actionType = 'request'
			this.actionTitle = ''
		},
		/**
		 * @param client
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-36
		 */
		createLeadForClient(client) {
			this.actionClient = client
			this.actionType = 'lead'
			this.actionTitle = ''
		},
		/**
		 * @param client
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-34
		 */
		async copyEmail(client) {
			try {
				await navigator.clipboard.writeText(client.email)
				this.copyFeedback = true
				setTimeout(() => { this.copyFeedback = false }, 2000)
			} catch (err) {
				console.error('Failed to copy email:', err)
			}
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-33
		 */
		cancelAction() {
			this.actionClient = null
			this.actionType = ''
			this.actionTitle = ''
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-41
		 */
		async submitAction() {
			if (!this.actionTitle || !this.actionClient) return

			this.actionSubmitting = true
			try {
				const type = this.actionType
				// `type` is the *logical* entity ('request' | 'lead'). A request is a
				// `ticket` with ticketType 'request', and `requestedAt` became
				// `occurredAt` (unify-ticket-supertype).
				const { objectType, ticketType } = resolveObjectType(type)
				const typeConfig = this.config[objectType]
				if (!typeConfig) throw new Error('Schema not configured for ' + type)

				const body = {
					title: this.actionTitle,
					client: this.actionClient.id,
				}

				if (ticketType) {
					body.ticketType = ticketType
				}

				if (type === 'request') {
					body.status = 'new'
					body.priority = 'normal'
					body.occurredAt = new Date().toISOString()
				} else {
					body.status = 'open'
				}

				const url = generateUrl('/apps/openregister/api/objects/'
					+ typeConfig.register + '/' + typeConfig.schema)

				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify(body),
				})

				if (!response.ok) throw new Error('Failed to create ' + type)
				const created = await response.json()
				const id = created.id || created.uuid
				// A request is a `ticket` with ticketType=request
				// (unify-ticket-supertype) and opens on the unified /tickets page.
				const path = type === 'request' ? 'tickets' : 'leads'
				window.location.href = generateUrl('/apps/pipelinq/' + path + '/' + id)
			} catch (err) {
				console.error('Action submit error:', err)
			} finally {
				this.actionSubmitting = false
			}
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-35
		 */
		async createClient() {
			this.newClientSubmitted = true
			if (!this.newClient.name) return

			this.creatingClient = true
			try {
				const typeConfig = this.config.client
				if (!typeConfig) throw new Error('Client schema not configured')

				const body = {
					name: this.newClient.name,
					type: typeof this.newClient.type === 'object'
						? this.newClient.type.id
						: (this.newClient.type || 'person'),
				}
				if (this.newClient.email) {
					body.email = this.newClient.email
				}

				const url = generateUrl('/apps/openregister/api/objects/'
					+ typeConfig.register + '/' + typeConfig.schema)

				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify(body),
				})

				if (!response.ok) throw new Error('Failed to create client')
				const created = await response.json()
				this.clients.unshift(created)
				this.newClient = { name: '', type: 'person', email: '' }
				this.newClientSubmitted = false
				this.showNewClientForm = false
			} catch (err) {
				console.error('Create client error:', err)
			} finally {
				this.creatingClient = false
			}
		},
	},
}
</script>

<style scoped>
.find-client-widget {
	padding: 8px 16px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.search-bar {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}

.search-bar .input-field {
	flex: 1;
}

.new-client-form {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.client-results {
	max-height: 300px;
	overflow-y: auto;
}

.client-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}

.client-row:last-child {
	border-bottom: none;
}

.client-info {
	display: flex;
	align-items: center;
	gap: 8px;
	cursor: pointer;
	flex: 1;
	min-width: 0;
}

.client-icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.client-details {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.client-name {
	font-weight: 500;
	color: var(--color-main-text);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.client-contact {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.client-actions {
	display: flex;
	gap: 0;
	flex-shrink: 0;
}

.empty-state {
	text-align: center;
	padding: 16px;
	color: var(--color-text-maxcontrast);
}

.inline-action {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.action-buttons {
	display: flex;
	gap: 8px;
}

.copy-feedback {
	position: fixed;
	bottom: 16px;
	right: 16px;
	padding: 8px 16px;
	background: var(--color-success);
	color: var(--color-primary-text);
	border-radius: var(--border-radius);
	font-size: 13px;
	z-index: 1000;
}

.loading-state {
	display: flex;
	justify-content: center;
	padding: 24px;
}
</style>
