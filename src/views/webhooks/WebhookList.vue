<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<div class="webhook-list">
		<div class="webhook-list__header">
			<h2>{{ t('pipelinq', 'Webhooks') }}</h2>
			<NcButton type="primary" @click="openCreate">
				{{ t('pipelinq', 'Add webhook') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" />

		<NcEmptyContent
			v-else-if="webhooks.length === 0"
			:name="t('pipelinq', 'No webhooks yet')"
			:description="t('pipelinq', 'Create a webhook to receive CRM events.')" />

		<table v-else class="webhook-table">
			<thead>
				<tr>
					<th>{{ t('pipelinq', 'URL') }}</th>
					<th>{{ t('pipelinq', 'Events') }}</th>
					<th>{{ t('pipelinq', 'Status') }}</th>
					<th>{{ t('pipelinq', 'Last fired') }}</th>
					<th>{{ t('pipelinq', 'Actions') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="webhook in webhooks" :key="webhook.id">
					<td class="mono">
						{{ truncate(webhook.url) }}
					</td>
					<td>{{ eventSummary(webhook.events) }}</td>
					<td>
						<CnStatusBadge
							:status="webhook.enabled ? 'active' : 'inactive'"
							:label="webhook.enabled ? t('pipelinq', 'Active') : t('pipelinq', 'Inactive')" />
					</td>
					<td>{{ formatDate(webhook.lastTriggeredAt) }}</td>
					<td class="actions-cell">
						<NcButton type="secondary" :disabled="busyId === webhook.id" @click="testWebhook(webhook)">
							{{ t('pipelinq', 'Test') }}
						</NcButton>
						<NcButton type="error" :disabled="busyId === webhook.id" @click="confirmDelete(webhook)">
							{{ t('pipelinq', 'Delete') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<NcDialog
			v-if="showCreate"
			:name="t('pipelinq', 'Add webhook')"
			@closing="showCreate = false">
			<div class="create-form">
				<NcTextField
					:value.sync="newWebhook.name"
					:label="t('pipelinq', 'Name')" />
				<NcTextField
					:value.sync="newWebhook.url"
					:label="t('pipelinq', 'URL')"
					:placeholder="t('pipelinq', 'https://...')" />
				<NcSelect
					v-model="newWebhook.events"
					:options="eventOptions"
					:multiple="true"
					:input-label="t('pipelinq', 'Events')" />
			</div>
			<template #actions>
				<NcButton @click="showCreate = false">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="creating || !newWebhook.url" @click="createWebhook">
					{{ t('pipelinq', 'Create') }}
				</NcButton>
			</template>
		</NcDialog>

		<NcDialog
			v-if="testResult !== null"
			:name="t('pipelinq', 'Test result')"
			@closing="testResult = null">
			<p :class="testResult.success ? 'result-ok' : 'result-fail'">
				{{ testResult.message }}
			</p>
			<template #actions>
				<NcButton type="primary" @click="testResult = null">
					{{ t('pipelinq', 'Close') }}
				</NcButton>
			</template>
		</NcDialog>

		<NcDialog
			v-if="deleteTarget !== null"
			:name="t('pipelinq', 'Delete webhook')"
			@closing="deleteTarget = null">
			<p>{{ t('pipelinq', 'Are you sure you want to delete this webhook?') }}</p>
			<template #actions>
				<NcButton @click="deleteTarget = null">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton type="error" @click="deleteWebhook">
					{{ t('pipelinq', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import { NcButton, NcDialog, NcEmptyContent, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'

const EVENT_OPTIONS = [
	'lead_created',
	'lead_stage_changed',
	'lead_assigned',
	'contact_created',
	'request_created',
	'request_status_changed',
	'marketing_segment_match',
]

export default {
	name: 'WebhookList',
	components: {
		CnStatusBadge,
		NcButton,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
	},
	data() {
		return {
			webhooks: [],
			loading: true,
			creating: false,
			busyId: null,
			showCreate: false,
			testResult: null,
			deleteTarget: null,
			newWebhook: {
				name: '',
				url: '',
				events: [],
			},
			eventOptions: EVENT_OPTIONS,
		}
	},
	async created() {
		await this.load()
	},
	methods: {
		/**
		 * Load the webhook subscriptions.
		 */
		async load() {
			this.loading = true
			try {
				const response = await axios.get(generateUrl('/apps/pipelinq/api/webhooks'))
				this.webhooks = (response.data && response.data.results) || []
			} catch (e) {
				showError(this.t('pipelinq', 'Failed to load webhooks.'))
			} finally {
				this.loading = false
			}
		},
		/**
		 * Open the create dialog.
		 */
		openCreate() {
			this.newWebhook = { name: '', url: '', events: [] }
			this.showCreate = true
		},
		/**
		 * Create a webhook subscription.
		 */
		async createWebhook() {
			this.creating = true
			try {
				await axios.post(generateUrl('/apps/pipelinq/api/webhooks'), {
					name: this.newWebhook.name,
					url: this.newWebhook.url,
					events: this.newWebhook.events,
					enabled: false,
				})
				showSuccess(this.t('pipelinq', 'Webhook created.'))
				this.showCreate = false
				await this.load()
			} catch (e) {
				showError(this.t('pipelinq', 'Failed to create webhook.'))
			} finally {
				this.creating = false
			}
		},
		/**
		 * Fire a test event to a webhook and show the result.
		 *
		 * @param {object} webhook The webhook to test.
		 */
		async testWebhook(webhook) {
			this.busyId = webhook.id
			try {
				const response = await axios.post(generateUrl(`/apps/pipelinq/api/webhooks/${webhook.id}/test`))
				this.testResult = {
					success: Boolean(response.data && response.data.success),
					message: (response.data && response.data.message) || this.t('pipelinq', 'Test completed.'),
				}
			} catch (e) {
				this.testResult = {
					success: false,
					message: this.t('pipelinq', 'Test webhook delivery failed.'),
				}
			} finally {
				this.busyId = null
			}
		},
		/**
		 * Open the delete confirmation dialog.
		 *
		 * @param {object} webhook The webhook to delete.
		 */
		confirmDelete(webhook) {
			this.deleteTarget = webhook
		},
		/**
		 * Delete the targeted webhook.
		 */
		async deleteWebhook() {
			const target = this.deleteTarget
			this.deleteTarget = null
			if (!target) {
				return
			}
			this.busyId = target.id
			try {
				await axios.delete(generateUrl(`/apps/pipelinq/api/webhooks/${target.id}`))
				showSuccess(this.t('pipelinq', 'Webhook deleted.'))
				await this.load()
			} catch (e) {
				showError(this.t('pipelinq', 'Failed to delete webhook.'))
			} finally {
				this.busyId = null
			}
		},
		/**
		 * Truncate a long URL for display.
		 *
		 * @param {string} url The webhook URL.
		 * @return {string} The truncated URL.
		 */
		truncate(url) {
			if (!url) {
				return '-'
			}
			return url.length > 48 ? `${url.slice(0, 45)}...` : url
		},
		/**
		 * Summarize the subscribed events.
		 *
		 * @param {string|Array} events The stored events.
		 * @return {string} A short summary.
		 */
		eventSummary(events) {
			let list = events
			if (typeof events === 'string') {
				try {
					list = JSON.parse(events)
				} catch (e) {
					list = events.split(',')
				}
			}
			if (!Array.isArray(list) || list.length === 0) {
				return '-'
			}
			return list.join(', ')
		},
		/**
		 * Format an ISO date for display.
		 *
		 * @param {string} value The ISO date string.
		 * @return {string} The display date.
		 */
		formatDate(value) {
			if (!value) {
				return this.t('pipelinq', 'Never')
			}
			return new Date(value).toLocaleString()
		},
	},
}
</script>

<style scoped>
.webhook-list {
	padding: 16px;
}
.webhook-list__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}
.webhook-table {
	width: 100%;
	border-collapse: collapse;
}
.webhook-table th,
.webhook-table td {
	text-align: left;
	padding: 8px 10px;
	border-bottom: 1px solid var(--color-border);
}
.webhook-table th {
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}
.actions-cell {
	display: flex;
	gap: 8px;
}
.create-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-width: 380px;
}
.mono {
	font-family: monospace;
	font-size: 0.9em;
}
.result-ok {
	color: var(--color-success);
}
.result-fail {
	color: var(--color-error);
}
</style>
