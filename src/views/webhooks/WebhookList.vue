<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<div>
		<div class="webhook-list">
			<div class="webhook-list__header">
				<h2>{{ t('pipelinq', 'Webhooks') }}</h2>
				<p>{{ t('pipelinq', 'Outgoing webhook subscriptions delivered via OpenRegister WebhookService') }}</p>
			</div>

			<NcLoadingIcon v-if="loading" />

			<table v-else-if="rows.length > 0" class="webhook-list__table">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'URL') }}</th>
						<th>{{ t('pipelinq', 'Events') }}</th>
						<th>{{ t('pipelinq', 'Active') }}</th>
						<th>{{ t('pipelinq', 'Last fired') }}</th>
						<th>{{ t('pipelinq', 'Actions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in rows" :key="row.id">
						<td>{{ truncateUrl(row.url) }}</td>
						<td>{{ formatEvents(row.events) }}</td>
						<td>
							<CnStatusBadge :status="row.isEnabled ? 'active' : 'inactive'"
								:label="row.isEnabled ? t('pipelinq', 'Active') : t('pipelinq', 'Inactive')" />
						</td>
						<td>{{ row.lastFiredAt || '-' }}</td>
						<td class="webhook-list__actions">
							<NcButton type="secondary" :aria-label="t('pipelinq', 'Test webhook')" @click="testWebhook(row)">
								{{ t('pipelinq', 'Test') }}
							</NcButton>
							<NcButton type="tertiary" :aria-label="t('pipelinq', 'Delete webhook')" @click="askDelete(row)">
								{{ t('pipelinq', 'Delete') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>

			<CnEmptyState v-else
				:title="t('pipelinq', 'No webhooks yet')"
				:description="t('pipelinq', 'Add a webhook subscription to push CRM events to an external system.')" />
		</div>

		<NcDialog v-if="testResult"
			:name="t('pipelinq', 'Webhook test result')"
			:open="!!testResult"
			@closing="testResult = ''">
			<p>{{ testResult }}</p>
		</NcDialog>

		<NcDialog v-if="pendingDelete"
			:name="t('pipelinq', 'Delete webhook')"
			:open="!!pendingDelete"
			@closing="pendingDelete = null">
			<p>{{ t('pipelinq', 'Delete this webhook subscription?') }}</p>
			<template #actions>
				<NcButton @click="pendingDelete = null">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton type="error" @click="confirmDelete">
					{{ t('pipelinq', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { CnEmptyState, CnStatusBadge } from '@conduction/nextcloud-vue'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'WebhookList',
	components: {
		CnEmptyState,
		CnStatusBadge,
		NcButton,
		NcDialog,
		NcLoadingIcon,
	},
	data() {
		return {
			rows: [],
			loading: true,
			testResult: '',
			pendingDelete: null,
		}
	},
	mounted() {
		this.fetchRows()
	},
	methods: {
		/**
		 * Load all webhooks from the backend.
		 *
		 * @return {Promise<void>}
		 */
		async fetchRows() {
			this.loading = true
			try {
				const res = await axios.get(generateUrl('/apps/pipelinq/api/webhooks'))
				this.rows = (res.data && res.data.results) || []
			} catch (_e) {
				this.testResult = this.t('pipelinq', 'Failed to load webhooks')
			} finally {
				this.loading = false
			}
		},
		/**
		 * Fire a test event against a webhook subscription.
		 *
		 * @param {object} row The webhook row.
		 * @return {Promise<void>}
		 */
		async testWebhook(row) {
			try {
				const res = await axios.post(generateUrl(`/apps/pipelinq/api/webhooks/${row.id}/test`))
				const ok = !!(res.data && res.data.delivered)
				this.testResult = ok
					? this.t('pipelinq', 'Test event delivered successfully.')
					: this.t('pipelinq', 'Test event delivery failed.')
			} catch (_e) {
				this.testResult = this.t('pipelinq', 'Test event delivery failed.')
			}
		},
		/**
		 * Open the delete confirmation for a webhook.
		 *
		 * @param {object} row The webhook row.
		 */
		askDelete(row) {
			this.pendingDelete = row
		},
		/**
		 * Confirm and execute the delete.
		 *
		 * @return {Promise<void>}
		 */
		async confirmDelete() {
			if (!this.pendingDelete) {
				return
			}
			const id = this.pendingDelete.id
			this.pendingDelete = null
			try {
				await axios.delete(generateUrl(`/apps/pipelinq/api/webhooks/${id}`))
				await this.fetchRows()
			} catch (_e) {
				this.testResult = this.t('pipelinq', 'Failed to delete webhook')
			}
		},
		/**
		 * Truncate a long URL for table display.
		 *
		 * @param {string} url The URL.
		 * @return {string} Truncated form.
		 */
		truncateUrl(url) {
			if (!url) {
				return '-'
			}
			return url.length > 60 ? url.slice(0, 57) + '…' : url
		},
		/**
		 * Stringify an events array for display.
		 *
		 * @param {Array<string>|string|null} events The events list.
		 * @return {string}
		 */
		formatEvents(events) {
			if (!events) {
				return '-'
			}
			if (Array.isArray(events)) {
				return events.join(', ')
			}
			return String(events)
		},
	},
}
</script>

<style scoped>
.webhook-list {
	padding: 16px;
}
.webhook-list__table {
	width: 100%;
	border-collapse: collapse;
}
.webhook-list__table th,
.webhook-list__table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}
.webhook-list__actions {
	display: flex;
	gap: 6px;
}
</style>
