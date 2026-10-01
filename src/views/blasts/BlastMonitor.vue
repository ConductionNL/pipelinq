<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<div class="blast-monitor">
		<header class="blast-monitor__header">
			<NcButton variant="tertiary" @click="$router.push({ name: 'Blasts' })">
				{{ t('pipelinq', 'Back to blasts') }}
			</NcButton>
			<h2>{{ blast?.name || t('pipelinq', 'Blast') }}</h2>
			<CnStatusBadge
				v-if="blast?.status"
				:status="blast.status"
				:label="statusLabel(blast.status)" />
		</header>

		<NcLoadingIcon v-if="loading" :size="32" />

		<section v-else class="blast-monitor__body">
			<div class="blast-monitor__progress">
				<!-- The label is rendered twice: once on the track and once on the
					fill, which is masked to the progress width with a wavy edge, so
					the text flips colour exactly along the wave. -->
				<div
					class="blast-monitor__bar"
					:class="{
						'blast-monitor__bar--active': isSending,
						'blast-monitor__bar--empty': progressPercent <= 0,
						'blast-monitor__bar--full': progressPercent >= 100,
					}"
					:style="{ '--blast-progress': progressPercent }"
					role="progressbar"
					:aria-valuenow="progressPercent"
					aria-valuemin="0"
					aria-valuemax="100">
					<span class="blast-monitor__bar-label">
						<strong>{{ progressPercent }}%</strong>
						{{ t('pipelinq', 'complete') }}
					</span>
					<span class="blast-monitor__bar-wave" aria-hidden="true" />
					<div
						class="blast-monitor__bar-fill blast-monitor__bar-wave"
						aria-hidden="true">
						<span class="blast-monitor__bar-label">
							<strong>{{ progressPercent }}%</strong>
							{{ t('pipelinq', 'complete') }}
						</span>
					</div>
				</div>
				<p v-if="etaLabel" class="blast-monitor__eta">
					{{ etaLabel }}
				</p>
			</div>

			<div class="blast-monitor__totals">
				<div
					v-for="key in totalsKeys"
					:key="key"
					class="blast-monitor__total">
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
					<li
						v-for="event in timeline"
						:key="event.id || event.timestamp + event.status">
						<time>{{ formatTime(event.timestamp) }}</time>
						<CnStatusBadge
							:status="event.status"
							:label="statusLabel(event.status)" />
						<span class="blast-monitor__event-target">
							{{
								event.email || event.phone || event.contactId || '—'
							}}
						</span>
					</li>
				</ul>
				<p v-else class="blast-monitor__empty">
					{{ t('pipelinq', 'No events yet.') }}
				</p>
			</section>

			<footer v-if="canCancel" class="blast-monitor__footer">
				<NcButton variant="error" :disabled="cancelling" @click="cancel">
					{{
						cancelling
							? t('pipelinq', 'Cancelling…')
							: t('pipelinq', 'Cancel send')
					}}
				</NcButton>
			</footer>
			<p v-if="cancelError" class="blast-monitor__error" role="alert">
				{{ cancelError }}
			</p>
		</section>
	</div>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'

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
			return TOTALS_KEYS.filter((k) => k !== 'queued').reduce(
				(sum, key) => sum + (this.totals[key] || 0),
				0,
			)
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
			return Math.min(
				100,
				Math.round((this.processed / this.audienceTotal) * 100),
			)
		},

		/**
		 * Heuristic ETA label computed from polling rate.
		 *
		 * @return {string}
		 */
		etaLabel() {
			if (
				!this.startedAt
				|| this.audienceTotal === 0
				|| this.processed === 0
			) {
				return ''
			}
			const elapsedMs = Date.now() - this.startedAt
			const rate = this.processed / (elapsedMs / 1000)
			const remaining = (this.totals.queued || 0) / Math.max(rate, 0.001)
			if (!isFinite(remaining) || remaining < 1) {
				return ''
			}
			return this.t('pipelinq', 'ETA: ~{seconds}s remaining', {
				seconds: Math.round(remaining),
			})
		},

		/**
		 * Whether messages are going out right now; drives the moving stripes.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/specs/marketing-ui/spec.md#scenario-progress-bar-and-totals-update-by-polling
		 */
		isSending() {
			return (
				this.blast?.status === 'sending'
				|| this.blast?.status === 'cancelling'
			)
		},

		/**
		 * Whether the blast is currently in a status that allows cancel.
		 *
		 * @return {boolean}
		 */
		canCancel() {
			return (
				this.blast?.status === 'sending'
				|| this.blast?.status === 'scheduled'
			)
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
				const { data } = await axios.get(
					generateUrl(`/apps/pipelinq/api/blasts/${this.id}`),
				)
				this.blast = data?.data || data
				const deliveries =
					this.blast?.recentDeliveries
					|| this.blast?.deliveries
					|| (await this.fetchDeliveries())
				this.timeline = this.buildTimeline(deliveries)
				if (TERMINAL_STATUSES.includes(this.blast?.status)) {
					this.stopPolling()
				}
			} catch {
				// Keep polling; transient errors are silently retried.
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fallback fetch of deliveries when the blast payload does not embed them.
		 *
		 * @return {Promise<Array>}
		 * @spec openspec/specs/marketing-ui/spec.md#requirement-live-send-monitor
		 */
		async fetchDeliveries() {
			try {
				const url = generateUrl(
					`/apps/pipelinq/api/blasts/${this.id}/deliveries`,
				)
				const { data } = await axios.get(url, {
					params: { limit: TIMELINE_MAX },
				})
				return data?.data || data?.results || data || []
			} catch {
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
					timestamp:
						d.clickedAt
						|| d.openedAt
						|| d.bouncedAt
						|| d.deliveredAt
						|| d.sentAt
						|| d.updatedAt
						|| d.createdAt,
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
				await axios.post(
					generateUrl(`/apps/pipelinq/api/blasts/${this.id}/cancel`),
				)
				if (this.blast) {
					this.blast = { ...this.blast, status: 'cancelling' }
				}
				// Keep polling so the actual status update lands.
			} catch (e) {
				this.cancelError =
					e?.response?.data?.error
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
		 * @spec exclude display formatter for the timeline list; returns the raw value when
		 *   the timestamp will not parse
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
			} catch {
				return ts
			}
		},
	},
}
</script>

<style scoped>
.blast-monitor {
	box-sizing: border-box;
	width: 100%;
	max-width: 980px;
	margin: 0 auto;
	padding: 24px 20px;
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.blast-monitor__header {
	display: flex;
	align-items: center;
	gap: 12px;
}

.blast-monitor__header h2 {
	margin: 0;
}

.blast-monitor__body {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.blast-monitor__progress {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.blast-monitor__bar {
	position: relative;
	width: 100%;
	height: 34px;
	background: var(--color-background-dark);
	border-radius: 999px;
	box-shadow: inset 0 1px 3px rgba(var(--color-box-shadow-rgb), 0.25);
	overflow: hidden;
	/* Lets the wave layers turn the progress number into a length (cqi). */
	container-type: inline-size;
	transition: --blast-progress 600ms ease;
}

.blast-monitor__bar-label {
	position: absolute;
	inset: 0;
	z-index: 1;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 4px;
	color: var(--color-main-text);
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

/* Both wave layers are full-width and masked by two layers: a solid block up to
   the wave's start, plus a sine-edged strip that scrolls vertically, so the
   leading edge ripples. The bare .blast-monitor__bar-wave is the paler, larger
   wave behind the fill, running the other way. */
.blast-monitor__bar-wave {
	--wave-width: 20px;
	--wave-height: 80px;
	--wave-shift: 6px;
	--wave-edge: calc(
		var(--blast-progress) * 1cqi - var(--wave-width) / 2 + var(--wave-shift)
	);
	position: absolute;
	inset: 0;
	background: color-mix(in srgb, var(--color-primary-element) 40%, transparent);
	mask-image:
		url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 34' preserveAspectRatio='none'%3E%3Cpath d='M0 0H15C28.3 6.1 28.3 10.9 15 17S1.7 27.9 15 34H0Z'/%3E%3C/svg%3E"),
		linear-gradient(#000, #000);
	mask-repeat: repeat-y, no-repeat;
	mask-position:
		var(--wave-edge) 0,
		0 0;
	mask-size:
		var(--wave-width) var(--wave-height),
		max(0px, var(--wave-edge) + 1px) 100%;
	animation: blast-monitor-wave 3.6s linear infinite reverse;
}

.blast-monitor__bar-fill {
	--wave-width: 15px;
	--wave-height: 56px;
	--wave-shift: 0px;
	background: var(--color-primary-element);
	animation-duration: 2.4s;
	animation-direction: normal;
}

.blast-monitor__bar-fill .blast-monitor__bar-label {
	color: var(--color-primary-element-text);
}

.blast-monitor__bar--active .blast-monitor__bar-wave {
	animation-duration: 1.4s;
}

.blast-monitor__bar--active .blast-monitor__bar-fill {
	animation-duration: 0.9s;
}

.blast-monitor__bar--empty .blast-monitor__bar-wave {
	visibility: hidden;
}

.blast-monitor__bar--full .blast-monitor__bar-wave {
	mask: none;
	animation: none;
}

@property --blast-progress {
	syntax: '<number>';
	inherits: true;
	initial-value: 0;
}

@keyframes blast-monitor-wave {
	from {
		mask-position:
			var(--wave-edge) 0,
			0 0;
	}

	to {
		mask-position:
			var(--wave-edge) var(--wave-height),
			0 0;
	}
}

.blast-monitor__eta {
	margin: 0;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
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

@media (prefers-reduced-motion: reduce) {
	.blast-monitor__bar {
		transition: none;
	}

	.blast-monitor__bar-wave {
		animation: none;
	}
}
</style>
