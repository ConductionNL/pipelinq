<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

Answer the customer, from the ticket page's Answer button.

The body is CustomerReplySection, unchanged: the same text area, the same send
button and the same save the full structure shows in the page. This
dialog adds no way to answer, it is a second door to the one that exists.

Laid out as board PqTicketAntwoord: the customer's question first, then the
message, and Cancel and Send answer in the footer; a sent answer closes the
dialog.

An `open-modal` action carries no object context, so the route names the
ticket. When the dialog closes the page is asked to reload, so its status
pill, its primary button and its conversation show what was just saved.

@spec openspec/changes/simple-ticket-page/specs/ticket-detail-page/spec.md#REQ-TDP-001
-->
<template>
	<NcDialog
		:name="t('pipelinq', 'Answer the customer')"
		:open="true"
		size="normal"
		data-testid="ticket-answer-dialog"
		@closing="close">
		<CustomerReplySection
			v-if="ticketId"
			ref="reply"
			:objectId="ticketId"
			inDialog
			@state="replyState = $event"
			@saved="onSaved" />
		<template #actions>
			<NcButton data-testid="ticket-answer-close" @click="close">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="replyState.busy || !replyState.canSave"
				data-testid="ticket-answer-send"
				@click="send">
				<template #icon>
					<Send :size="20" />
				</template>
				{{ t('pipelinq', 'Send answer') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog } from '@nextcloud/vue'
import Send from 'vue-material-design-icons/Send.vue'
import CustomerReplySection from '../components/CustomerReplySection.vue'

/**
 * The Answer dialog on a ticket.
 *
 * @spec openspec/changes/simple-ticket-page/specs/ticket-detail-page/spec.md#REQ-TDP-001
 */
export default {
	name: 'TicketAnswerDialog',
	components: { CustomerReplySection, NcButton, NcDialog, Send },

	emits: ['close'],

	data() {
		return {
			replyState: { canSave: false, busy: false, waitForCustomer: true },
		}
	},

	computed: {
		/**
		 * The ticket the page is on.
		 *
		 * @return {string} The id from the route, or an empty string.
		 * @spec openspec/changes/simple-ticket-page/specs/ticket-detail-page/spec.md#REQ-TDP-001
		 */
		ticketId() {
			const id = this.$route?.params?.id
			return typeof id === 'string' ? id : ''
		},
	},

	methods: {
		t,

		/**
		 * Send the answer the section holds, and set the status when the box is ticked.
		 *
		 * @spec openspec/changes/simple-ticket-page/specs/ticket-detail-page/spec.md#REQ-TDP-001
		 */
		send() {
			this.$refs.reply?.save(this.replyState.waitForCustomer)
		},

		/**
		 * A sent answer: say so and close.
		 *
		 * @spec openspec/changes/simple-ticket-page/specs/ticket-detail-page/spec.md#REQ-TDP-001
		 */
		onSaved() {
			showSuccess(
				t(
					'pipelinq',
					'The answer is saved. The customer can read it on the portal.',
				),
			)
			this.close()
		},

		/**
		 * Close, and have the page read the ticket again.
		 *
		 * @spec openspec/changes/simple-ticket-page/specs/ticket-detail-page/spec.md#REQ-TDP-001
		 */
		close() {
			emit('cn:page:refresh', {})
			this.$emit('close')
		},
	},
}
</script>
