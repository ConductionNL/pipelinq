<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<div class="blast-monitor">
		<NcButton
			class="blast-monitor__back"
			variant="tertiary"
			:to="{ name: 'Blasts' }">
			<template #icon>
				<ArrowLeft :size="18" />
			</template>
			{{ t('pipelinq', 'Back to blasts') }}
		</NcButton>

		<header class="blast-monitor__header">
			<div class="blast-monitor__heading">
				<h1>{{ blast?.name || t('pipelinq', 'Blast') }}</h1>
				<div class="blast-monitor__meta">
					<CnStatusBadge
						v-if="blast?.status"
						:status="blast.status"
						:variant="statusVariant(blast.status)"
						:label="statusLabel(blast.status)"
						size="small" />
					<router-link :to="{ name: 'Blasts' }" class="blast-monitor__crumb">
						{{ t('pipelinq', 'Newsletters') }}
					</router-link>
					<span aria-hidden="true">/</span>
					<span>{{ t('pipelinq', 'Live sending') }}</span>
					<span v-if="audienceTotal" class="blast-monitor__meta-line">
						· {{ t('pipelinq', '{count} recipients', { count: audienceTotal.toLocaleString() }) }}
					</span>
				</div>
			</div>
<div class="cn-header-actions">
			<NcButton
				v-if="canCancel"
				variant="error"
				:disabled="cancelling"
				@click="cancel">
				<template #icon>
					<Cancel :size="18" />
				</template>
				{{
					cancelling
						? t('pipelinq', 'Cancelling…')
						: t('pipelinq', 'Cancel send')
				}}
			</NcButton>
			<CnBuildiqEditButton />
			</div>
		</header>
		<p v-if="cancelError" class="blast-monitor__error" role="alert">
			{{ cancelError }}
		</p>

		<NcLoadingIcon v-if="loading" :size="32" />

		<section v-else class="blast-monitor__body">
			<div class="blast-monitor__card blast-monitor__progress">
				<div class="blast-monitor__progress-head">
					<p class="blast-monitor__progress-meta">
						<strong>{{ progressPercent }}%</strong>
						{{ t('pipelinq', 'done') }}
					</p>
					<p v-if="etaLabel" class="blast-monitor__eta">
						{{ etaLabel }}
					</p>
				</div>
				<div
					class="blast-monitor__bar"
					:style="{ '--blast-progress': progressPercent }"
					role="progressbar"
					:aria-valuenow="progressPercent"
					aria-valuemin="0"
					aria-valuemax="100">
					<div class="blast-monitor__bar-fill" />
				</div>
				<p class="blast-monitor__progress-note">
					{{
						t('pipelinq', '{done} of {total} handed to the provider', {
							done: processed.toLocaleString(),
							total: audienceTotal.toLocaleString(),
						})
					}}
				</p>
			</div>

			<div class="blast-monitor__totals">
				<div
					v-for="key in totalsKeys"
					:key="key"
					class="blast-monitor__card blast-monitor__total">
					<span class="blast-monitor__total-label">
						{{ totalLabel(key) }}
					</span>
					<strong
						class="blast-monitor__total-value"
						:class="`blast-monitor__total-value--${totalTone(key)}`">
						{{ totals[key] || 0 }}
					</strong>
					<span class="blast-monitor__total-caption">
						{{ totalCaption(key) }}
					</span>
				</div>
			</div>

			<section class="blast-monitor__card blast-monitor__timeline">
				<header class="blast-monitor__timeline-head">
					<h3>{{ t('pipelinq', 'Recent events') }}</h3>
					<span>{{ t('pipelinq', 'Newest first · addresses are masked') }}</span>
				</header>
				<ul v-if="timeline.length > 0">
					<li
						v-for="event in timeline"
						:key="event.id || event.timestamp + event.status">
						<time>{{ formatTime(event.timestamp) }}</time>
						<span class="blast-monitor__event-target">
							{{ maskAddress(event.email || event.phone || event.contactId) }}
						</span>
						<CnStatusBadge
							:status="event.status"
							:variant="statusVariant(event.status)"
							:label="statusLabel(event.status)"
							size="small" />
					</li>
				</ul>
				<p v-else class="blast-monitor__empty">
					{{ t('pipelinq', 'No events yet.') }}
				</p>
			</section>
		</section>
	</div>
</template>

<script>
import { CnBuildiqEditButton, CnStatusBadge } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'

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
// The tiles in the order the send monitor board draws them.
const TILE_ORDER = ['queued', 'sent', 'delivered', 'opened', 'clicked', 'bounced', 'unsubscribed', 'complained']
const TERMINAL_STATUSES = ['sent', 'failed', 'cancelled']

export default {
	name: 'BlastMonitor',
	components: {
		CnBuildiqEditButton,
		ArrowLeft,
		Cancel,
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
			return TILE_ORDER
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
			return this.t('pipelinq', 'About {seconds} s to go', {
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
				sending: this.t('pipelinq', 'Being sent'),
				cancelling: this.t('pipelinq', 'Cancelling'),
				sent: this.t('pipelinq', 'Sent'),
				paused: this.t('pipelinq', 'Paused'),
				failed: this.t('pipelinq', 'Failed'),
				cancelled: this.t('pipelinq', 'Cancelled'),
				queued: this.t('pipelinq', 'Queued'),
				delivered: this.t('pipelinq', 'Delivered'),
				opened: this.t('pipelinq', 'Opened'),
				clicked: this.t('pipelinq', 'Clicked'),
				bounced: this.t('pipelinq', 'Could not be delivered'),
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
			if (key === 'queued') {
				return this.t('pipelinq', 'In the queue')
			}
			return this.statusLabel(key)
		},

		/**
		 * An address as the event list shows it: the first character, then
		 * stars, then the domain ("a***@ziggo.nl"), so the list can be on a
		 * shared screen.
		 *
		 * @param {string} value An e-mail address, phone number or contact id.
		 * @return {string} The masked text.
		 * @spec openspec/specs/marketing-ui/spec.md#cancel-a-sending-blast
		 */
		maskAddress(value) {
			const text = String(value || '')
			if (text === '') {
				return '—'
			}
			const at = text.indexOf('@')
			if (at > 0) {
				return `${text.charAt(0)}***${text.slice(at)}`
			}
			return text.length > 4 ? `${'*'.repeat(text.length - 4)}${text.slice(-4)}` : text
		},

		/**
		 * The tone of a state: the colour of its pill and its tile figure.
		 *
		 * @param {string} status A blast or delivery state.
		 * @return {string} A CnStatusBadge variant.
		 * @spec openspec/specs/marketing-ui/spec.md#cancel-a-sending-blast
		 */
		statusVariant(status) {
			const tones = {
				sending: 'purple',
				cancelling: 'warning',
				scheduled: 'info',
				sent: 'default',
				delivered: 'success',
				opened: 'info',
				clicked: 'teal',
				bounced: 'error',
				failed: 'error',
				unsubscribed: 'warning',
				complained: 'error',
				paused: 'warning',
			}
			return tones[status] || 'default'
		},

		/**
		 * The tone of a tile's figure: delivered reads good, bounced reads bad.
		 *
		 * @param {string} key A totals key.
		 * @return {string} success, error or plain.
		 * @spec openspec/specs/marketing-ui/spec.md#cancel-a-sending-blast
		 */
		totalTone(key) {
			return { delivered: 'success', bounced: 'error' }[key] || 'plain'
		},

		/**
		 * The line under a tile's figure, saying what the number means.
		 *
		 * @param {string} key A totals key.
		 * @return {string} The caption.
		 * @spec openspec/specs/marketing-ui/spec.md#cancel-a-sending-blast
		 */
		totalCaption(key) {
			const totals = this.totals
			const share = (part, whole) => (whole ? Math.round(((part || 0) / whole) * 100) : 0)
			const captions = {
				queued: () => this.t('pipelinq', 'waiting on the rate limit'),
				sent: () => this.t('pipelinq', 'handed to the provider'),
				delivered: () => this.t('pipelinq', '{percent}% of sent', { percent: share(totals.delivered, totals.sent) }),
				opened: () => this.t('pipelinq', '{percent}% of delivered', { percent: share(totals.opened, totals.delivered) }),
				clicked: () => this.t('pipelinq', '{percent}% of delivered', { percent: share(totals.clicked, totals.delivered) }),
				bounced: () => this.t('pipelinq', 'address refused'),
				unsubscribed: () => this.t('pipelinq', 'via the link in the footer'),
				complained: () => this.t('pipelinq', 'marked as spam'),
			}
			return captions[key] ? captions[key]() : ''
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
				return date.toLocaleTimeString()
			} catch {
				return ts
			}
		},
	},
}
</script>

<style scoped lang="scss">
.cn-header-actions {
	display: flex;
	align-items: flex-start;
	gap: 8px;
}

.blast-monitor {
	box-sizing: border-box;
	width: 100%;
	padding: 24px 28px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.blast-monitor__back {
	align-self: flex-start;
	margin-inline-start: -12px;
}

.blast-monitor__header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
}

.blast-monitor__header h1 {
	margin: 0 0 8px;
	font-size: 28px;
}

.blast-monitor__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	color: var(--color-text-maxcontrast);
}

.blast-monitor__crumb {
	text-decoration: underline;
	color: var(--color-primary-element);
}

.blast-monitor__body {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.blast-monitor__card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px 22px;
}

.blast-monitor__progress {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.blast-monitor__progress-head {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 12px;
}

.blast-monitor__progress-meta {
	margin: 0;
	display: flex;
	align-items: baseline;
	gap: 8px;

	strong {
		font-size: 26px;
	}
}

.blast-monitor__bar {
	position: relative;
	width: 100%;
	height: 12px;
	background: var(--color-background-dark);
	border-radius: 999px;
	overflow: hidden;
}

.blast-monitor__bar-fill {
	height: 100%;
	width: calc(var(--blast-progress) * 1%);
	background: var(--color-primary-element);
	border-radius: 999px;
	transition: width 600ms ease;
}

.blast-monitor__eta {
	margin: 0;
	color: var(--color-primary-element);
	font-weight: 600;
}

.blast-monitor__progress-note {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.blast-monitor__totals {
	display: grid;
	grid-template-columns: repeat(5, minmax(0, 1fr));
	gap: 16px;
}

.blast-monitor__total {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.blast-monitor__card.blast-monitor__total {
	padding: 16px 18px;
}

.blast-monitor__total-label,
.blast-monitor__total-caption {
	color: var(--color-text-maxcontrast);
}

.blast-monitor__total-caption {
	font-size: 13px;
}

.blast-monitor__total-value {
	font-size: 26px;
	color: var(--color-main-text);
}

.blast-monitor__total-value--success {
	color: var(--color-success-text, var(--color-success));
}

.blast-monitor__total-value--error {
	color: var(--color-error-text, var(--color-error));
}

.blast-monitor__timeline-head {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	color: var(--color-text-maxcontrast);
	font-size: 13px;

	h3 {
		margin: 0 0 8px;
		color: var(--color-main-text);
		font-size: 17px;
	}
}

.blast-monitor__timeline ul {
	margin: 0;
	padding: 0;
	list-style: none;
}

.blast-monitor__timeline li {
	display: grid;
	grid-template-columns: 100px 1fr auto;
	align-items: center;
	gap: 8px;
	padding: 10px 0;
	border-bottom: 1px solid var(--color-border);
}

.blast-monitor__timeline time {
	color: var(--color-text-maxcontrast);
}

.blast-monitor__event-target {
	font-weight: 600;
}

.blast-monitor__empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.blast-monitor__error {
	color: var(--color-error);
	font-weight: 600;
	margin: 0;
}

@media (max-width: 1024px) {
	.blast-monitor__totals {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}
}

@media (prefers-reduced-motion: reduce) {
	.blast-monitor__bar-fill {
		transition: none;
	}
}
</style>
