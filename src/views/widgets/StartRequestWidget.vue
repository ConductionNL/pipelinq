<template>
	<div class="start-request-widget">
		<div v-if="!success" class="widget-form">
			<NcTextField
				v-model="form.title"
				:label="t('pipelinq', 'Title')"
				:placeholder="t('pipelinq', 'Request title (required)')"
				:error="submitted && !form.title"
				@keyup.enter="onSubmit" />

			<ClientAutocomplete
				:value="selectedClient"
				:placeholder="t('pipelinq', 'Search client…')"
				:label="t('pipelinq', 'Client')"
				@input="onClientSelected" />

			<NcSelect
				v-model="form.category"
				:options="categoryOptions"
				:inputLabel="t('pipelinq', 'Category')"
				:placeholder="t('pipelinq', 'Category')"
				inputId="request-category" />

			<NcSelect
				v-model="form.priority"
				:options="priorityOptions"
				:inputLabel="t('pipelinq', 'Priority')"
				:placeholder="t('pipelinq', 'Priority')"
				inputId="request-priority" />

			<NcSelect
				v-model="form.channel"
				:options="channelOptions"
				:inputLabel="t('pipelinq', 'Channel')"
				:placeholder="t('pipelinq', 'Channel')"
				inputId="request-channel" />

			<NcButton variant="primary" :disabled="submitting" @click="onSubmit">
				{{
					submitting
						? t('pipelinq', 'Creating…')
						: t('pipelinq', 'Create request')
				}}
			</NcButton>
		</div>

		<div v-else class="widget-success">
			<NcNoteCard type="success">
				{{ t('pipelinq', 'Request created!') }}
				<a :href="successLink">{{ t('pipelinq', 'View request') }}</a>
			</NcNoteCard>
			<NcButton variant="secondary" @click="resetForm">
				{{ t('pipelinq', 'Create another') }}
			</NcButton>
		</div>

		<div v-if="recentRequests.length > 0" class="recent-list">
			<h4>{{ t('pipelinq', 'Recent requests') }}</h4>
			<ul>
				<li v-for="req in recentRequests" :key="req.id">
					<a :href="generateUrl('/apps/pipelinq/tickets/' + req.id)">
						{{ toText(req.title) || t('pipelinq', 'Untitled') }}
					</a>
					<span class="recent-status">{{ req.status }}</span>
				</li>
			</ul>
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import ClientAutocomplete from '../../components/widgets/ClientAutocomplete.vue'
import { initializeStores } from '../../store/store.js'
import { toText } from '../../utils/widgetText.js'
import createWidgetMixin from './createWidgetMixin.js'
import { createWidgetObject, optionValue, readWidgetObjects, widgetObjectsUrl } from './widgetObjects.js'

/**
 * The request ticket to post: now, with the priority (normal when none),
 * and the client, category and channel when given.
 *
 * @param {object} form The widget form.
 * @param {?object} selectedClient The picked client.
 * @return {object} The ticket.
 */
function requestBody(form, selectedClient) {
	const body = {
		ticketType: 'request',
		title: form.title,
		status: 'new',
		priority:
			typeof form.priority === 'object'
				? form.priority.id
				: form.priority || 'normal',

		occurredAt: new Date().toISOString(),
	}

	if (selectedClient) {
		body.client = selectedClient.id
	}
	return { ...body, ...requestChoices(form) }
}

/**
 * @param {object} form The widget form.
 * @return {object} The category and channel of the request, each only when picked.
 */
function requestChoices(form) {
	const choices = {}
	if (form.category) {
		choices.category = optionValue(form.category)
	}
	if (form.channel) {
		choices.channel = optionValue(form.channel)
	}
	return choices
}

export default {
	name: 'StartRequestWidget',
	components: {
		NcTextField,
		NcButton,
		NcSelect,
		NcNoteCard,
		ClientAutocomplete,
	},

	mixins: [createWidgetMixin],

	data() {
		return {
			form: {
				title: '',
				category: null,
				priority: 'normal',
				channel: null,
			},

			recentRequests: [],
			categoryOptions: [
				{ id: 'general', label: t('pipelinq', 'General') },
				{ id: 'technical', label: t('pipelinq', 'Technical') },
				{ id: 'billing', label: t('pipelinq', 'Billing') },
				{ id: 'support', label: t('pipelinq', 'Support') },
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

	/**
	 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-53
	 */
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
		generateUrl,
		toText,
		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-55
		 */
		async onSubmit() {
			this.submitted = true
			if (!this.form.title) {
				return
			}
			// A request is a `ticket` with ticketType 'request'; `requestedAt` became
			// `occurredAt` (unify-ticket-supertype).
			if (!this.config?.ticket) {
				console.error('Ticket schema not configured')
				return
			}

			this.submitting = true
			try {
				const id = await createWidgetObject(this.config.ticket, {
					body: requestBody(this.form, this.selectedClient),
					failure: 'Failed to create request',
				})
				this.successLink = generateUrl('/apps/pipelinq/tickets/' + id)
				this.success = true
			} catch (err) {
				console.error('StartRequestWidget create error:', err)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-56
		 */
		resetForm() {
			this.form = {
				title: '',
				category: null,
				priority: 'normal',
				channel: null,
			}
			this.selectedClient = null
			this.submitted = false
			this.success = false
			this.successLink = ''
			this.fetchRecentRequests()
		},

		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-52
		 */
		async fetchRecentRequests() {
			if (!this.config?.ticket) return
			try {
				// Narrow the shared `ticket` supertype to request-tickets so complaints
				// and contactmomenten don't show up as "recent requests".
				const params = new URLSearchParams({
					ticketType: 'request',
					_limit: '3',
					_order: 'desc',
				})
				const requests = await readWidgetObjects(widgetObjectsUrl(this.config.ticket, '?' + params.toString()))
				if (requests === null) return
				this.recentRequests = requests.slice(0, 3)
			} catch (err) {
				console.error('Failed to fetch recent requests:', err)
			}
		},
	},
}
</script>

<style scoped src="./createWidget.css"></style>

<style scoped>
.start-request-widget {
	padding: 12px 16px;
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
	margin-inline-start: 8px;
}
</style>
