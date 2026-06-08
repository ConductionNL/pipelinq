<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Vertical timeline of submission attempts for a posJournalEntryOutbound:
  - reverse chronological order, status-coloured badges, optional CloudEvents
  - id, expandable raw message and the scheduled next-retry timestamp.
  -
  - @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#5.3
  -->
<template>
	<section class="submission-timeline" data-testid="submission-timeline">
		<h3 class="submission-timeline__title">
			{{ t('pipelinq', 'Submission timeline') }}
		</h3>

		<p v-if="!sortedAttempts.length" class="submission-timeline__empty">
			{{ t('pipelinq', 'Nog geen submission pogingen gelogd.') }}
		</p>

		<ol v-else class="submission-timeline__list">
			<li
				v-for="(attempt, idx) in sortedAttempts"
				:key="attempt.timestamp + '-' + idx"
				class="submission-timeline__entry">
				<span class="submission-timeline__when">
					{{ formatStamp(attempt.timestamp) }}
				</span>
				<span
					class="submission-timeline__badge"
					:class="statusClass(attempt.status)">
					{{ attempt.status || '—' }}
				</span>
				<span class="submission-timeline__message">
					{{ humanMessage(attempt) }}
				</span>
				<span v-if="attempt.eventId" class="submission-timeline__event">
					{{ t('pipelinq', 'Event') }}: <code>{{ attempt.eventId }}</code>
				</span>
			</li>
		</ol>

		<p v-if="nextRetryAt" class="submission-timeline__retry">
			{{ t('pipelinq', 'Volgende retry gepland op:') }}
			<time :datetime="nextRetryAt">{{ formatStamp(nextRetryAt) }}</time>
		</p>
	</section>
</template>

<script>
export default {
	name: 'SubmissionTimeline',
	props: {
		/**
		 * Raw submissionAttempts array from the outbound message.
		 */
		attempts: {
			type: Array,
			default: () => [],
		},
		/**
		 * ISO 8601 nextRetryAt timestamp, or empty when no retry is scheduled.
		 */
		nextRetryAt: {
			type: String,
			default: '',
		},
	},
	computed: {
		/**
		 * Attempts sorted by timestamp in reverse chronological order.
		 *
		 * @return {Array<object>} The sorted attempts.
		 */
		sortedAttempts() {
			const list = Array.isArray(this.attempts) ? [...this.attempts] : []
			return list.sort((a, b) => String(b?.timestamp || '').localeCompare(String(a?.timestamp || '')))
		},
	},
	methods: {
		/**
		 * Map a HTTP status to a CSS class for the badge colour.
		 *
		 * @param {number|string} status The HTTP status code.
		 * @return {string} The CSS class.
		 */
		statusClass(status) {
			const code = Number(status)
			if (code >= 200 && code < 300) {
				return 'submission-timeline__badge--ok'
			}
			if (code >= 500 || code === 0) {
				return 'submission-timeline__badge--transient'
			}
			if (code >= 400) {
				return 'submission-timeline__badge--terminal'
			}
			return 'submission-timeline__badge--unknown'
		},
		/**
		 * Format an ISO 8601 stamp as a localised human-readable string.
		 *
		 * @param {string} stamp The ISO 8601 timestamp.
		 * @return {string} The formatted string.
		 */
		formatStamp(stamp) {
			if (!stamp) {
				return '—'
			}
			try {
				return new Date(stamp).toLocaleString()
			} catch {
				return stamp
			}
		},
		/**
		 * Render a friendly message for an attempt entry — translates the
		 * common server messages to Dutch, falls back to the raw value.
		 *
		 * @param {object} attempt The attempt row.
		 * @return {string} The localised message.
		 */
		humanMessage(attempt) {
			const raw = String(attempt?.message || '').trim()
			if (raw === '') {
				return t('pipelinq', 'Geen response message')
			}
			const map = {
				Accepted: t('pipelinq', 'Geaccepteerd door Shillinq'),
				'Service Unavailable': t('pipelinq', 'Shillinq niet bereikbaar — retry gepland'),
				NETWORK_TIMEOUT: t('pipelinq', 'Verbinding verlopen — retry gepland'),
			}
			if (Object.prototype.hasOwnProperty.call(map, raw)) {
				return map[raw]
			}
			if (raw.startsWith('NETWORK_TIMEOUT')) {
				return t('pipelinq', 'Verbinding verlopen — retry gepland')
			}
			return raw
		},
	},
}
</script>

<style scoped>
.submission-timeline {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.submission-timeline__title {
	margin: 0 0 4px 0;
	font-size: 1.05em;
}

.submission-timeline__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.submission-timeline__entry {
	display: grid;
	grid-template-columns: 1fr auto auto;
	gap: 8px;
	align-items: baseline;
	padding: 6px 8px;
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.submission-timeline__when {
	font-variant-numeric: tabular-nums;
}

.submission-timeline__badge {
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-weight: 600;
	font-size: 0.85em;
	background: var(--color-background-darker);
	color: var(--color-main-text);
}

.submission-timeline__badge--ok {
	background: var(--color-success);
	color: var(--color-main-background);
}

.submission-timeline__badge--transient {
	background: var(--color-warning);
	color: var(--color-main-background);
}

.submission-timeline__badge--terminal {
	background: var(--color-error);
	color: var(--color-main-background);
}

.submission-timeline__message {
	grid-column: 1 / -1;
	color: var(--color-text-maxcontrast);
}

.submission-timeline__event {
	grid-column: 1 / -1;
	font-size: 0.85em;
}

.submission-timeline__retry {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}

.submission-timeline__empty {
	color: var(--color-text-maxcontrast);
}
</style>
