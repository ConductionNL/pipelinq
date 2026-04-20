<template>
	<div class="email-timeline">
		<div class="timeline-header">
			<h3>{{ t('pipelinq', 'Email History') }}</h3>
			<span v-if="emails.length > 0" class="email-count">{{ emails.length }}</span>
		</div>

		<div v-if="loading" class="loading">
			<NcLoadingIcon />
		</div>

		<div v-else-if="emails.length === 0" class="empty-state">
			<p>{{ t('pipelinq', 'No emails linked to this entity yet.') }}</p>
		</div>

		<div v-else class="timeline">
			<div
				v-for="email in sortedEmails"
				:key="email.messageId"
				class="timeline-item"
				:class="{ 'is-outbound': email.direction === 'outbound' }">
				<div class="timeline-marker">
					<Mail :size="16" />
				</div>

				<div class="timeline-content">
					<div class="email-header">
						<div class="email-from">
							<strong>{{ email.sender }}</strong>
							<span class="email-direction">{{ directionLabel(email.direction) }}</span>
						</div>
						<div class="email-date">
							{{ formatDate(email.date) }}
						</div>
					</div>

					<div class="email-subject">
						{{ email.subject }}
					</div>

					<div class="email-recipients" v-if="email.recipients && email.recipients.length > 0">
						<span class="label">{{ t('pipelinq', 'To:') }}</span>
						<span>{{ email.recipients.join(', ') }}</span>
					</div>

					<div class="email-actions">
						<NcButton
							v-if="!email.excluded"
							type="tertiary"
							size="small"
							@click="excludeEmail(email.messageId)">
							{{ t('pipelinq', 'Exclude') }}
						</NcButton>
						<NcButton
							v-else
							type="tertiary"
							size="small"
							@click="includeEmail(email.messageId)">
							{{ t('pipelinq', 'Include') }}
						</NcButton>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcLoadingIcon, NcButton } from '@nextcloud/vue'
import Mail from 'vue-material-design-icons/Mail.vue'

export default {
	name: 'EmailTimeline',
	components: {
		NcLoadingIcon,
		NcButton,
		Mail,
	},
	props: {
		entityType: {
			type: String,
			required: true,
		},
		entityId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			emails: [],
			loading: false,
		}
	},
	computed: {
		sortedEmails() {
			return [...this.emails].sort((a, b) => {
				const dateA = new Date(a.date).getTime()
				const dateB = new Date(b.date).getTime()
				return dateB - dateA
			})
		},
	},
	mounted() {
		this.fetchEmails()
	},
	watch: {
		entityId() {
			this.fetchEmails()
		},
	},
	methods: {
		async fetchEmails() {
			this.loading = true
			try {
				const response = await fetch(
					`/apps/pipelinq/api/sync/emails?entityType=${encodeURIComponent(this.entityType)}&entityId=${encodeURIComponent(this.entityId)}`,
					{
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
					}
				)
				if (response.ok === true) {
					const data = await response.json()
					this.emails = data.emails || []
				}
			} catch (error) {
				console.error('Failed to fetch emails', error)
			} finally {
				this.loading = false
			}
		},
		formatDate(dateString) {
			const date = new Date(dateString)
			return date.toLocaleDateString(undefined, {
				year: 'numeric',
				month: 'short',
				day: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},
		directionLabel(direction) {
			return direction === 'outbound'
				? this.t('pipelinq', 'Sent')
				: this.t('pipelinq', 'Received')
		},
		async excludeEmail(messageId) {
			try {
				const response = await fetch(`/apps/pipelinq/api/sync/emails/${encodeURIComponent(messageId)}`, {
					method: 'PATCH',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify({ excluded: true }),
				})
				if (response.ok !== true) {
					return
				}
				const emailIndex = this.emails.findIndex(e => e.messageId === messageId)
				if (emailIndex >= 0) {
					this.emails[emailIndex].excluded = true
				}
			} catch (error) {
				console.error('Failed to exclude email', error)
			}
		},
		async includeEmail(messageId) {
			try {
				const response = await fetch(`/apps/pipelinq/api/sync/emails/${encodeURIComponent(messageId)}`, {
					method: 'PATCH',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify({ excluded: false }),
				})
				if (response.ok !== true) {
					return
				}
				const emailIndex = this.emails.findIndex(e => e.messageId === messageId)
				if (emailIndex >= 0) {
					this.emails[emailIndex].excluded = false
				}
			} catch (error) {
				console.error('Failed to include email', error)
			}
		},
	},
}
</script>

<style scoped>
.email-timeline {
	margin: 20px 0;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	overflow: hidden;
}

.timeline-header {
	background: var(--color-background-secondary);
	padding: 15px;
	border-bottom: 1px solid var(--color-border);
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.timeline-header h3 {
	margin: 0;
	font-size: 1em;
	color: var(--color-main-text);
}

.email-count {
	background: var(--color-primary);
	color: white;
	border-radius: 12px;
	padding: 2px 8px;
	font-size: 0.85em;
	font-weight: 600;
}

.loading {
	display: flex;
	justify-content: center;
	align-items: center;
	padding: 40px;
}

.empty-state {
	padding: 40px 20px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.timeline {
	position: relative;
	padding: 20px;
}

.timeline::before {
	content: '';
	position: absolute;
	left: 35px;
	top: 0;
	bottom: 0;
	width: 2px;
	background: var(--color-border);
}

.timeline-item {
	position: relative;
	margin-bottom: 20px;
	padding-left: 70px;
}

.timeline-item:last-child {
	margin-bottom: 0;
}

.timeline-marker {
	position: absolute;
	left: 15px;
	top: 0;
	width: 40px;
	height: 40px;
	background: var(--color-main-background);
	border: 2px solid var(--color-primary);
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--color-primary);
}

.timeline-item.is-outbound .timeline-marker {
	border-color: var(--color-success);
	color: var(--color-success);
}

.timeline-content {
	background: var(--color-background-secondary);
	border: 1px solid var(--color-border);
	border-radius: 4px;
	padding: 12px;
}

.email-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 8px;
}

.email-from {
	display: flex;
	align-items: center;
	gap: 8px;
}

.email-from strong {
	color: var(--color-main-text);
	word-break: break-word;
}

.email-direction {
	background: var(--color-background-tertiary);
	color: var(--color-text-maxcontrast);
	padding: 2px 6px;
	border-radius: 3px;
	font-size: 0.8em;
	white-space: nowrap;
}

.email-date {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	white-space: nowrap;
}

.email-subject {
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 8px;
	word-break: break-word;
}

.email-recipients {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-bottom: 10px;
}

.email-recipients .label {
	font-weight: 600;
	margin-right: 4px;
}

.email-actions {
	margin-top: 10px;
	display: flex;
	gap: 8px;
}
</style>
