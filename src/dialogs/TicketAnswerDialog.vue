<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

Answer the customer, from the ticket page's Answer button.

It saves through POST /api/tickets/{id}/answer, the same endpoint the page's
conversation answers through, so there is one save.

Laid out as board PqTicketAntwoord: the customer's question first, then a
saved reply and the message, and Cancel and Send answer in the footer; a sent
answer closes the dialog.

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
		<div class="ticket-answer">
			<template v-if="question">
				<span class="ticket-answer__meta">{{ t('pipelinq', 'The question') }}</span>
				<blockquote class="ticket-answer__question" data-testid="customer-reply-question">
					{{ question }}
				</blockquote>
			</template>
			<CnTemplateSelect
				v-if="ticketId"
				url="/apps/pipelinq/api/saved-replies"
				:params="{ channel: 'portal', ticket: ticketId }"
				:inputLabel="t('pipelinq', 'Saved reply')"
				data-testid="saved-reply-picker"
				@apply="applyReply" />
			<NcTextArea
				v-model="message"
				:label="t('pipelinq', 'Your message')"
				:helperText="t('pipelinq', 'The customer reads this on the portal. Everything else on the ticket stays internal.')"
				:rows="10"
				resize="vertical" />
			<NcCheckboxRadioSwitch v-model="waitForCustomer" data-testid="customer-reply-wait">
				{{ t('pipelinq', 'Also set the ticket to waiting for the customer') }}
			</NcCheckboxRadioSwitch>
			<NcNoteCard v-if="failed" type="error">
				{{ t('pipelinq', 'Could not save the answer.') }}
			</NcNoteCard>
		</div>
		<template #actions>
			<NcButton data-testid="ticket-answer-close" @click="close">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || message.trim() === ''"
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
import { CnTemplateSelect } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcNoteCard,
	NcTextArea,
} from '@nextcloud/vue'
import Send from 'vue-material-design-icons/Send.vue'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'TicketAnswerDialog',
	components: {
		CnTemplateSelect,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcNoteCard,
		NcTextArea,
		Send,
	},

	emits: ['close'],

	data() {
		return {
			question: '',
			message: '',
			waitForCustomer: true,
			busy: false,
			failed: false,
		}
	},

	computed: {
		/**
		 * @return {string} The ticket the route names.
		 * @spec openspec/changes/simple-ticket-page/specs/ticket-detail-page/spec.md#REQ-TDP-001
		 */
		ticketId() {
			const id = this.$route?.params?.id
			return typeof id === 'string' ? id : ''
		},
	},

	async mounted() {
		if (!this.ticketId) {
			return
		}
		const ticket = await useObjectStore().fetchObject('ticket', this.ticketId)
		this.question = (ticket && ticket.description) || ''
		this.message = (ticket && ticket.customerMessage) || ''
	},

	methods: {
		t,

		/**
		 * Put a picked saved reply in the message.
		 *
		 * @param {object|null} option The template option.
		 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/messaging-saved-replies/spec.md#requirement-the-server-offers-saved-replies-filled-for-the-record
		 */
		applyReply(option) {
			if (option && option.values && typeof option.values.body === 'string') {
				this.message = option.values.body
			}
		},

		/**
		 * Save the answer, then close.
		 *
		 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/dossier-questions/spec.md#requirement-the-server-saves-the-answer-to-the-customer
		 */
		async send() {
			if (this.busy || this.message.trim() === '') {
				return
			}
			this.busy = true
			this.failed = false
			try {
				await axios.post(
					generateUrl('/apps/pipelinq/api/tickets/{id}/answer', { id: this.ticketId }),
					{ message: this.message.trim(), waitForCustomer: this.waitForCustomer },
				)
				showSuccess(t('pipelinq', 'The answer is saved. The customer can read it on the portal.'))
				this.close()
			} catch {
				this.failed = true
			} finally {
				this.busy = false
			}
		},

		/**
		 * @spec openspec/changes/simple-ticket-page/specs/ticket-detail-page/spec.md#REQ-TDP-001
		 */
		close() {
			emit('cn:page:refresh', {})
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.ticket-answer {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.ticket-answer__meta {
	color: var(--color-text-maxcontrast);
}

.ticket-answer__question {
	margin: 0;
	padding: 8px 12px;
	border-inline-start: 3px solid var(--color-border-dark);
	white-space: pre-wrap;
}
</style>
