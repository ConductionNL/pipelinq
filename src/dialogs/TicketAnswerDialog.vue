<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

Answer the customer, from the ticket page's Answer button.

The body is CustomerReplySection, unchanged: the same text area, the same two
save buttons and the same save the full structure shows in the page. This
dialog adds no way to answer, it is a second door to the one that exists.

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
		<CustomerReplySection v-if="ticketId" :objectId="ticketId" />
		<template #actions>
			<NcButton data-testid="ticket-answer-close" @click="close">
				{{ t('pipelinq', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog } from '@nextcloud/vue'
import CustomerReplySection from '../components/CustomerReplySection.vue'

/**
 * The Answer dialog on a ticket.
 *
 * @spec openspec/changes/simple-ticket-page/specs/ticket-detail-page/spec.md#REQ-TDP-001
 */
export default {
	name: 'TicketAnswerDialog',
	components: { CustomerReplySection, NcButton, NcDialog },

	emits: ['close'],

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
