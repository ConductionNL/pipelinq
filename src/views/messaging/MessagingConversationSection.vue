<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - MessagingConversationSection is the client/contact-detail in-body section
  - (kind:'section' bodyWidget) for the outbound WhatsApp/SMS feature
  - (outbound-messaging-provider-wiring). It self-fetches `channelConversation` /
  - `channelMessage` OpenRegister rows filtered by contactId and the composer
  - preflight facts from GET /api/messaging/preflight/{contactId}.
  -
  - The message/conversation schemas only carry a contactId FK (there is no
  - client-level FK — WhatsApp/SMS numbers belong to a person, not an
  - organisation). When mounted on ContactDetail (entityType 'contact') the
  - contactId is the page object id directly. When mounted on ClientDetail
  - (entityType 'client') there is no single contactId, so this section
  - resolves the client's linked contacts client-side (the same cross-schema
  - join pattern already used by PosRefundItemsWidget / ProjectDetail,
  - since OpenRegister has no native cross-schema join) and lets the agent
  - pick which contact person to converse with via a picker.
  -
  - @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
  -->
<template>
	<section class="messaging-conversation">
		<header class="messaging-conversation__head">
			<h3>
				{{ t('pipelinq', 'Messages') }}
				<span
					v-if="latestConversation"
					class="messaging-conversation__badge">
					{{ conversationStatusLabel(latestConversation.status) }}
				</span>
			</h3>
			<NcButton
				variant="primary"
				:disabled="!effectiveContactId"
				@click="openComposer">
				{{ t('pipelinq', 'Send message') }}
			</NcButton>
		</header>

		<div
			v-if="entityType === 'client'"
			class="messaging-conversation__contact-picker">
			<NcLoadingIcon v-if="loadingContacts" :size="20" />
			<NcSelect
				v-else-if="contactOptions.length !== 0"
				v-model="selectedContactId"
				:options="contactOptions"
				:inputLabel="t('pipelinq', 'Contact')"
				label="label"
				:reduce="(o) => o.id" />
			<NcEmptyContent
				v-else
				:description="
					t(
						'pipelinq',
						'No contacts are linked to this client yet. Add a contact to send messages.',
					)
				" />
		</div>

		<div v-if="effectiveContactId" class="messaging-conversation__consent">
			<span class="messaging-conversation__consent-item">
				{{ t('pipelinq', 'SMS consent:') }}
				<strong :class="consentClass(preflightConsent.sms)">{{
					/**
					 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
					 */
					consentLabel(preflightConsent.sms)
				}}</strong>
			</span>
			<span class="messaging-conversation__consent-item">
				{{ t('pipelinq', 'WhatsApp consent:') }}
				<strong :class="consentClass(preflightConsent.whatsapp)">{{
					consentLabel(preflightConsent.whatsapp)
				}}</strong>
			</span>
			<span class="messaging-conversation__consent-item">
				{{ t('pipelinq', 'WhatsApp session:') }}
				<strong
					:class="
						preflight && preflight.whatsappSessionOpen
							? 'messaging-conversation__consent--ok'
							: 'messaging-conversation__consent--warn'
					">
					{{
						preflight && preflight.whatsappSessionOpen
							? t('pipelinq', 'Open (24h window)')
							: t('pipelinq', 'Closed — template required')
					}}
				</strong>
			</span>
		</div>

		<NcLoadingIcon v-if="loading" :size="28" />

		<NcEmptyContent
			v-else-if="effectiveContactId && messages.length === 0"
			:description="t('pipelinq', 'No messages yet.')" />

		<ul v-else-if="messages.length > 0" class="messaging-conversation__list">
			<li
				v-for="message in messages"
				:key="message.id"
				class="messaging-conversation__item"
				:class="`messaging-conversation__item--${message.direction}`">
				<div class="messaging-conversation__item-meta">
					<span class="messaging-conversation__channel">{{
						message.channel
					}}</span>
					<span class="messaging-conversation__direction">{{
						/**
						 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
						 */
						directionLabel(message.direction)
					}}</span>
					<span
						class="messaging-conversation__status"
						:class="deliveryStatusClass(message.deliveryStatus)">
						{{ message.deliveryStatus || 'queued' }}
					</span>
					<span class="messaging-conversation__time">{{
						formatDate(message.sentAt)
					}}</span>
				</div>
				<p class="messaging-conversation__body">
					{{ message.body || '—' }}
				</p>
				<div
					v-if="canResend(message)"
					class="messaging-conversation__resend">
					<NcButton
						variant="secondary"
						:disabled="resending === message.id"
						data-testid="messaging-resend"
						@click="resend(message)">
						{{
							resending === message.id
								? t('pipelinq', 'Sending…')
								: t('pipelinq', 'Send again')
						}}
					</NcButton>
					<span
						v-if="resendErrors[message.id]"
						class="messaging-conversation__status--error"
						role="alert">
						{{ resendErrors[message.id] }}
					</span>
				</div>
			</li>
		</ul>

		<SendMessageModal
			v-if="showComposer"
			:contactId="effectiveContactId"
			:clientId="effectiveClientId"
			:preflight="preflightForModal"
			:initialChannel="composerChannel"
			:placeholderValues="placeholderValues"
			:language="contactLanguage"
			@sent="onSent"
			@close="closeComposer" />
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import SendMessageModal from '../../modals/SendMessageModal.vue'
import { PAGE_REFRESH_CHANNEL } from '../../services/pageRefreshOnCreate.js'
import { useObjectStore } from '../../store/modules/object.js'

const EMPTY_PREFLIGHT = {
	channels: { sms: false, whatsapp: false },
	whatsappSessionOpen: false,
	consent: { sms: 'unknown', whatsapp: 'unknown' },
	templates: [],
}

export default {
	name: 'MessagingConversationSection',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		SendMessageModal,
	},

	inject: {
		cnSectionContext: { default: null },
	},

	props: {
		/** Contact or client OpenRegister UUID for the page being viewed. */
		entityId: {
			type: String,
			required: true,
		},

		/** Which kind of entity entityId refers to. */
		entityType: {
			type: String,
			required: true,
			validator: (value) => ['client', 'contact'].includes(value),
		},
	},

	data() {
		return {
			loading: false,
			loadingContacts: false,
			messages: [],
			conversations: [],
			linkedContacts: [],
			selectedContactId: '',
			resolvedClientId: '',
			preflight: null,
			showComposer: false,
			composerChannel: '',
			contactRecord: null,
			clientRecord: null,
			resending: '',
			resendErrors: {},
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		objectStore() {
			return useObjectStore()
		},

		/** The contactId every fetch/send in this component is keyed on. */
		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		effectiveContactId() {
			return this.entityType === 'contact'
				? this.entityId
				: this.selectedContactId
		},

		/** The clientId passed to the composer for the send-request audit trail. */
		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		effectiveClientId() {
			return this.entityType === 'client'
				? this.entityId
				: this.resolvedClientId
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		contactOptions() {
			return this.linkedContacts.map((c) => ({
				id: c.id,
				label: c.name || c.id,
			}))
		},

		/**
		 * The selected contact, from the page or from the client's contacts.
		 *
		 * @return {object|null}
		 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-inserts-a-saved-reply-where-they-answer-req-msr-003
		 */
		selectedContact() {
			if (this.entityType === 'contact') {
				return this.contactRecord
			}
			return (
				this.linkedContacts.find((c) => c.id === this.selectedContactId)
				|| null
			)
		},

		/**
		 * Saved reply placeholder values this section holds.
		 *
		 * @return {object}
		 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-inserts-a-saved-reply-where-they-answer-req-msr-003
		 */
		placeholderValues() {
			return {
				'contact.name':
					(this.selectedContact && this.selectedContact.name) || '',

				'client.name': (this.clientRecord && this.clientRecord.name) || '',
			}
		},

		/**
		 * The contact's correspondence language.
		 *
		 * @return {string}
		 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-inserts-a-saved-reply-where-they-answer-req-msr-003
		 */
		contactLanguage() {
			const contact = this.selectedContact
			return (
				(contact && (contact.correspondenceLanguage || contact.language))
				|| ''
			)
		},

		preflightConsent() {
			return (
				(this.preflight && this.preflight.consent) || EMPTY_PREFLIGHT.consent
			)
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		preflightForModal() {
			return this.preflight || EMPTY_PREFLIGHT
		},

		/** Most recently active conversation thread for the current contact, if any. */
		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		latestConversation() {
			if (this.conversations.length === 0) {
				return null
			}
			return this.conversations.slice().sort((a, b) => {
				const at = a.lastInboundAt ? new Date(a.lastInboundAt).getTime() : 0
				const bt = b.lastInboundAt ? new Date(b.lastInboundAt).getTime() : 0
				return bt - at
			})[0]
		},
	},

	watch: {
		effectiveContactId(newValue, oldValue) {
			if (newValue === oldValue) {
				return
			}
			this.fetchMessages()
			this.fetchConversations()
			this.fetchPreflight()
		},
	},

	/**
	 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
	 */
	async mounted() {
		subscribe(PAGE_REFRESH_CHANNEL, this.onPageRefresh)
		if (this.entityType === 'contact') {
			await this.resolveContactClient()
			await Promise.all([
				this.fetchMessages(),
				this.fetchConversations(),
				this.fetchPreflight(),
			])
		} else {
			await this.fetchLinkedContacts()
		}
	},

	beforeUnmount() {
		unsubscribe(PAGE_REFRESH_CHANNEL, this.onPageRefresh)
	},

	methods: {
		/**
		 * A page refresh, sent too when a contact is created on the page:
		 * on a client, fetch its contacts again so a contact added on the
		 * Contacts tab shows here without a reload (round-5 cloud check,
		 * item 2).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/r6-contact-activity-relations-copy/specs/client-management/spec.md#requirement-cards-on-the-client-page-follow-a-contact-added-on-that-page
		 */
		async onPageRefresh() {
			if (this.entityType === 'client') {
				await this.fetchLinkedContacts()
			}
		},

		/**
		 * @param {string} status The conversation status.
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		conversationStatusLabel(status) {
			const labels = {
				open: t('pipelinq', 'Open'),
				assigned: t('pipelinq', 'Assigned'),
				closed: t('pipelinq', 'Closed'),
			}
			return labels[status] || status
		},

		directionLabel(direction) {
			return direction === 'inbound'
				? t('pipelinq', 'Received')
				: t('pipelinq', 'Sent')
		},

		/**
		 * @param {string} status The delivery status.
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		deliveryStatusClass(status) {
			if (status === 'failed' || status === 'expired') {
				return 'messaging-conversation__status--error'
			}
			if (status === 'delivered' || status === 'read') {
				return 'messaging-conversation__status--ok'
			}
			return 'messaging-conversation__status--pending'
		},

		consentLabel(state) {
			const labels = {
				'opted-in': t('pipelinq', 'Opted in'),
				'opted-out': t('pipelinq', 'Opted out'),
				unknown: t('pipelinq', 'Unknown'),
			}
			return labels[state] || labels.unknown
		},

		/**
		 * @param {string} state The consent state.
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		consentClass(state) {
			if (state === 'opted-in') {
				return 'messaging-conversation__consent--ok'
			}
			if (state === 'opted-out') {
				return 'messaging-conversation__consent--error'
			}
			return 'messaging-conversation__consent--warn'
		},

		formatDate(value) {
			if (!value) {
				return '—'
			}
			const parsed = new Date(value)
			if (Number.isNaN(parsed.getTime())) {
				return value
			}
			return parsed.toLocaleString()
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		async resolveContactClient() {
			try {
				const contact = await this.objectStore.fetchObject(
					'contact',
					this.entityId,
				)
				this.contactRecord = contact || null
				this.resolvedClientId = (contact && contact.client) || ''
				await this.fetchClient(this.resolvedClientId)
			} catch {
				this.resolvedClientId = ''
			}
		},

		/**
		 * Fetch the client's contacts. A refetch keeps the selected contact when it is still linked.
		 *
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 * @spec openspec/changes/r6-contact-activity-relations-copy/specs/client-management/spec.md#requirement-cards-on-the-client-page-follow-a-contact-added-on-that-page
		 */
		async fetchLinkedContacts() {
			this.loadingContacts = true
			this.fetchClient(this.entityId)
			try {
				this.linkedContacts =
					(await this.objectStore.fetchCollection('contact', {
						client: this.entityId,
						_limit: 100,
					})) || []
				const stillLinked = this.linkedContacts.some(
					(c) => c.id === this.selectedContactId,
				)
				if (this.linkedContacts.length > 0 && !stillLinked) {
					this.selectedContactId = this.linkedContacts[0].id
				}
			} catch {
				this.linkedContacts = []
			} finally {
				this.loadingContacts = false
			}
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-the-messages-section-lists-the-contacts-messages-req-msr-001
		 */
		async fetchMessages() {
			if (!this.effectiveContactId) {
				this.messages = []
				return
			}
			this.loading = true
			try {
				const rows =
					(await this.objectStore.fetchCollection('channelMessage', {
						contactId: this.effectiveContactId,
						_limit: 200,
					})) || []
				this.messages = rows.slice().sort((a, b) => {
					const at = a.sentAt ? new Date(a.sentAt).getTime() : 0
					const bt = b.sentAt ? new Date(b.sentAt).getTime() : 0
					return bt - at
				})
			} catch {
				this.messages = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		async fetchConversations() {
			if (!this.effectiveContactId) {
				this.conversations = []
				return
			}
			try {
				this.conversations =
					(await this.objectStore.fetchCollection('channelConversation', {
						contactId: this.effectiveContactId,
						_limit: 50,
					})) || []
			} catch {
				this.conversations = []
			}
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		async fetchPreflight() {
			if (!this.effectiveContactId) {
				this.preflight = null
				return
			}
			try {
				const { data } = await axios.get(
					generateUrl(
						'/apps/pipelinq/api/messaging/preflight/{contactId}',
						{ contactId: this.effectiveContactId },
					),
				)
				this.preflight = data
			} catch {
				this.preflight = null
			}
		},

		/**
		 * Read the client's name for the saved reply placeholders.
		 *
		 * @param {string} clientId The client id, or empty.
		 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-inserts-a-saved-reply-where-they-answer-req-msr-003
		 */
		async fetchClient(clientId) {
			if (!clientId) {
				this.clientRecord = null
				return
			}
			try {
				this.clientRecord =
					(await this.objectStore.fetchObject('client', clientId)) || null
			} catch {
				this.clientRecord = null
			}
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		openComposer() {
			if (!this.effectiveContactId) {
				return
			}
			this.composerChannel = ''
			this.showComposer = true
		},

		/**
		 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-sends-a-failed-message-again-req-msr-006
		 */
		closeComposer() {
			this.showComposer = false
			this.composerChannel = ''
		},

		/**
		 * Whether a row offers Send again: an outbound WhatsApp or SMS that
		 * failed or expired and was not sent again yet.
		 *
		 * @param {object} message A channelMessage row.
		 * @return {boolean}
		 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-sends-a-failed-message-again-req-msr-006
		 */
		canResend(message) {
			const metadata = (message && message.metadata) || {}
			return (
				!!message
				&& message.direction === 'outbound'
				&& ['sms', 'whatsapp'].includes(message.channel)
				&& ['failed', 'expired'].includes(message.deliveryStatus)
				&& !metadata.resentAs
			)
		},

		/**
		 * Send a failed message again. A closed WhatsApp window opens the
		 * composer on WhatsApp for an approved template; any other refusal
		 * shows the server's reason on the row.
		 *
		 * @param {object} message The failed row.
		 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-sends-a-failed-message-again-req-msr-006
		 */
		async resend(message) {
			if (this.resending) {
				return
			}
			this.resending = message.id
			this.resendErrors = { ...this.resendErrors, [message.id]: '' }
			let status
			try {
				const { data } = await axios.post(
					generateUrl(
						'/apps/pipelinq/api/messaging/messages/{id}/resend',
						{
							id: message.id,
						},
					),
				)
				status = (data && data.status) || 'failed'
			} catch (error) {
				status = error?.response?.data?.status || 'failed'
			} finally {
				this.resending = ''
			}
			if (status === 'sent') {
				this.onSent()
				return
			}
			if (status === 'template-required') {
				this.composerChannel = 'whatsapp'
				this.showComposer = true
				return
			}
			this.resendErrors = {
				...this.resendErrors,
				[message.id]: this.resendReason(status),
			}
		},

		/**
		 * The text for a refused Send again.
		 *
		 * @param {string} status The server's status.
		 * @return {string}
		 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-sends-a-failed-message-again-req-msr-006
		 */
		resendReason(status) {
			const reasons = {
				'consent-missing': t(
					'pipelinq',
					'Not sent: the contact has not given consent for this channel.',
				),

				'budget-exceeded': t(
					'pipelinq',
					'Not sent: the messaging budget for this period is used up.',
				),

				'no-provider': t(
					'pipelinq',
					'Not sent: no provider is set up for this channel.',
				),

				'template-invalid': t(
					'pipelinq',
					'Not sent: the template is no longer approved.',
				),

				'already-resent': t(
					'pipelinq',
					'This message was already sent again.',
				),

				'not-failed': t(
					'pipelinq',
					'This message did not fail, so it is not sent again.',
				),
			}
			return (
				reasons[status]
				|| t('pipelinq', 'Sending failed again. Try again later.')
			)
		},

		/**
		 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
		 */
		onSent() {
			this.showComposer = false
			this.fetchMessages()
			this.fetchConversations()
			this.fetchPreflight()
		},
	},
}
</script>

<style scoped>
.messaging-conversation {
	margin-bottom: 8px;
}

.messaging-conversation__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;
}

.messaging-conversation h3 {
	margin: 0;
	font-weight: 600;
	display: flex;
	align-items: center;
	gap: 8px;
}

.messaging-conversation__badge {
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 12px);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.messaging-conversation__contact-picker {
	margin-bottom: 12px;
	max-width: 320px;
}

.messaging-conversation__consent {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	margin-bottom: 12px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.messaging-conversation__consent-item strong {
	margin-inline-start: 4px;
}

.messaging-conversation__consent--ok {
	color: var(--color-success-text, var(--color-success));
}

.messaging-conversation__consent--warn {
	color: var(--color-warning-text, var(--color-warning));
}

.messaging-conversation__consent--error {
	color: var(--color-error-text, var(--color-error));
}

.messaging-conversation__resend {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 4px;
}

.messaging-conversation__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-height: 420px;
	overflow-y: auto;
}

.messaging-conversation__item {
	padding: 8px 12px;
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
	max-width: 80%;
}

.messaging-conversation__item--outbound {
	align-self: flex-end;
	background: var(--color-primary-element-light);
}

.messaging-conversation__item-meta {
	display: flex;
	gap: 8px;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
	margin-bottom: 4px;
}

.messaging-conversation__status--ok {
	color: var(--color-success-text, var(--color-success));
}

.messaging-conversation__status--error {
	color: var(--color-error-text, var(--color-error));
}

.messaging-conversation__status--pending {
	color: var(--color-text-maxcontrast);
}

.messaging-conversation__body {
	margin: 0;
	white-space: pre-wrap;
	overflow-wrap: break-word;
}
</style>
