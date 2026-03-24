<template>
	<div class="email-timeline">
		<h3>{{ t('pipelinq', 'Email History') }}</h3>

		<NcLoadingIcon v-if="loading" />

		<div v-else-if="emails.length === 0" class="email-timeline__empty">
			<p>{{ t('pipelinq', 'No emails linked to this entity') }}</p>
		</div>

		<div v-else class="email-timeline__list">
			<div
				v-for="email in groupedEmails"
				:key="email.id || email.messageId"
				class="email-item"
				:class="'email-item--' + email.direction">
				<div class="email-item__icon">
					<span v-if="email.direction === 'inbound'" class="direction-icon">&#x2199;</span>
					<span v-else class="direction-icon">&#x2197;</span>
				</div>
				<div class="email-item__content">
					<div class="email-item__header">
						<span class="email-item__subject">{{ email.subject || t('pipelinq', '(no subject)') }}</span>
						<span class="email-item__date">{{ formatDate(email.date) }}</span>
					</div>
					<div class="email-item__meta">
						<span>{{ email.direction === 'inbound' ? t('pipelinq', 'From') : t('pipelinq', 'To') }}: {{ email.sender }}</span>
					</div>
					<div v-if="email.deleted" class="email-item__deleted">
						{{ t('pipelinq', 'Email deleted from mailbox') }}
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'EmailTimeline',
	components: { NcLoadingIcon },
	props: {
		entityType: { type: String, required: true },
		entityId: { type: String, required: true },
	},
	data() {
		return {
			emails: [],
			loading: false,
		}
	},
	computed: {
		groupedEmails() {
			// Group by thread ID, show latest first
			return [...this.emails].sort((a, b) =>
				new Date(b.date) - new Date(a.date),
			)
		},
	},
	mounted() { this.fetchEmails() },
	methods: {
		async fetchEmails() {
			this.loading = true
			try { this.emails = [] } finally { this.loading = false }
		},
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleString('nl-NL', {
				day: '2-digit',
				month: '2-digit',
				year: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},
	},
}
</script>

<style scoped>
.email-timeline { margin-top: 20px; }

.email-timeline__empty { padding: 16px; text-align: center; color: var(--color-text-lighter); }

.email-timeline__list { display: flex; flex-direction: column; gap: 4px; }

.email-item { display: flex; gap: 12px; padding: 10px 12px; border-radius: var(--border-radius); border-left: 3px solid transparent; }

.email-item--inbound { border-left-color: var(--color-primary-element); }

.email-item--outbound { border-left-color: var(--color-success); }

.email-item:hover { background: var(--color-background-hover); }

.email-item__icon { font-size: 1.2em; width: 24px; text-align: center; }

.email-item__content { flex: 1; min-width: 0; }

.email-item__header { display: flex; justify-content: space-between; gap: 12px; }

.email-item__subject { font-weight: 600; font-size: 0.9em; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.email-item__date { font-size: 0.8em; color: var(--color-text-lighter); white-space: nowrap; }

.email-item__meta { font-size: 0.8em; color: var(--color-text-lighter); margin-top: 2px; }

.email-item__deleted { font-size: 0.8em; color: var(--color-warning); font-style: italic; margin-top: 4px; }
</style>
