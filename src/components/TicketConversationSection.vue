<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

Ticket-detail in-body section (kind:'section'): what the customer wrote and
what was answered, as one thread, to read.

The thread is the library's CnConversationThread. It has no reply box here:
the answer is written in the Answer dialog (TicketAnswerDialog), which is the
same CustomerReplySection the full structure shows in the page. So there is one
place that saves an answer, and this section cannot drift from it.

The ticket comes from the page (`cnSectionContext`), so the thread follows the
page when it reloads after an answer.

Hidden for a contact moment: that is a logged interaction, with nobody to
answer.

@spec openspec/changes/simple-ticket-page/specs/ticket-detail-page/spec.md#REQ-TDP-003
-->
<template>
	<CnConversationThread
		v-if="applies"
		:messages="messages"
		:allowReply="false"
		:usLabel="t('pipelinq', 'Employee')"
		:emptyText="t('pipelinq', 'No messages with the customer yet.')"
		data-testid="ticket-conversation" />
</template>

<script>
import { CnConversationThread } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { unref } from 'vue'
import { ticketConversation } from '../utils/ticketConversation.js'

/**
 * The conversation on a ticket.
 *
 * @spec openspec/changes/simple-ticket-page/specs/ticket-detail-page/spec.md#REQ-TDP-003
 */
export default {
	name: 'TicketConversationSection',
	components: { CnConversationThread },

	inject: {
		sectionContext: { from: 'cnSectionContext', default: null },
	},

	computed: {
		/**
		 * The ticket the page holds.
		 *
		 * @return {object|null} The ticket, or null while the page loads.
		 * @spec openspec/changes/simple-ticket-page/specs/ticket-detail-page/spec.md#REQ-TDP-003
		 */
		ticket() {
			// Vue unwraps an injected ref on `this`; unref covers both shapes.
			const object = unref(this.sectionContext)?.object
			return object && typeof object === 'object' ? object : null
		},

		/**
		 * Whether this ticket has a customer to talk to.
		 *
		 * @return {boolean} False for a contact moment and while loading.
		 * @spec openspec/changes/simple-ticket-page/specs/ticket-detail-page/spec.md#REQ-TDP-003
		 */
		applies() {
			return !!this.ticket && this.ticket.ticketType !== 'interaction'
		},

		/**
		 * The thread, oldest first.
		 *
		 * @return {Array<object>} The messages.
		 * @spec openspec/changes/simple-ticket-page/specs/ticket-detail-page/spec.md#REQ-TDP-003
		 */
		messages() {
			return ticketConversation(this.ticket)
		},
	},

	methods: { t },
}
</script>
