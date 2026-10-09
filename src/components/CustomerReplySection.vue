<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Ticket-detail in-body section (kind:'section'): answer the customer or
  - resident on a request or complaint. The minimal answer control of
  - messaging-saved-replies-and-resend D5, built for the Woo citizen journey
  - (questions-about-a-citizen-dossier, REQ-QCD-007); the saved reply
  - picker (channel `portal`) fills the text area since
  - messaging-saved-replies-and-resend.
  -
  - Shows the replies sent from the portal (`portalReplies`), oldest first,
  - and a text area bound to `customerMessage`. "Send answer" writes the
  - message; with "Also set the ticket to waiting for the customer" ticked
  - (the default, as the PqTicketAntwoord board draws it) it also sets
  - `awaiting_customer`, the status a portal reply resumes from. The store's
  - saveObject() is a PUT that
  - replaces the whole object, so the section re-reads the ticket and sends it
  - back with only these fields changed. Saving changes `customerMessage`,
  - which QuestionAnsweredListener hears: it tells the resident "Uw vraag is
  - beantwoord" under the rule key `pipelinq.question.answered`.
  - A changed answer is also added to `portalAnswers` with its moment, so the
  - resident reads every answer and when it came (question-detail-on-the-portal).
  -
  - The manifest's bodyWidget title ("Customer contact") is the h3 that
  - CnDetailPage renders above the section, so the section never repeats it.
  - Inside it two h4 headings keep the two directions apart: "Replies from
  - the customer" over `portalReplies` (what the resident wrote, see
  - DossierQuestionService::reply and PortalRequestService::addReply) and
  - "Answer to the customer" over the employee's own message. A single
  - "Answer to the customer" frame put the resident's words under the
  - employee's heading (Woo round 5).
  -->
<template>
	<section
		v-if="applies"
		class="customer-reply-section"
		:aria-label="t('pipelinq', 'Customer contact')">
		<h4 class="customer-reply-section__heading">
			{{ t('pipelinq', 'Replies from the customer') }}
		</h4>
		<ol v-if="replies.length" class="customer-reply-section__replies">
			<li v-for="(reply, index) in replies" :key="index">
				<span class="customer-reply-section__meta">{{
					formatDate(reply.createdAt)
				}}</span>
				<span>{{ reply.message }}</span>
			</li>
		</ol>
		<p v-else class="customer-reply-section__meta">
			{{ t('pipelinq', 'No replies from the portal yet.') }}
		</p>
		<h4 class="customer-reply-section__heading">
			{{ t('pipelinq', 'Answer to the customer') }}
		</h4>
		<SavedReplyPicker
			channel="portal"
			:language="language"
			:values="placeholderValues"
			@pick="message = $event" />
		<NcTextArea
			v-model="message"
			:label="t('pipelinq', 'Message to the customer')"
			:helperText="
				t(
					'pipelinq',
					'The customer reads this on the portal. Everything else on the ticket stays internal.',
				)
			"
			resize="vertical" />
		<NcCheckboxRadioSwitch
			v-model="waitForCustomer"
			data-testid="customer-reply-wait">
			{{ t('pipelinq', 'Also set the ticket to waiting for the customer') }}
		</NcCheckboxRadioSwitch>
		<div class="customer-reply-section__actions">
			<NcButton
				variant="primary"
				:disabled="busy || !canSave"
				data-testid="customer-reply-send"
				@click="save(waitForCustomer)">
				{{ t('pipelinq', 'Send answer') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="notice" :type="noticeType">
			{{ notice }}
		</NcNoteCard>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcTextArea } from '@nextcloud/vue'
import SavedReplyPicker from './SavedReplyPicker.vue'
import { useObjectStore } from '../store/modules/object.js'

/** The ticket kinds a customer can be answered on. */
const ANSWERABLE = ['request', 'complaint']

export default {
	name: 'CustomerReplySection',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcTextArea,
		SavedReplyPicker,
	},

	props: {
		/** The ticket id, token-resolved from `@objectId`. */
		objectId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			ticket: null,
			message: '',
			waitForCustomer: true,
			client: null,
			contact: null,
			busy: false,
			notice: '',
			noticeType: 'success',
		}
	},

	computed: {
		/**
		 * Whether this ticket is one a customer can be answered on.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-answers-from-the-ticket-req-qcd-007
		 */
		applies() {
			return !!this.ticket && ANSWERABLE.includes(this.ticket.ticketType)
		},

		/**
		 * The portal replies, oldest first.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-answers-from-the-ticket-req-qcd-007
		 */
		replies() {
			const list =
				this.ticket && Array.isArray(this.ticket.portalReplies)
					? this.ticket.portalReplies
					: []
			return list
				.filter((reply) => reply && typeof reply === 'object')
				.slice()
				.sort((a, b) =>
					String(a.createdAt || '').localeCompare(
						String(b.createdAt || ''),
					),
				)
		},

		/**
		 * Whether there is an answer to save.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-answers-from-the-ticket-req-qcd-007
		 */
		canSave() {
			return this.message.trim() !== ''
		},

		/**
		 * Saved reply placeholder values for this ticket.
		 *
		 * @return {object}
		 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-inserts-a-saved-reply-where-they-answer-req-msr-003
		 */
		placeholderValues() {
			return {
				'ticket.title': (this.ticket && this.ticket.title) || '',
				'client.name': (this.client && this.client.name) || '',
				'contact.name': (this.contact && this.contact.name) || '',
			}
		},

		/**
		 * The customer's correspondence language, contact first.
		 *
		 * @return {string}
		 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-inserts-a-saved-reply-where-they-answer-req-msr-003
		 */
		language() {
			const party = this.contact || this.client
			return (party && (party.correspondenceLanguage || party.language)) || ''
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/**
			 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-answers-from-the-ticket-req-qcd-007
			 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,

		/**
		 * Read the ticket and put its current message in the text area.
		 *
		 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-answers-from-the-ticket-req-qcd-007
		 */
		async load() {
			this.ticket = this.objectId
				? await useObjectStore().fetchObject('ticket', this.objectId)
				: null
			this.message = (this.ticket && this.ticket.customerMessage) || ''
			await this.loadParties()
		},

		/**
		 * Read the ticket's client and contact for the saved reply placeholders.
		 * A party that cannot be read leaves its placeholder as written.
		 *
		 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-inserts-a-saved-reply-where-they-answer-req-msr-003
		 */
		async loadParties() {
			const store = useObjectStore()
			const read = async (type, id) => {
				if (!id || typeof id !== 'string') {
					return null
				}
				try {
					return (await store.fetchObject(type, id)) || null
				} catch {
					return null
				}
			}
			this.client = await read('client', this.ticket && this.ticket.client)
			this.contact = await read('contact', this.ticket && this.ticket.contact)
		},

		/**
		 * Save the answer, and optionally wait for the customer's reply.
		 *
		 * @param {boolean} wait Also set the status to awaiting_customer.
		 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-answers-from-the-ticket-req-qcd-007
		 */
		async save(wait) {
			if (this.busy || !this.canSave) {
				return
			}
			this.busy = true
			this.notice = ''
			try {
				const store = useObjectStore()
				const current = await store.fetchObject('ticket', this.objectId)
				if (!current) {
					this.fail()
					return
				}
				const next = {
					...current,
					id: this.objectId,
					customerMessage: this.message.trim(),
					portalAnswers: this.answersAfter(current, this.message.trim()),
				}
				if (wait) {
					next.status = 'awaiting_customer'
				}
				const saved = await store.saveObject('ticket', next)
				if (!saved) {
					this.fail()
					return
				}
				this.ticket = saved
				this.notice = t(
					'pipelinq',
					'The answer is saved. The customer can read it on the portal.',
				)
				this.noticeType = 'success'
			} finally {
				this.busy = false
			}
		},

		/**
		 * The ticket's answers with this one added, unless it is the answer
		 * the resident already has. Earlier answers keep their moment.
		 *
		 * @param {object} current The ticket as read just now.
		 * @param {string} answer The answer being saved.
		 * @return {Array<object>} `{message, createdAt}` per answer, oldest first.
		 * @spec openspec/changes/question-detail-on-the-portal/specs/dossier-questions/spec.md#requirement-a-resident-reads-their-question-the-dossier-it-was-about-and-the-answers-req-qdp-001
		 */
		answersAfter(current, answer) {
			const answers = Array.isArray(current.portalAnswers)
				? current.portalAnswers.filter((a) => a && typeof a === 'object')
				: []
			const last = answers.length ? answers[answers.length - 1].message : ''
			if (answer === last) {
				return answers
			}
			return [
				...answers,
				{ message: answer, createdAt: new Date().toISOString() },
			]
		},

		/**
		 * Tell the employee the answer did not save.
		 *
		 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-answers-from-the-ticket-req-qcd-007
		 */
		fail() {
			this.notice = t('pipelinq', 'Could not save the answer.')
			this.noticeType = 'error'
		},

		/**
		 * A reply's send time for display.
		 *
		 * @param {string} value An ISO date-time.
		 * @return {string} The localised date and time, or the raw value.
		 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-answers-from-the-ticket-req-qcd-007
		 */
		formatDate(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime())
				? String(value || '')
				: date.toLocaleString()
		},
	},
}
</script>

<style scoped>
.customer-reply-section {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.customer-reply-section__heading {
	margin: 8px 0 0;
	font-size: 1em;
	font-weight: bold;
}

.customer-reply-section__replies {
	margin: 0;
	padding-inline-start: 20px;
}

.customer-reply-section__replies li {
	display: flex;
	flex-direction: column;
	margin-bottom: 6px;
}

.customer-reply-section__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.customer-reply-section__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
