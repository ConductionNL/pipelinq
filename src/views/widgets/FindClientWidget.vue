<template>
	<div class="find-client-widget">
		<div class="search-bar">
			<NcTextField
				v-model="searchQuery"
				:placeholder="t('pipelinq', 'Search clients…')"
				:label="t('pipelinq', 'Search')" />
			<NcButton
				variant="secondary"
				@click="showNewClientForm = !showNewClientForm">
				{{ t('pipelinq', 'New client') }}
			</NcButton>
		</div>

		<!-- New client mini-form -->
		<div v-if="showNewClientForm" class="new-client-form">
			<NcTextField
				v-model="newClient.name"
				:label="t('pipelinq', 'Name')"
				:placeholder="t('pipelinq', 'Client name (required)')"
				:error="newClientSubmitted && !newClient.name" />
			<NcSelect
				v-model="newClient.type"
				:options="typeOptions"
				:inputLabel="t('pipelinq', 'Type')"
				:placeholder="t('pipelinq', 'Type')"
				inputId="new-client-type" />
			<NcTextField
				v-model="newClient.email"
				:label="t('pipelinq', 'Email')"
				:placeholder="t('pipelinq', 'Email address')"
				type="email" />
			<NcButton
				variant="primary"
				:disabled="creatingClient"
				@click="createClient">
				{{
					creatingClient
						? t('pipelinq', 'Creating…')
						: t('pipelinq', 'Add client')
				}}
			</NcButton>
		</div>

		<!-- Loading state -->
		<div v-if="loading" class="loading-state">
			<NcLoadingIcon />
		</div>

		<!-- Client results -->
		<div v-else-if="filteredClients.length > 0" class="client-results">
			<div
				v-for="client in filteredClients"
				:key="client.id"
				class="client-row">
				<div
					class="client-info"
					role="button"
					tabindex="0"
					:aria-label="
						t('pipelinq', 'Open client {name}', { name: client.name })
					"
					@click="viewClient(client, $event)"
					@auxclick="viewClient(client, $event)"
					@keydown.enter="viewClient(client, $event)"
					@keydown.space="viewClientOnSpace(client, $event)">
					<span class="client-icon">
						<AccountGroup
							v-if="client.type === 'organization'"
							:size="18" />
						<Account v-else :size="18" />
					</span>
					<div class="client-details">
						<span class="client-name">{{
							toText(client.name) || t('pipelinq', 'Unnamed')
						}}</span>
						<span class="client-contact">
							{{
								[toText(client.email), toText(client.phone)]
									.filter(Boolean)
									.join(' · ')
							}}
						</span>
					</div>
				</div>
				<div class="client-actions">
					<NcButton
						variant="tertiary"
						:aria-label="t('pipelinq', 'View client')"
						:href="clientUrl(client)">
						<template #icon>
							<Eye :size="18" />
						</template>
					</NcButton>
					<NcButton
						variant="tertiary"
						:aria-label="t('pipelinq', 'Create request for this client')"
						@click="createRequestForClient(client)">
						<template #icon>
							<FileDocumentOutline :size="18" />
						</template>
					</NcButton>
					<NcButton
						variant="tertiary"
						:aria-label="t('pipelinq', 'Create lead for this client')"
						@click="createLeadForClient(client)">
						<template #icon>
							<TrendingUp :size="18" />
						</template>
					</NcButton>
					<NcButton
						v-if="client.email"
						variant="tertiary"
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
				{{
					searchQuery
						? t('pipelinq', 'No clients found for "{query}"', {
								query: searchQuery,
							})
						: t('pipelinq', 'No clients found')
				}}
			</p>
		</div>

		<!-- Inline request/lead creation -->
		<div v-if="actionClient" class="inline-action">
			<NcNoteCard type="info">
				{{
					actionType === 'request'
						? t('pipelinq', 'Creating request for {name}', {
								name: toText(actionClient.name),
							})
						: t('pipelinq', 'Creating lead for {name}', {
								name: toText(actionClient.name),
							})
				}}
			</NcNoteCard>
			<NcTextField
				v-model="actionTitle"
				:label="t('pipelinq', 'Title')"
				:placeholder="
					actionType === 'request'
						? t('pipelinq', 'Request title')
						: t('pipelinq', 'Lead title')
				"
				@keyup.enter="submitAction" />
			<div class="action-buttons">
				<NcButton
					variant="primary"
					:disabled="!actionTitle || actionSubmitting"
					@click="submitAction">
					{{ t('pipelinq', 'Create') }}
				</NcButton>
				<NcButton variant="secondary" @click="cancelAction">
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
import { openRowTarget } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import Account from 'vue-material-design-icons/Account.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import { fetchRegisteredObjects } from '../../services/objectFetch.js'
import { resolveObjectType } from '../../services/pipelineUtils.js'
import { initializeStores } from '../../store/store.js'
import { toText } from '../../utils/widgetText.js'

const RESULT_LIMIT = 20
const SEARCH_DEBOUNCE_MS = 300

/**
 * Post a new object; a refused post throws the failure.
 *
 * @param {{register: string, schema: string}} typeConfig The object type's register and schema.
 * @param {{body: object, failure: string}} creation The object and what a refusal says.
 * @return {Promise<object>} The created object.
 */
async function postObject(typeConfig, creation) {
	const url = generateUrl(
		'/apps/openregister/api/objects/'
			+ typeConfig.register
			+ '/'
			+ typeConfig.schema,
	)

	const response = await fetch(url, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			requesttoken: OC.requestToken,
			'OCS-APIREQUEST': 'true',
		},
		body: JSON.stringify(creation.body),
	})

	if (!response.ok) throw new Error(creation.failure)
	return response.json()
}

/**
 * The request or lead an action starts for a client.
 *
 * @param {{type: string, ticketType: (string|null), title: string, clientId: string}} action The logical type, its ticket type, the title and the client.
 * @return {object} The object to post.
 */
function actionBody(action) {
	const body = {
		title: action.title,
		client: action.clientId,
	}

	if (action.ticketType) {
		body.ticketType = action.ticketType
	}
	return { ...body, ...startingFields(action.type) }
}

/**
 * @param {string} type The logical type.
 * @return {object} A request's status, priority and moment, or a lead's open status.
 */
function startingFields(type) {
	if (type === 'request') {
		return {
			status: 'new',
			priority: 'normal',
			occurredAt: new Date().toISOString(),
		}
	}
	return { status: 'open' }
}

/**
 * @param {{name: string, type: (object|string), email: string}} newClient The new client form.
 * @return {object} The client to post: a person by default, the e-mail address when given.
 */
function clientBody(newClient) {
	const body = {
		name: newClient.name,
		type:
			typeof newClient.type === 'object'
				? newClient.type.id
				: newClient.type || 'person',
	}
	if (newClient.email) {
		body.email = newClient.email
	}
	return body
}

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
		/**
		 * Widget heading, supplied by the manifest.
		 *
		 * Declared but not rendered here: the widget chrome draws the heading.
		 * Declaring it is what stops Vue's attribute fallthrough painting
		 * `title="…"` onto the component's root element, which would hover a
		 * browser tooltip over the whole widget.
		 */
		// eslint-disable-next-line vue/no-unused-properties
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
			searchTimer: null,
			searchTicket: 0,
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
		 * The server already searched; this only caps what the widget shows.
		 *
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-40
		 */
		filteredClients() {
			return this.clients.slice(0, RESULT_LIMIT)
		},
	},

	watch: {
		/**
		 * Search on the server as the user types, so every client can be
		 * found, not only the first page.
		 *
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-40
		 */
		searchQuery() {
			clearTimeout(this.searchTimer)
			this.searchTimer = setTimeout(() => this.searchClients(), SEARCH_DEBOUNCE_MS)
		},
	},

	async mounted() {
		await this.fetchData()
	},

	beforeUnmount() {
		clearTimeout(this.searchTimer)
	},

	methods: {
		toText,
		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-38
		 */
		async fetchData() {
			this.loading = true
			try {
				const { objectStore } = await initializeStores()
				this.config = objectStore.objectTypeRegistry

				await this.searchClients()
			} catch (err) {
				console.error('FindClientWidget fetch error:', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the clients matching the search box. A slower answer to an
		 * older query never replaces a newer one.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-40
		 */
		async searchClients() {
			if (!this.config?.client) {
				return
			}
			const query = this.searchQuery.trim()
			const ticket = ++this.searchTicket
			try {
				const clients = await this.fetchRaw('client', { _search: query, _limit: RESULT_LIMIT })
				if (ticket === this.searchTicket) {
					this.clients = clients
				}
			} catch (err) {
				console.error('FindClientWidget search error:', err)
			}
		},

		/**
		 * @param {string} type The registered object-type slug.
		 * @param {string} params Query parameters.
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-39
		 */
		async fetchRaw(type, params = {}) {
			return fetchRegisteredObjects(this.config, { type, params })
		},

		/**
		 * A plain URL, not a router location: the widget also runs on the
		 * Nextcloud Dashboard, where no vue-router is present.
		 *
		 * @param {object} client The client.
		 * @return {string} The client's detail page URL.
		 * @spec openspec/specs/dashboard/spec.md#requirement-nextcloud-dashboard-widget-api-integration
		 */
		clientUrl(client) {
			return generateUrl('/apps/pipelinq/clients/' + client.id)
		},

		/**
		 * Open a client from its row: a plain click or key navigates in place,
		 * a ctrl/cmd/shift or middle click opens a new tab.
		 *
		 * @param {object} client The client to open.
		 * @param {MouseEvent|KeyboardEvent} [event] The triggering event.
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-42
		 */
		viewClient(client, event) {
			openRowTarget(event, this.clientUrl(client))
		},

		/**
		 * Space opens the client like Enter. Its scroll is prevented only after
		 * the open, so a modified Space still reaches openRowTarget unhandled.
		 *
		 * @param {object} client The client to open.
		 * @param {KeyboardEvent} event The keydown.
		 * @spec openspec/specs/dashboard/spec.md#requirement-nextcloud-dashboard-widget-api-integration
		 */
		viewClientOnSpace(client, event) {
			this.viewClient(client, event)
			event.preventDefault()
		},

		/**
		 * @param {object} client The client to raise a request for.
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-37
		 */
		createRequestForClient(client) {
			this.actionClient = client
			this.actionType = 'request'
			this.actionTitle = ''
		},

		/**
		 * @param {object} client The client to raise a lead for.
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-36
		 */
		createLeadForClient(client) {
			this.actionClient = client
			this.actionType = 'lead'
			this.actionTitle = ''
		},

		/**
		 * @param {object} client The client whose email to copy.
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-34
		 */
		async copyEmail(client) {
			try {
				await navigator.clipboard.writeText(client.email)
				this.copyFeedback = true
				setTimeout(() => {
					this.copyFeedback = false
				}, 2000)
			} catch (err) {
				console.error('Failed to copy email:', err)
			}
		},

		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-33
		 */
		cancelAction() {
			this.actionClient = null
			this.actionType = ''
			this.actionTitle = ''
		},

		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-41
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

				const created = await postObject(typeConfig, {
					body: actionBody({ type, ticketType, title: this.actionTitle, clientId: this.actionClient.id }),
					failure: 'Failed to create ' + type,
				})
				const id = created.id || created.uuid
				// A request is a `ticket` with ticketType=request
				// (unify-ticket-supertype) and opens on the unified /tickets page.
				const path = type === 'request' ? 'tickets' : 'leads'
				window.location.href = generateUrl(
					'/apps/pipelinq/' + path + '/' + id,
				)
			} catch (err) {
				console.error('Action submit error:', err)
			} finally {
				this.actionSubmitting = false
			}
		},

		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-35
		 */
		async createClient() {
			this.newClientSubmitted = true
			if (!this.newClient.name) return

			this.creatingClient = true
			try {
				const typeConfig = this.config.client
				if (!typeConfig) throw new Error('Client schema not configured')

				const created = await postObject(typeConfig, {
					body: clientBody(this.newClient),
					failure: 'Failed to create client',
				})
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
	inset-inline-end: 16px;
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
