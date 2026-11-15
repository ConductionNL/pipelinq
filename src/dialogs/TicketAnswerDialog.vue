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
	<CnDialog
		:name="t('pipelinq', 'Answer the customer')"
		:eyebrow="eyebrow"
		:subtitle="subtitle"
		width="form"
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
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import Send from 'vue-material-design-icons/Send.vue'
import CustomerReplySection from '../components/CustomerReplySection.vue'
import { useObjectStore } from '../store/modules/object.js'

/**
 * The Answer dialog on a ticket.
 *
 * @spec openspec/changes/simple-ticket-page/specs/ticket-detail-page/spec.md#REQ-TDP-001
 */
export default {
	name: 'TicketAnswerDialog',
	components: { CnDialog, CustomerReplySection, NcButton, Send },

	emits: ['close'],

	data() {
		return {
			replyState: { canSave: false, busy: false, waitForCustomer: true },
			ticket: null,
			clientName: '',
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

		/**
		 * The line above the title: "Answer · PQ-2026-0417".
		 *
		 * @return {string} The eyebrow, or "Answer" until the ticket is read.
		 * @spec openspec/changes/simple-ticket-page/specs/ticket-detail-page/spec.md#REQ-TDP-001
		 */
		eyebrow() {
			const reference = this.ticket?.reference || ''
			return reference
				? t('pipelinq', 'Answer · {reference}', { reference })
				: t('pipelinq', 'Answer')
		},

		/**
		 * The line under the title: the customer, the reference and the ticket's title.
		 *
		 * @return {string} The parts that are known, joined with a middle dot.
		 * @spec openspec/changes/simple-ticket-page/specs/ticket-detail-page/spec.md#REQ-TDP-001
		 */
		subtitle() {
			return [this.clientName, this.ticket?.reference, this.ticket?.title]
				.filter((part) => typeof part === 'string' && part !== '')
				.join(' · ')
		},
	},

	async created() {
		await this.loadTicket()
	},

	methods: {
		t,

		/**
		 * Read the ticket and its customer's name for the dialog's header lines.
		 *
		 * @spec openspec/changes/simple-ticket-page/specs/ticket-detail-page/spec.md#REQ-TDP-001
		 */
		async loadTicket() {
			if (!this.ticketId) {
				return
			}
			const store = useObjectStore()
			try {
				this.ticket = (await store.fetchObject('ticket', this.ticketId)) || null
				const clientId = this.ticket?.client
				if (typeof clientId === 'string' && clientId !== '') {
					const client = await store.fetchObject('client', clientId)
					this.clientName = client?.name || ''
				}
			} catch {
				this.ticket = null
			}
		},

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
