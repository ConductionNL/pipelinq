<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->

<!--
  Submission timeline component for posJournalEntryOutbound.

  Renders all submission attempts in reverse chronological order, showing
  timestamp, HTTP status code (colour-coded), response message, and CloudEvent
  ID on success. Also shows the next retry scheduled time when the outbound
  message status is 'failed' and nextRetryAt is set.

  @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#5.3
-->
<template>
	<div class="submission-timeline">
		<h3 class="submission-timeline__title">
			{{ t('pipelinq', 'Indieningshistorie') }}
		</h3>

		<!-- Next retry indicator -->
		<div v-if="nextRetryAt" class="submission-timeline__retry-notice">
			<span class="submission-timeline__retry-icon">⏰</span>
			{{ t('pipelinq', 'Volgende herpoging gepland op:') }}
			<strong>{{ formatDateTime(nextRetryAt) }}</strong>
		</div>

		<!-- Empty state -->
		<p v-if="!sortedAttempts.length" class="submission-timeline__empty">
			{{ t('pipelinq', 'Nog geen indieningspogingen') }}
		</p>

		<!-- Timeline entries -->
		<ol class="submission-timeline__list">
			<li
				v-for="(attempt, index) in sortedAttempts"
				:key="index"
				class="submission-timeline__entry">
				<div class="submission-timeline__connector" aria-hidden="true" />
				<div class="submission-timeline__content">
					<!-- Timestamp -->
					<time
						class="submission-timeline__timestamp"
						:datetime="attempt.timestamp">
						{{ formatDateTime(attempt.timestamp) }}
					</time>

					<!-- Status badge -->
					<span
						class="submission-timeline__status-badge"
						:class="statusClass(attempt.status)">
						{{ attempt.status || '—' }}
					</span>

					<!-- Message -->
					<span class="submission-timeline__message">
						{{ translatedMessage(attempt.message) }}
					</span>

					<!-- CloudEvent ID on success -->
					<span
						v-if="attempt.eventId"
						class="submission-timeline__event-id"
						:title="attempt.eventId">
						{{ t('pipelinq', 'Event-ID:') }} {{ attempt.eventId }}
					</span>
				</div>
			</li>
		</ol>
	</div>
</template>

<script>
/**
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#5.3
 */
export default {
	name: 'SubmissionTimeline',

	props: {
		/**
		 * Array of submission attempt objects from posJournalEntryOutbound.submissionAttempts.
		 * Each entry: { timestamp, status, message, eventId? }
		 *
		 * @type {Array<{timestamp: string, status: number, message: string, eventId?: string}>}
		 */
		attempts: {
			type: Array,
			default: () => [],
		},

		/**
		 * ISO 8601 datetime for the next scheduled retry attempt, or null.
		 *
		 * @type {string|null}
		 */
		nextRetryAt: {
			type: String,
			default: null,
		},
	},

	computed: {
		/**
		 * Attempts sorted in reverse chronological order (most recent first).
		 *
		 * @return {Array<object>} Sorted attempt list.
		 */
		sortedAttempts() {
			return [...(this.attempts || [])].sort((a, b) => {
				return new Date(b.timestamp).getTime() - new Date(a.timestamp).getTime()
			})
		},
	},

	methods: {
		/**
		 * Format an ISO 8601 datetime string to a readable Dutch locale format.
		 *
		 * @param {string} isoString The ISO 8601 datetime string.
		 * @return {string} Formatted datetime, e.g. "20-05-2026 23:59:30".
		 */
		formatDateTime(isoString) {
			if (!isoString) return '—'
			try {
				return new Date(isoString).toLocaleString('nl-NL', {
					day: '2-digit',
					month: '2-digit',
					year: 'numeric',
					hour: '2-digit',
					minute: '2-digit',
					second: '2-digit',
				})
			} catch {
				return isoString
			}
		},

		/**
		 * Return a CSS class for the HTTP status badge based on the status code family.
		 * 2xx = success (green), 4xx = client error (red), 5xx = server error (orange),
		 * 0 = network timeout (orange), other = neutral (gray).
		 *
		 * @param {number} status The HTTP status code.
		 * @return {string} CSS class string.
		 */
		statusClass(status) {
			const code = parseInt(status, 10)
			if (code >= 200 && code < 300) return 'submission-timeline__badge--success'
			if (code >= 400 && code < 500) return 'submission-timeline__badge--error'
			if (code >= 500 || code === 0) return 'submission-timeline__badge--warning'
			return 'submission-timeline__badge--neutral'
		},

		/**
		 * Translate a known Shillinq response message to Dutch when available.
		 *
		 * @param {string} message The raw message from the submission attempt.
		 * @return {string} Translated or original message.
		 */
		translatedMessage(message) {
			const translations = {
				Accepted: this.t('pipelinq', 'Geaccepteerd'),
				'Service Unavailable': this.t('pipelinq', 'Service niet beschikbaar'),
				'Bad Request': this.t('pipelinq', 'Ongeldige aanvraag'),
				Unauthorized: this.t('pipelinq', 'Niet geautoriseerd'),
				'Unprocessable Entity': this.t('pipelinq', 'Onverwerkbare entiteit'),
				'Internal Server Error': this.t('pipelinq', 'Interne serverfout'),
				NETWORK_TIMEOUT: this.t('pipelinq', 'Netwerktime-out'),
			}
			return translations[message] ?? message ?? '—'
		},
	},
}
</script>

<style scoped>
.submission-timeline {
	padding: var(--default-grid-baseline, 4px) 0;
}

.submission-timeline__title {
	font-weight: 600;
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 2);
}

.submission-timeline__retry-notice {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 4px);
	padding: calc(var(--default-grid-baseline, 4px) * 2);
	background: var(--color-warning-bg, #fff3cd);
	border-left: 3px solid var(--color-warning, #e6a817);
	border-radius: var(--border-radius, 3px);
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 2);
	font-size: 0.9em;
}

.submission-timeline__empty {
	color: var(--color-text-lighter, #999);
	font-style: italic;
}

.submission-timeline__list {
	list-style: none;
	padding: 0;
	margin: 0;
	position: relative;
}

.submission-timeline__list::before {
	content: '';
	position: absolute;
	left: 12px;
	top: 0;
	bottom: 0;
	width: 2px;
	background: var(--color-border, #ddd);
}

.submission-timeline__entry {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	padding: calc(var(--default-grid-baseline, 4px) * 2) 0;
	padding-left: calc(var(--default-grid-baseline, 4px) * 7);
	position: relative;
}

.submission-timeline__connector {
	position: absolute;
	left: 8px;
	top: 20px;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: var(--color-primary, #0082c9);
	border: 2px solid white;
	box-shadow: 0 0 0 2px var(--color-primary, #0082c9);
}

.submission-timeline__content {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 1.5);
	font-size: 0.9em;
}

.submission-timeline__timestamp {
	color: var(--color-text-lighter, #666);
	min-width: 160px;
}

.submission-timeline__status-badge {
	padding: 2px 8px;
	border-radius: 12px;
	font-weight: 600;
	font-size: 0.85em;
	min-width: 40px;
	text-align: center;
}

.submission-timeline__badge--success {
	background: var(--color-success-bg, #d4edda);
	color: var(--color-success, #155724);
}

.submission-timeline__badge--error {
	background: var(--color-error-bg, #f8d7da);
	color: var(--color-error, #721c24);
}

.submission-timeline__badge--warning {
	background: var(--color-warning-bg, #fff3cd);
	color: var(--color-warning-text, #856404);
}

.submission-timeline__badge--neutral {
	background: var(--color-background-dark, #f5f5f5);
	color: var(--color-text-light, #555);
}

.submission-timeline__message {
	flex: 1;
}

.submission-timeline__event-id {
	font-size: 0.8em;
	color: var(--color-text-lighter, #888);
	font-family: monospace;
	max-width: 300px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
</style>
