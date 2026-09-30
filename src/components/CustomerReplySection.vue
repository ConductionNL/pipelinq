<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Ticket-detail in-body section (kind:'section'): answer the customer or
  - resident on a request or complaint. The minimal answer control of
  - messaging-saved-replies-and-resend D5, built for the Woo citizen journey
  - (questions-about-a-citizen-dossier, REQ-QCD-007) without the saved-reply
  - picker, which that change adds here later.
  -
  - Shows the replies sent from the portal (`portalReplies`), oldest first,
  - and a text area bound to `customerMessage`. "Save answer" writes the
  - message; "Save and wait for a reply" also sets `awaiting_customer`, the
  - status a portal reply resumes from. The store's saveObject() is a PUT that
  - replaces the whole object, so the section re-reads the ticket and sends it
  - back with only these fields changed. Saving changes `customerMessage`,
  - which is what portaliq's change rule `pipelinq.question.answered` hears.
  -->
<template>
	<section
		v-if="applies"
		class="customer-reply-section"
		:aria-label="t('pipelinq', 'Answer to the customer')">
		<h3 class="customer-reply-section__title">
			{{ t('pipelinq', 'Answer to the customer') }}
		</h3>
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
		<div class="customer-reply-section__actions">
			<NcButton :disabled="busy || !canSave" @click="save(false)">
				{{ t('pipelinq', 'Save answer') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || !canSave"
				@click="save(true)">
				{{ t('pipelinq', 'Save and wait for a reply') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="notice" :type="noticeType">
			{{ notice }}
		</NcNoteCard>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcNoteCard, NcTextArea } from '@nextcloud/vue'
import { useObjectStore } from '../store/modules/object.js'

/** The ticket kinds a customer can be answered on. */
const ANSWERABLE = ['request', 'complaint']

export default {
	name: 'CustomerReplySection',
	components: {
		NcButton,
		NcNoteCard,
		NcTextArea,
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

.customer-reply-section__title {
	margin: 0;
	font-size: 1.1em;
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
