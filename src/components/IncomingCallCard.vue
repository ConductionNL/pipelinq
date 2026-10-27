<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The incoming-call card of board PqInkomendGesprek. It asks the server which
  - call is on the agent's own line, looks the caller up with the screen pop,
  - and opens the matched record, the chooser or the new-contact form.
  -
  - With no telephony platform configured the first answer says so, and the
  - card stops: nothing is shown, nothing is polled and nothing errors.
  -
  - `?callPreview=<number>` in the address shows the card for that number
  - without a call on the line, so the card, the chooser
  - (`&callPreviewOpen=chooser`) and the new-contact form
  - (`&callPreviewOpen=intake`) and the wrap-up after the call
  - (`&callPreviewOpen=disposition`) can be seen and checked against their boards.
  - The preview only looks the number up; it writes nothing until the agent
  - saves a new contact or opens a record, as with a real call.
  -
  - @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
  -->
<template>
	<div v-if="active" class="incoming-call-host">
		<div
			v-if="cardShown"
			class="incoming-call"
			role="dialog"
			aria-modal="false"
			aria-labelledby="incoming-call-title"
			data-testid="incoming-call-card">
			<div class="incoming-call__header">
				<span class="incoming-call__icon" aria-hidden="true">
					<PhoneIncoming :size="22" />
				</span>
				<span class="incoming-call__heading">
					<strong id="incoming-call-title">{{
						t('pipelinq', 'Incoming call')
					}}</strong>
					<span data-testid="incoming-call-line">{{ lineSummary }}</span>
				</span>
				<span
					class="incoming-call__timer"
					data-testid="incoming-call-timer"
					>{{ elapsed }}</span
				>
				<NcButton
					class="incoming-call__close"
					variant="tertiary-no-background"
					:aria-label="t('pipelinq', 'Close')"
					:title="t('pipelinq', 'Close')"
					data-testid="incoming-call-close"
					@click="dismiss">
					<template #icon>
						<Close :size="20" />
					</template>
				</NcButton>
			</div>
			<div class="incoming-call__body" aria-live="polite">
				<NcLoadingIcon v-if="looking" :size="24" />
				<template v-else-if="singleMatch">
					<span class="incoming-call__found">{{
						t('pipelinq', 'Found on the number, 1 client')
					}}</span>
					<div
						class="incoming-call__match"
						data-testid="incoming-call-match">
						<span class="incoming-call__initials" aria-hidden="true">{{
							initials(singleMatch)
						}}</span>
						<span class="incoming-call__names">
							<strong>{{ displayName(singleMatch) }}</strong>
							<span v-if="matchLine(singleMatch)">{{
								matchLine(singleMatch)
							}}</span>
						</span>
					</div>
				</template>
				<span
					v-else-if="manyMatches"
					class="incoming-call__found"
					data-testid="incoming-call-many"
					>{{
						t('pipelinq', 'Found on the number, {count} contacts', {
							count: pop.matches.length,
						})
					}}</span
				>
				<span
					v-else
					class="incoming-call__found"
					data-testid="incoming-call-none"
					>{{ t('pipelinq', 'No client found on this number') }}</span
				>
				<p class="incoming-call__help">
					{{
						t(
							'pipelinq',
							'This call is logged as a contact moment. Open the client to see their requests and earlier contact.',
						)
					}}
				</p>
			</div>
			<div class="incoming-call__actions">
				<template v-if="singleMatch">
					<NcButton
						data-testid="incoming-call-reject"
						@click="rejected = true">
						{{ t('pipelinq', 'Not this client') }}
					</NcButton>
					<span class="incoming-call__spacer" />
					<NcButton
						variant="primary"
						data-testid="incoming-call-open"
						@click="openMatch(singleMatch)">
						<template #icon>
							<AccountOutline :size="16" />
						</template>
						{{ t('pipelinq', 'Answer and open') }}
					</NcButton>
				</template>
				<template v-else-if="manyMatches">
					<span class="incoming-call__spacer" />
					<NcButton
						variant="primary"
						data-testid="incoming-call-choose"
						@click="chooserOpen = true">
						{{ t('pipelinq', 'Choose contact') }}
					</NcButton>
				</template>
				<template v-else-if="!looking">
					<span class="incoming-call__spacer" />
					<NcButton
						variant="primary"
						data-testid="incoming-call-new"
						@click="intakeOpen = true">
						{{ t('pipelinq', 'New contact') }}
					</NcButton>
				</template>
			</div>
		</div>
		<ScreenPopModal
			v-if="chooserOpen"
			:matches="pop.matches"
			:e164="pop.e164 || ''"
			:rawNumber="pop.rawNumber || call.fromNumber"
			@close="chooserOpen = false"
			@select="openMatch"
			@intake="openIntake" />
		<NewContactIntakeModal
			v-if="intakeOpen"
			:e164="pop && pop.e164 ? pop.e164 : ''"
			:prefillPhone="call ? call.fromNumber : ''"
			:queueName="call && call.queueName ? call.queueName : ''"
			@close="intakeOpen = false"
			@submit="createContact" />
		<CtiDispositionModal
			v-if="wrapUp"
			:number="wrapUp.number"
			:clientName="wrapUp.clientName"
			:durationSeconds="wrapUp.durationSeconds"
			:recordedAs="wrapUp.recordedAs"
			:recordedHref="wrapUp.recordedHref"
			:saving="savingWrapUp"
			:queueOptions="queueOptions"
			@close="wrapUp = null"
			@submit="saveWrapUp" />
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import Close from 'vue-material-design-icons/Close.vue'
import PhoneIncoming from 'vue-material-design-icons/PhoneIncoming.vue'
import CtiDispositionModal from '../modals/CtiDispositionModal.vue'
import NewContactIntakeModal from '../modals/NewContactIntakeModal.vue'
import ScreenPopModal from '../modals/ScreenPopModal.vue'
import { escalationQueues, incomingCall, screenPop, submitDisposition } from '../services/ctiApi.js'
import { useObjectStore } from '../store/modules/object.js'

/** How often the card asks again when no live event arrives. */
export const POLL_MS = 15000

export default {
	name: 'IncomingCallCard',

	components: {
		AccountOutline,
		CtiDispositionModal,
		Close,
		NcButton,
		NcLoadingIcon,
		NewContactIntakeModal,
		PhoneIncoming,
		ScreenPopModal,
	},

	data() {
		return {
			/** False until the server says telephony is on for this agent. */
			active: false,
			/** The open call on the agent's line, or null. */
			call: null,
			/** The screen pop's answer for that call's number, or null. */
			pop: null,
			looking: false,
			/** The agent said the single match is not the caller. */
			rejected: false,
			/** The call the agent closed or opened: its card stays away. */
			handledCallId: '',
			/** The call whose screen-pop delay has passed. */
			revealedCallId: '',
			chooserOpen: false,
			intakeOpen: false,
			/** The call the agent wraps up after it ended, or null. */
			wrapUp: null,
			savingWrapUp: false,
			/** The escalation queues the wrap-up offers; loaded when a wrap-up opens. */
			queues: [],
			queuesLoaded: false,
			now: Date.now(),
			pollTimer: null,
			tickTimer: null,
			revealTimer: null,
			liveHandle: null,
			destroyed: false,
		}
	},

	computed: {
		/**
		 * The escalation queues as the wrap-up's select options.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/changes/cti-escalation-queues/specs/cti-screenpop-adapter/spec.md#requirement-the-agent-chooses-the-escalation-queue-at-wrap-up
		 */
		queueOptions() {
			return this.queues.map((queue) => ({ value: queue.id, label: queue.label }))
		},

		/**
		 * @return {object} The shared object store, used for live hints and for
		 *   linking the call to the record the agent opens.
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		objectStore() {
			return useObjectStore()
		},

		/**
		 * @return {boolean} Whether the card is on screen.
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		cardShown() {
			return (
				this.call !== null
				&& this.call.id !== this.handledCallId
				&& this.call.id === this.revealedCallId
			)
		},

		/**
		 * @return {object|null} The one matched caller, unless the agent rejected it.
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		singleMatch() {
			if (this.rejected || !this.pop || this.pop.action !== 'navigate') {
				return null
			}
			return this.pop.matches[0] || null
		},

		/**
		 * @return {boolean} Whether more than one record matched.
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		manyMatches() {
			return (
				!this.rejected
				&& !!this.pop
				&& this.pop.action === 'chooser'
				&& this.pop.matches.length > 1
			)
		},

		/**
		 * The line under the title: number, queue and extension, each only
		 * when the platform reported it.
		 *
		 * @return {string} The summary.
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		lineSummary() {
			const parts = [(this.pop && this.pop.rawNumber) || this.call.fromNumber]
			if (this.call.queueName) {
				parts.push(
					this.t('pipelinq', 'queue {queue}', {
						queue: this.call.queueName,
					}),
				)
			}
			if (this.call.extension) {
				parts.push(
					this.t('pipelinq', 'line {line}', { line: this.call.extension }),
				)
			}
			return parts.filter(Boolean).join(' · ')
		},

		/**
		 * @return {string} Time since the call started, as m:ss.
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		elapsed() {
			const started = Date.parse(this.call.startedAt)
			const seconds = Number.isNaN(started)
				? 0
				: Math.max(0, Math.floor((this.now - started) / 1000))
			return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
		},

		/**
		 * @return {number} The store's last live event, watched as a refetch hint.
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		liveLastEventAt() {
			return this.objectStore.liveLastEventAt
		},
	},

	watch: {
		/**
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		liveLastEventAt() {
			if (this.active) {
				this.refresh()
			}
		},

		/**
		 * A wrap-up opened: load the escalation queues it offers, once.
		 *
		 * @param {object|null} wrapUp The call being wrapped up.
		 * @spec openspec/changes/cti-escalation-queues/specs/cti-screenpop-adapter/spec.md#requirement-the-agent-chooses-the-escalation-queue-at-wrap-up
		 */
		async wrapUp(wrapUp) {
			if (!wrapUp || this.queuesLoaded) {
				return
			}
			this.queuesLoaded = true
			try {
				this.queues = await escalationQueues()
			} catch {
				// Without the list the dialog leaves the choice out; the server falls back to the first queue.
				this.queuesLoaded = false
			}
		},
	},

	/**
	 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
	 */
	mounted() {
		this.refresh()
	},

	/**
	 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
	 */
	beforeUnmount() {
		this.destroyed = true
		this.stop()
	},

	methods: {
		/**
		 * Ask which call is on the agent's line. The first answer decides
		 * whether the card watches at all: a refusal, an error or "not
		 * enabled" stops it for good, silently.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		async refresh() {
			const preview = this.previewCall()
			if (preview !== null) {
				if (this.call === null) {
					this.active = true
					this.receive(preview, 0)
				}
				return
			}
			let answer
			try {
				answer = await incomingCall()
			} catch {
				this.stop()
				return
			}
			if (this.destroyed) {
				return
			}
			if (!answer || answer.enabled !== true) {
				this.stop()
				return
			}
			this.watch()
			this.receive(answer.call || null, Number(answer.delayMs) || 0)
		},

		/**
		 * Take the server's answer. A new call starts one caller lookup; the
		 * same call again changes nothing; no call clears the card.
		 *
		 * @param {object|null} call The open call, or null.
		 * @param {number} delayMs How long to wait before the card shows.
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		receive(call, delayMs) {
			if (call === null) {
				if (this.call !== null && this.call.id === this.handledCallId) {
					this.wrapUp = this.wrapUpFor(this.call)
				}
				this.clearCall()
				return
			}
			if (this.call !== null && this.call.id === call.id) {
				this.call = call
				return
			}
			this.clearCall()
			this.call = call
			this.tickTimer = setInterval(() => {
				this.now = Date.now()
			}, 1000)
			this.revealTimer = setTimeout(() => {
				this.revealedCallId = call.id
			}, delayMs)
			this.lookUp(call)
		},

		/**
		 * Look the caller up by the call's number. A failed lookup reads as
		 * "no client found": the agent can still add the caller.
		 *
		 * @param {object} call The call to look up.
		 * @return {Promise<void>}
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		async lookUp(call) {
			this.looking = true
			const result = await screenPop(call.fromNumber).catch(() => null)
			if (this.call === null || this.call.id !== call.id) {
				return
			}
			this.pop = {
				action: (result && result.action) || 'intake',
				matches: Array.isArray(result && result.matches)
					? result.matches
					: [],

				e164: (result && result.e164) || '',
				rawNumber: (result && result.rawNumber) || '',
			}
			this.looking = false
			if (call.previewOpen === 'chooser' && this.pop.matches.length > 1) {
				this.chooserOpen = true
			}
			if (call.previewOpen === 'intake') {
				this.intakeOpen = true
			}
			if (call.previewOpen === 'disposition') {
				this.wrapUp = this.wrapUpFor(call)
			}
		},

		/**
		 * The previewed call named in the address (`callPreview`,
		 * `callPreviewOpen`), or null when there is none.
		 *
		 * @return {object|null} A call without a line behind it.
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		previewCall() {
			const params = new URLSearchParams(window.location.search)
			const number = (params.get('callPreview') || '').trim()
			if (number === '') {
				return null
			}
			return {
				id: 'preview',
				fromNumber: number,
				startedAt: new Date().toISOString(),
				previewOpen: params.get('callPreviewOpen') || '',
			}
		},

		/**
		 * Start watching once: live events on tickets as a hint, and a slow
		 * poll for an instance without live updates.
		 *
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		async watch() {
			if (this.active) {
				return
			}
			this.active = true
			this.pollTimer = setInterval(() => this.refresh(), POLL_MS)
			if (typeof this.objectStore.subscribe !== 'function') {
				return
			}
			try {
				const handle = await this.objectStore.subscribe('ticket')
				if (this.destroyed || !this.active) {
					this.objectStore.unsubscribe(handle)
					return
				}
				this.liveHandle = handle
			} catch {
				// The poll covers an instance without live updates.
			}
		},

		/**
		 * Stop watching and clear the card.
		 *
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		stop() {
			this.active = false
			clearInterval(this.pollTimer)
			this.pollTimer = null
			if (this.liveHandle !== null) {
				this.objectStore.unsubscribe(this.liveHandle)
				this.liveHandle = null
			}
			this.clearCall()
		},

		/**
		 * Forget the current call and everything shown for it.
		 *
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		clearCall() {
			clearInterval(this.tickTimer)
			clearTimeout(this.revealTimer)
			this.tickTimer = null
			this.revealTimer = null
			this.call = null
			this.pop = null
			this.looking = false
			this.rejected = false
			this.chooserOpen = false
			this.intakeOpen = false
		},

		/**
		 * What the wrap-up shows for a call: its number, who called (when the
		 * screen pop found one), how long it took and the contact moment.
		 *
		 * @param {object} call The call that ended.
		 * @return {object} The wrap-up.
		 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-5.4
		 */
		wrapUpFor(call) {
			const started = Date.parse(call.startedAt)
			const match = this.pop && this.pop.matches.length === 1 ? this.pop.matches[0] : null
			const reference = call.reference || ''
			return {
				callId: call.id,
				preview: call.id === 'preview',
				number: (this.pop && this.pop.rawNumber) || call.fromNumber || '',
				clientName: match ? this.displayName(match) : '',
				durationSeconds: Number.isNaN(started) ? -1 : Math.max(0, Math.round((Date.now() - started) / 1000)),
				recordedAs: reference
					? this.t('pipelinq', 'Contact moment at {reference}', { reference })
					: this.t('pipelinq', 'Contact moment'),

				recordedHref: call.id === 'preview' ? '' : this.$router.resolve({ name: 'TicketDetail', params: { id: call.id } }).href,
			}
		},

		/**
		 * Record the wrap-up on the call's contact moment. A previewed call
		 * has none, so its wrap-up only closes.
		 *
		 * @param {object} form Subject, outcome and notes.
		 * @return {Promise<void>}
		 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-5.4
		 */
		async saveWrapUp(form) {
			if (!this.wrapUp || this.wrapUp.preview) {
				this.wrapUp = null
				return
			}
			this.savingWrapUp = true
			try {
				await submitDisposition(this.wrapUp.callId, form)
				this.wrapUp = null
			} catch {
				showError(this.t('pipelinq', 'The call outcome is not saved. Try again.'))
			} finally {
				this.savingWrapUp = false
			}
		},

		/**
		 * Close the card for this call. The next call shows again.
		 *
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		dismiss() {
			this.handledCallId = this.call.id
		},

		/**
		 * From the chooser to the new-contact form.
		 *
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		openIntake() {
			this.chooserOpen = false
			this.intakeOpen = true
		},

		/**
		 * Open the matched record and link the call to it.
		 *
		 * @param {object} match A matched client or contact.
		 * @return {Promise<void>}
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		async openMatch(match) {
			const type = match._matchType === 'client' ? 'client' : 'contact'
			const id = match.id || match.uuid
			const callId = this.call.id
			this.chooserOpen = false
			this.handledCallId = callId
			await this.linkCall(callId, { [type]: id })
			this.$router.push({
				name: type === 'client' ? 'ClientDetail' : 'ContactDetail',
				params: { id },
			})
		},

		/**
		 * Store the caller as a new contact, link the call and open them.
		 *
		 * @param {object} form What the agent typed in the new-contact form.
		 * @return {Promise<void>}
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		async createContact(form) {
			const callId = this.call.id
			const contact = { name: form.name.trim(), phone: form.phone.trim() }
			if (form.email && form.email.trim() !== '') {
				contact.email = form.email.trim()
			}
			const saved = await this.objectStore
				.saveObject('contact', contact)
				.catch(() => null)
			if (!saved || !(saved.id || saved.uuid)) {
				showError(this.t('pipelinq', 'The contact is not saved. Try again.'))
				return
			}
			const id = saved.id || saved.uuid
			this.intakeOpen = false
			this.handledCallId = callId
			const notes = [form.organisation, form.notes]
				.map((part) => (part || '').trim())
				.filter(Boolean)
				.join('\n')
			await this.linkCall(
				callId,
				notes ? { contact: id, notes } : { contact: id },
			)
			this.$router.push({ name: 'ContactDetail', params: { id } })
		},

		/**
		 * Write the chosen record onto the call's contact moment. The agent
		 * is on the phone, so a failed link never blocks opening the record:
		 * it is logged and the agent can link the contact moment by hand.
		 *
		 * @param {string} callId The call's contact moment.
		 * @param {object} fields The fields to set on it.
		 * @return {Promise<boolean>} Whether the link was written.
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		async linkCall(callId, fields) {
			try {
				const current = await this.objectStore.fetchObject('ticket', callId)
				if (!current) {
					return false
				}
				const saved = await this.objectStore.saveObject('ticket', {
					...current,
					id: callId,
					...fields,
				})
				return !!saved
			} catch (e) {
				console.warn(
					'[IncomingCallCard] the call is not linked:',
					e?.message ?? e,
				)
				return false
			}
		},

		/**
		 * @param {object} match A matched client or contact.
		 * @return {string} The name to show.
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		displayName(match) {
			return (
				match.displayName
				|| match.name
				|| `${match.firstName || ''} ${match.lastName || ''}`.trim()
				|| this.t('pipelinq', 'Unknown contact')
			)
		},

		/**
		 * @param {object} match A matched client or contact.
		 * @return {string} Up to two initials for the avatar.
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		initials(match) {
			return this.displayName(match)
				.split(/\s+/)
				.filter(Boolean)
				.map((word) => word[0].toUpperCase())
				.filter(
					(letter, index, all) => index === 0 || index === all.length - 1,
				)
				.join('')
		},

		/**
		 * The line under a match's name: the preferred name they asked for,
		 * and the organisation a contact person belongs to.
		 *
		 * @param {object} match A matched client or contact.
		 * @return {string} The line, or ''.
		 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
		 */
		matchLine(match) {
			const preferred =
				typeof match.preferredName === 'string'
					? match.preferredName.trim()
					: ''
			return [
				preferred !== '' && preferred !== this.displayName(match)
					? preferred
					: '',
				match.clientName || match.organisation || '',
			]
				.filter(Boolean)
				.join(' · ')
		},
	},
}
</script>

<style scoped>
.incoming-call {
	position: fixed;
	top: calc(var(--header-height, 50px) + 34px);
	inset-inline-end: 24px;
	z-index: 2000;
	width: min(400px, calc(100vw - 32px));
	display: flex;
	flex-direction: column;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	box-shadow: 0 12px 40px var(--color-box-shadow);
}

.incoming-call__header {
	display: flex;
	gap: 12px;
	align-items: center;
	padding: 16px 18px;
	border-radius: var(--border-radius-large, 12px) var(--border-radius-large, 12px)
		0 0;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.incoming-call__icon {
	width: 40px;
	height: 40px;
	flex: none;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: 50%;
	background: var(--color-primary-element-text);
	color: var(--color-primary-element);
}

.incoming-call__heading {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
}

.incoming-call__timer {
	font-weight: 600;
	font-variant-numeric: tabular-nums;
}

.incoming-call__close {
	color: var(--color-primary-element-text);
}

.incoming-call__body {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px 18px;
}

.incoming-call__found,
.incoming-call__help,
.incoming-call__names span {
	color: var(--color-text-maxcontrast);
}

.incoming-call__help {
	margin: 0;
}

.incoming-call__match {
	display: flex;
	gap: 12px;
	align-items: center;
}

.incoming-call__initials {
	width: 40px;
	height: 40px;
	flex: none;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	border-radius: 50%;
	background: var(--color-background-dark);
	font-weight: 700;
}

.incoming-call__names {
	display: flex;
	flex-direction: column;
}

.incoming-call__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	padding: 14px 18px;
	border-top: 1px solid var(--color-border);
}

.incoming-call__spacer {
	flex: 1;
}
</style>
