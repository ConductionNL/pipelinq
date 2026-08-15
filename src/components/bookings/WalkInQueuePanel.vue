<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2

WalkInQueuePanel — real-time operator view of the walk-in ticket queue
(appointment-booking member 09 of 12). Lists `waiting` and `called` tickets
ordered by `arrivedAt`, exposes Call next / Serve / Abandon actions, and
auto-refreshes every 10 seconds so ETAs surface as the queue rebalances after
a Booking completes (member 04 -> member 09).

@spec openspec/specs/appointment-booking/spec.md
-->
<template>
	<div class="walkin-queue-panel">
		<header class="walkin-queue-panel__header">
			<h2>{{ t('pipelinq', 'Walk-in queue') }}</h2>
			<NcButton variant="primary" :disabled="!canCallNext" @click="onCallNext">
				{{ t('pipelinq', 'Call next') }}
			</NcButton>
		</header>

		<p v-if="loadError" class="walkin-queue-panel__error" role="alert">
			{{ loadError }}
		</p>

		<div
			v-if="loading && !tickets.length"
			class="walkin-queue-panel__loading"
			role="status"
			aria-live="polite">
			{{ t('pipelinq', 'Loading queue…') }}
		</div>

		<div
			v-else-if="!sortedTickets.length"
			class="walkin-queue-panel__empty"
			role="status">
			<p>{{ t('pipelinq', 'The walk-in queue is empty.') }}</p>
		</div>

		<ul v-else class="walkin-queue-panel__list" role="list">
			<li
				v-for="ticket in sortedTickets"
				:key="ticketKey(ticket)"
				class="walkin-queue-ticket"
				:class="{
					'walkin-queue-ticket--called': ticket.status === 'called',
				}">
				<div class="walkin-queue-ticket__primary">
					<span class="walkin-queue-ticket__name">
						{{
							ticket.displayName || t('pipelinq', 'Anonymous customer')
						}}
					</span>
					<span
						v-if="ticket.serviceName"
						class="walkin-queue-ticket__service">
						{{ ticket.serviceName }}
					</span>
				</div>
				<dl class="walkin-queue-ticket__meta">
					<div class="walkin-queue-ticket__meta-item">
						<dt>{{ t('pipelinq', 'Arrived') }}</dt>
						<dd>{{ formatTime(ticket.arrivedAt) }}</dd>
					</div>
					<div
						v-if="ticket.estimatedReadyAt"
						class="walkin-queue-ticket__meta-item">
						<dt>{{ t('pipelinq', 'Ready at') }}</dt>
						<dd>{{ formatTime(ticket.estimatedReadyAt) }}</dd>
					</div>
					<div class="walkin-queue-ticket__meta-item">
						<dt>{{ t('pipelinq', 'Status') }}</dt>
						<dd>{{ statusLabel(ticket.status) }}</dd>
					</div>
				</dl>
				<div class="walkin-queue-ticket__actions">
					<NcButton
						v-if="ticket.status === 'waiting'"
						:disabled="busyTicketId === ticketKey(ticket)"
						@click="onCallTicket(ticket)">
						{{ t('pipelinq', 'Call') }}
					</NcButton>
					<NcButton
						v-if="ticket.status === 'called'"
						variant="primary"
						:disabled="busyTicketId === ticketKey(ticket)"
						@click="onServe(ticket)">
						{{ t('pipelinq', 'Serve') }}
					</NcButton>
					<NcButton
						variant="tertiary"
						:disabled="busyTicketId === ticketKey(ticket)"
						@click="onAbandon(ticket)">
						{{ t('pipelinq', 'Abandon') }}
					</NcButton>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'

/**
 * Pipelinq walk-in queue operator panel.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
export default {
	name: 'WalkInQueuePanel',
	components: { NcButton },
	props: {
		/**
		 * OpenRegister register slug/id (e.g. `pipelinq`).
		 */
		register: {
			type: String,
			required: true,
		},

		/**
		 * OpenRegister schema slug/id for the walkInTicket schema.
		 */
		schema: {
			type: String,
			required: true,
		},

		/**
		 * Refresh interval in milliseconds (default 10s per design).
		 */
		refreshInterval: {
			type: Number,
			default: 10000,
		},
	},

	data() {
		return {
			tickets: [],
			loading: false,
			loadError: '',
			busyTicketId: '',
			refreshTimer: null,
		}
	},

	computed: {
		/**
		 * Tickets sorted by arrivedAt ascending (oldest first).
		 *
		 * @return {Array} Sorted ticket list.
		 */
		sortedTickets() {
			const open = this.tickets.filter(
				(ticket) =>
					ticket.status === 'waiting' || ticket.status === 'called',
			)
			return open.slice().sort((left, right) => {
				const leftIso = String(left.arrivedAt || '')
				const rightIso = String(right.arrivedAt || '')
				if (leftIso === rightIso) return 0
				return leftIso < rightIso ? -1 : 1
			})
		},

		/**
		 * True when at least one waiting ticket exists.
		 *
		 * @return {boolean} True when Call next is available.
		 */
		canCallNext() {
			return this.sortedTickets.some((ticket) => ticket.status === 'waiting')
		},
	},

	mounted() {
		this.fetchTickets()
		this.refreshTimer = window.setInterval(
			this.fetchTickets,
			this.refreshInterval,
		)
	},

	beforeUnmount() {
		if (this.refreshTimer) {
			window.clearInterval(this.refreshTimer)
			this.refreshTimer = null
		}
	},

	methods: {
		/**
		 * Build the OpenRegister objects URL for the walkInTicket schema.
		 *
		 * @param {string} suffix Optional path suffix (e.g. ticket UUID).
		 * @return {string} The absolute URL.
		 */
		buildUrl(suffix = '') {
			const tail = suffix ? '/' + encodeURIComponent(suffix) : ''
			return generateUrl(
				'/apps/openregister/api/objects/'
					+ encodeURIComponent(this.register)
					+ '/'
					+ encodeURIComponent(this.schema)
					+ tail,
			)
		},

		/**
		 * A stable key for a ticket row.
		 *
		 * @param {object} ticket OpenRegister entity.
		 * @return {string} The key.
		 */
		ticketKey(ticket) {
			if (!ticket) return ''
			if (ticket['@self'] && ticket['@self'].id)
				return String(ticket['@self'].id)
			if (ticket.id) return String(ticket.id)
			if (ticket.uuid) return String(ticket.uuid)
			return ''
		},

		/**
		 * Format an ISO datetime as a local short time (HH:MM).
		 *
		 * @param {string} iso ISO-8601 timestamp.
		 * @return {string} Local short time or '—' on parse failure.
		 */
		formatTime(iso) {
			if (!iso) return '—'
			const date = new Date(iso)
			if (Number.isNaN(date.getTime())) return '—'
			return date.toLocaleTimeString([], {
				hour: '2-digit',
				minute: '2-digit',
			})
		},

		/**
		 * Translate the raw status enum to a human-readable label.
		 *
		 * @param {string} raw Ticket status enum value.
		 * @return {string} Localised status label.
		 */
		statusLabel(raw) {
			if (raw === 'waiting') return t('pipelinq', 'Waiting')
			if (raw === 'called') return t('pipelinq', 'Called')
			if (raw === 'served') return t('pipelinq', 'Served')
			if (raw === 'abandoned') return t('pipelinq', 'Abandoned')
			return raw || ''
		},

		/**
		 * Fetch the open queue (waiting + called) from OpenRegister.
		 *
		 * @return {Promise<void>} Resolves when fetch completes.
		 */
		async fetchTickets() {
			if (this.busyTicketId) return
			this.loading = true
			try {
				const params = new URLSearchParams()
				params.set('_limit', '200')
				const response = await axios.get(
					this.buildUrl() + '?' + params.toString(),
				)
				const payload = response && response.data ? response.data : {}
				const list = payload.results || payload.objects || payload || []
				this.tickets = Array.isArray(list) ? list : []
				this.loadError = ''
			} catch (err) {
				this.loadError = t(
					'pipelinq',
					'Could not load the walk-in queue. Retrying in {seconds} seconds.',
					{
						seconds: Math.round(this.refreshInterval / 1000),
					},
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Call the next waiting ticket (oldest arrivedAt).
		 *
		 * @return {Promise<void>} Resolves when transition completes.
		 */
		async onCallNext() {
			const next = this.sortedTickets.find(
				(ticket) => ticket.status === 'waiting',
			)
			if (!next) return
			await this.onCallTicket(next)
		},

		/**
		 * Transition a specific ticket from `waiting` to `called`.
		 *
		 * @param {object} ticket OpenRegister entity.
		 * @return {Promise<void>} Resolves when transition completes.
		 */
		async onCallTicket(ticket) {
			await this.updateStatus(ticket, 'called', {})
		},

		/**
		 * Transition a ticket to `served` (sets actualServedAt locally).
		 *
		 * @param {object} ticket OpenRegister entity.
		 * @return {Promise<void>} Resolves when transition completes.
		 */
		async onServe(ticket) {
			const nowIso = new Date().toISOString()
			await this.updateStatus(ticket, 'served', { actualServedAt: nowIso })
		},

		/**
		 * Transition a ticket to `abandoned`.
		 *
		 * @param {object} ticket OpenRegister entity.
		 * @return {Promise<void>} Resolves when transition completes.
		 */
		async onAbandon(ticket) {
			await this.updateStatus(ticket, 'abandoned', {})
		},

		/**
		 * Update a ticket via OpenRegister PUT and refresh the panel.
		 *
		 * @param {object} ticket OpenRegister entity.
		 * @param {string} nextStatus Target status enum.
		 * @param {object} extra Extra fields to write alongside the status.
		 * @return {Promise<void>} Resolves when refresh completes.
		 */
		async updateStatus(ticket, nextStatus, extra) {
			const key = this.ticketKey(ticket)
			if (!key) return
			this.busyTicketId = key
			try {
				const payload = {
					...ticket,
					status: nextStatus,
					...(extra || {}),
				}
				if (payload['@self']) {
					delete payload['@self']
				}
				await axios.put(this.buildUrl(key), payload)
				await this.fetchTickets()
			} catch (err) {
				this.loadError = t(
					'pipelinq',
					'Could not update the ticket. Please try again.',
				)
			} finally {
				this.busyTicketId = ''
			}
		},
	},
}
</script>

<style scoped>
.walkin-queue-panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.walkin-queue-panel__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.walkin-queue-panel__error {
	color: var(--color-error);
}

.walkin-queue-panel__empty,
.walkin-queue-panel__loading {
	color: var(--color-text-maxcontrast);
	padding: 12px 0;
}

.walkin-queue-panel__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.walkin-queue-ticket {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	display: grid;
	grid-template-columns: 1fr auto;
	grid-template-rows: auto auto;
	column-gap: 12px;
	row-gap: 8px;
	background: var(--color-main-background);
}

.walkin-queue-ticket--called {
	border-color: var(--color-primary-element);
	background: var(--color-primary-light);
}

.walkin-queue-ticket__primary {
	grid-column: 1;
	grid-row: 1;
	display: flex;
	flex-direction: column;
}

.walkin-queue-ticket__name {
	font-weight: 600;
}

.walkin-queue-ticket__service {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.walkin-queue-ticket__meta {
	grid-column: 1;
	grid-row: 2;
	display: flex;
	gap: 16px;
	margin: 0;
}

.walkin-queue-ticket__meta-item {
	display: flex;
	flex-direction: column;
}

.walkin-queue-ticket__meta-item dt {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.walkin-queue-ticket__meta-item dd {
	margin: 0;
}

.walkin-queue-ticket__actions {
	grid-column: 2;
	grid-row: 1 / span 2;
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>
