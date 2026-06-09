<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Shillinq AP card on the expense detail view (REQ-AP-006).
  Visible only when expense.apSyncStatus is not null. Shows a coloured status
  badge plus, depending on state, a synced timestamp, a pending message, or
  an "Opnieuw versturen" retry button that calls the retry endpoint.
-->
<template>
	<div v-if="hasSyncState" class="shillinq-ap-card">
		<div class="shillinq-ap-card__header">
			<h3>{{ t('pipelinq', 'Shillinq AP') }}</h3>
			<span class="ap-status-badge" :style="badgeStyle">{{ badgeLabel }}</span>
		</div>

		<div v-if="expense.apSyncStatus === 'synced'" class="shillinq-ap-card__body">
			<p>{{ t('pipelinq', 'Gesynchroniseerd op {date}', { date: formattedTimestamp }) }}</p>
		</div>

		<div v-else-if="expense.apSyncStatus === 'pending'" class="shillinq-ap-card__body">
			<p>{{ t('pipelinq', 'Verzending in progress, moment geduld a.u.b.') }}</p>
		</div>

		<div v-else-if="expense.apSyncStatus === 'failed'" class="shillinq-ap-card__body">
			<NcButton type="primary"
				:disabled="retrying"
				@click="onRetry">
				<template #icon>
					<NcLoadingIcon v-if="retrying" :size="16" />
				</template>
				{{ t('pipelinq', 'Opnieuw versturen') }}
			</NcButton>
			<NcNoteCard v-if="message" :type="messageType">
				{{ message }}
			</NcNoteCard>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'ExpenseShillinqApCard',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},
	props: {
		expense: {
			type: Object,
			required: true,
		},
	},
	emits: ['updated'],
	data() {
		return {
			retrying: false,
			message: '',
			messageType: 'success',
		}
	},
	computed: {
		hasSyncState() {
			return this.expense
				&& this.expense.apSyncStatus
				&& ['synced', 'pending', 'failed'].includes(this.expense.apSyncStatus)
		},
		/**
		 * Inline style for the badge. REQ-AP-006.
		 *
		 * @return {object} The inline style object.
		 */
		badgeStyle() {
			const colors = {
				synced: '#28a745',
				pending: '#ffc107',
				failed: '#dc3545',
			}
			return {
				backgroundColor: colors[this.expense.apSyncStatus] || '#6c757d',
				color: this.expense.apSyncStatus === 'pending' ? '#212529' : '#ffffff',
				padding: '2px 10px',
				borderRadius: '12px',
				fontSize: '0.9em',
				fontWeight: '500',
				display: 'inline-block',
			}
		},
		/**
		 * Localised badge label. REQ-AP-006.
		 *
		 * @return {string} The label.
		 */
		badgeLabel() {
			if (this.expense.apSyncStatus === 'synced') {
				return t('pipelinq', 'AP gesynchroniseerd')
			}
			if (this.expense.apSyncStatus === 'pending') {
				return t('pipelinq', 'AP in behandeling')
			}
			if (this.expense.apSyncStatus === 'failed') {
				return t('pipelinq', 'AP mislukt')
			}
			return '–'
		},
		/**
		 * Human-readable Dutch timestamp, e.g. "15 mei 2026 om 14:35 uur". REQ-AP-006 Scenario 17.
		 *
		 * @return {string} The formatted timestamp.
		 */
		formattedTimestamp() {
			const raw = this.expense.apSyncedAt
			if (!raw) {
				return '–'
			}
			try {
				const d = new Date(raw)
				const date = d.toLocaleDateString('nl-NL', { day: 'numeric', month: 'long', year: 'numeric' })
				const time = d.toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' })
				return `${date} om ${time} uur`
			} catch (e) {
				return raw
			}
		},
	},
	methods: {
		/**
		 * Trigger the manual retry endpoint. REQ-AP-006 Scenario 18 + REQ-AP-003 Scenario 11.
		 *
		 * @return {Promise<void>}
		 */
		async onRetry() {
			this.retrying = true
			this.message = ''
			try {
				const url = generateUrl(`/apps/pipelinq/api/expenses/${this.expense.id}/shillinq-ap/retry`)
				const { data } = await axios.post(url)
				this.message = t('pipelinq', 'Retry started.')
				this.messageType = data.apSyncStatus === 'failed' ? 'error' : 'success'
				this.$emit('updated', data)
			} catch (e) {
				this.message = e.response?.data?.error || t('pipelinq', 'Retry failed.')
				this.messageType = 'error'
			} finally {
				this.retrying = false
			}
		},
	},
}
</script>

<style scoped>
.shillinq-ap-card {
	border: 1px solid var(--color-border, #ddd);
	border-radius: 8px;
	padding: 16px;
	margin-top: 16px;
	background-color: var(--color-main-background, #fff);
}

.shillinq-ap-card__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.shillinq-ap-card__header h3 {
	margin: 0;
	font-size: 1.1em;
}

.shillinq-ap-card__body {
	margin-top: 8px;
}

.ap-status-badge {
	white-space: nowrap;
}
</style>
