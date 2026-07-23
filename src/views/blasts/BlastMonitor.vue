<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<div class="blast-monitor">
		<header class="blast-monitor__header">
			<NcButton type="tertiary" @click="$router.push({ name: 'Blasts' })">
				{{ t('pipelinq', 'Back to blasts') }}
			</NcButton>
			<h2>{{ blast?.name || t('pipelinq', 'Blast') }}</h2>
			<CnStatusBadge v-if="blast?.status"
				:status="blast.status"
				:label="statusLabel(blast.status)" />
		</header>

		<NcLoadingIcon v-if="loading" :size="32" />

		<section v-else class="blast-monitor__body">
			<div class="blast-monitor__progress">
				<div class="blast-monitor__bar"
					role="progressbar"
					:aria-valuenow="progressPercent"
					aria-valuemin="0"
					aria-valuemax="100">
					<div class="blast-monitor__bar-fill" :style="{ width: progressPercent + '%' }" />
				</div>
				<p class="blast-monitor__progress-meta">
					<strong>{{ progressPercent }}%</strong>
					{{ t('pipelinq', 'complete') }}
					<span v-if="etaLabel" class="blast-monitor__eta">
						· {{ etaLabel }}
					</span>
				</p>
			</div>

			<div class="blast-monitor__totals">
				<div v-for="key in totalsKeys" :key="key" class="blast-monitor__total">
					<span class="blast-monitor__total-label">
						{{ totalLabel(key) }}
					</span>
					<strong class="blast-monitor__total-value">
						{{ totals[key] || 0 }}
					</strong>
				</div>
			</div>

			<section class="blast-monitor__timeline">
				<h3>{{ t('pipelinq', 'Recent events') }}</h3>
				<ul v-if="timeline.length > 0">
					<li v-for="event in timeline" :key="event.id || event.timestamp + event.status">
						<time>{{ formatTime(event.timestamp) }}</time>
						<CnStatusBadge :status="event.status"
							:label="statusLabel(event.status)" />
						<span class="blast-monitor__event-target">
							{{ event.email || event.phone || event.contactId || '—' }}
						</span>
					</li>
				</ul>
				<p v-else class="blast-monitor__empty">
					{{ t('pipelinq', 'No events yet.') }}
				</p>
			</section>

			<footer v-if="canCancel" class="blast-monitor__footer">
				<NcButton type="error" :disabled="cancelling" @click="cancel">
					{{ cancelling ? t('pipelinq', 'Cancelling…') : t('pipelinq', 'Cancel send') }}
				</NcButton>
			</footer>
			<p v-if="cancelError" class="blast-monitor__error" role="alert">
				{{ cancelError }}
			</p>
		</section>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const POLL_INTERVAL_MS = 2000
const TIMELINE_MAX = 50
const TOTALS_KEYS = [
	'queued',
	'sent',
	'delivered',
	'bounced',
	'opened',
	'clicked',
	'unsubscribed',
	'complained',
]
const TERMINAL_STATUSES = ['sent', 'failed', 'cancelled']

export default {
	name: 'BlastMonitor',
	components: {
		NcButton,
		NcLoadingIcon,
		CnStatusBadge,
	},
	props: {
		id: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			loading: true,
			blast: null,
			timeline: [],
			cancelling: false,
			cancelError: '',
			pollHandle: null,
			startedAt: null,
		}
	},
	computed: {
		/**
		 * Convenience accessor for the blast's totals counter map.
		 *
		 * @return {object}
		 */
		totals() {
			return this.blast?.totals || {}
		},
		/**
		 * Stable ordering for the totals grid.
		 *
		 * @return {Array<string>}
		 */
		totalsKeys() {
			return TOTALS_KEYS
		},
		/**
		 * Sum of all non-queued totals — used for progress %.
		 *
		 * @return {number}
		 */
		processed() {
			return TOTALS_KEYS
				.filter((k) => k !== 'queued')
				.reduce((sum, key) => sum + (this.totals[key] || 0), 0)
		},
		/**
		 * Total audience that has ever been in the queue (queued + processed).
		 *
		 * @return {number}
		 */
		audienceTotal() {
			return this.processed + (this.totals.queued || 0)
		},
		/**
		 * Send progress as a percentage 0-100.
		 *
		 * @return {number}
		 */
		progressPercent() {
			if (this.audienceTotal <= 0) {
				return 0
			}
			return Math.min(100, Math.round((this.processed / this.audienceTotal) * 100))
		},
		/**
		 * Heuristic ETA label computed from polling rate.
		 *
		 * @return {string}
		 */
		etaLabel() {
			if (!this.startedAt || this.audienceTotal === 0 || this.processed === 0) {
				return ''
			}
			const elapsedMs = Date.now() - this.startedAt
			const rate = this.processed / (elapsedMs / 1000)
			const remaining = (this.totals.queued || 0) / Math.max(rate, 0.001)
			if (!isFinite(remaining) || remaining < 1) {
				return ''
			}
			return this.t('pipelinq', 'ETA: ~{seconds}s remaining', { seconds: Math.round(remaining) })
		},
		/**
		 * Whether the blast is currently in a status that allows cancel.
		 *
		 * @return {boolean}
		 */
		canCancel() {
			return this.blast?.status === 'sending' || this.blast?.status === 'scheduled'
		},
	},
	mounted() {
		this.startedAt = Date.now()
		this.fetchOnce()
		this.startPolling()
	},
	beforeUnmount() {
		this.stopPolling()
	},
	methods: {
		/**
		 * Start the 2-second polling loop.
		 */
		startPolling() {
			if (this.pollHandle) {
				return
			}
			this.pollHandle = setInterval(() => this.fetchOnce(), POLL_INTERVAL_MS)
		},
		/**
		 * Stop the polling loop and clear the handle.
		 */
		stopPolling() {
			if (this.pollHandle) {
				clearInterval(this.pollHandle)
				this.pollHandle = null
			}
		},
		/**
		 * Fetch the latest blast + its recent deliveries; update totals/timeline.
		 *
		 * @spec openspec/specs/marketing-ui/spec.md#scenario-progress-bar-and-totals-update-by-polling
		 */
		async fetchOnce() {
			try {
				const { data } = await axios.get(generateUrl(`/apps/pipelinq/api/blasts/${this.id}`))
				this.blast = data?.data || data
				const deliveries = this.blast?.recentDeliveries
					|| this.blast?.deliveries
					|| (await this.fetchDeliveries())
				this.timeline = this.buildTimeline(deliveries)
				if (TERMINAL_STATUSES.includes(this.blast?.status)) {
					this.stopPolling()
				}
			} catch (_e) {
				// Keep polling; transient errors are silently retried.
			} finally {
				this.loading = false
			}
		},
		/**
		 * Fallback fetch of deliveries when the blast payload does not embed them.
		 *
		 * @return {Promise<Array>}
		 */
		async fetchDeliveries() {
			try {
				const url = generateUrl(`/apps/pipelinq/api/blasts/${this.id}/deliveries`)
				const { data } = await axios.get(url, { params: { limit: TIMELINE_MAX } })
				return data?.data || data?.results || data || []
			} catch (_e) {
				return []
			}
		},
		/**
		 * Build the reverse-chronological event timeline (capped at 50 entries).
		 *
		 * @param {Array} deliveries Raw delivery objects.
		 * @return {Array} Trimmed timeline entries.
		 */
		buildTimeline(deliveries) {
			if (!Array.isArray(deliveries)) {
				return []
			}
			return deliveries
				.map((d) => ({
					id: d.id,
					contactId: d.contactId,
					email: d.email,
					phone: d.phone,
					status: d.status || 'queued',
					timestamp: d.clickedAt || d.openedAt || d.bouncedAt || d.deliveredAt || d.sentAt || d.updatedAt || d.createdAt,
				}))
				.filter((d) => d.timestamp)
				.sort((a, b) => (a.timestamp < b.timestamp ? 1 : -1))
				.slice(0, TIMELINE_MAX)
		},
		/**
		 * POST the cancel endpoint and reflect a cancelling status locally.
		 *
		 * @spec openspec/specs/marketing-ui/spec.md#scenario-cancel-a-sending-blast
		 */
		async cancel() {
			this.cancelling = true
			this.cancelError = ''
			try {
				await axios.post(generateUrl(`/apps/pipelinq/api/blasts/${this.id}/cancel`))
				if (this.blast) {
					this.blast = { ...this.blast, status: 'cancelling' }
				}
				// Keep polling so the actual status update lands.
			} catch (e) {
				this.cancelError = e?.response?.data?.error
					|| this.t('pipelinq', 'Could not cancel this blast.')
			} finally {
				this.cancelling = false
			}
		},
		/**
		 * Localised label for a status badge.
		 *
		 * @param {string} status The status key.
		 * @return {string}
		 */
		statusLabel(status) {
			const map = {
				draft: this.t('pipelinq', 'Draft'),
				scheduled: this.t('pipelinq', 'Scheduled'),
				sending: this.t('pipelinq', 'Sending'),
				cancelling: this.t('pipelinq', 'Cancelling'),
				sent: this.t('pipelinq', 'Sent'),
				paused: this.t('pipelinq', 'Paused'),
				failed: this.t('pipelinq', 'Failed'),
				cancelled: this.t('pipelinq', 'Cancelled'),
				queued: this.t('pipelinq', 'Queued'),
				delivered: this.t('pipelinq', 'Delivered'),
				opened: this.t('pipelinq', 'Opened'),
				clicked: this.t('pipelinq', 'Clicked'),
				bounced: this.t('pipelinq', 'Bounced'),
				unsubscribed: this.t('pipelinq', 'Unsubscribed'),
				complained: this.t('pipelinq', 'Complained'),
			}
			return map[status] || status
		},
		/**
		 * Localised label for a totals key (re-uses status labels).
		 *
		 * @param {string} key The totals key.
		 * @return {string}
		 */
		totalLabel(key) {
			return this.statusLabel(key)
		},
		/**
		 * Pretty-print a timestamp for the timeline list.
		 *
		 * @param {string} ts Timestamp string.
		 * @return {string}
		 */
		formatTime(ts) {
			if (!ts) {
				return ''
			}
			try {
				const date = new Date(ts)
				if (isNaN(date.getTime())) {
					return ts
				}
				return date.toLocaleString()
			} catch (_e) {
				return ts
			}
		},
	},
}
</script>

<style scoped>
.blast-monitor {
	padding: 20px;
	max-width: 980px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.blast-monitor__header {
	display: flex;
	align-items: center;
	gap: 12px;
}

.blast-monitor__header h2 {
	margin: 0;
}

.blast-monitor__progress {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.blast-monitor__bar {
	width: 100%;
	height: 14px;
	background: var(--color-background-darker);
	border-radius: var(--border-radius);
	overflow: hidden;
}

.blast-monitor__bar-fill {
	height: 100%;
	background: var(--color-primary-element);
	transition: width 200ms linear;
}

.blast-monitor__progress-meta {
	margin: 0;
	color: var(--color-text-lighter);
}

.blast-monitor__eta {
	margin-left: 6px;
}

.blast-monitor__totals {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
	gap: 8px;
}

.blast-monitor__total {
	display: flex;
	flex-direction: column;
	padding: 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.blast-monitor__total-label {
	color: var(--color-text-lighter);
	font-size: 0.85em;
}

.blast-monitor__total-value {
	font-size: 1.4em;
	color: var(--color-main-text);
}

.blast-monitor__timeline {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.blast-monitor__timeline ul {
	margin: 0;
	padding: 0;
	list-style: none;
	max-height: 360px;
	overflow-y: auto;
}

.blast-monitor__timeline li {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}

.blast-monitor__event-target {
	color: var(--color-text-lighter);
}

.blast-monitor__empty {
	color: var(--color-text-lighter);
	margin: 0;
}

.blast-monitor__footer {
	display: flex;
	justify-content: flex-end;
}

.blast-monitor__error {
	color: var(--color-error);
	font-weight: 600;
	margin: 0;
}
</style>
