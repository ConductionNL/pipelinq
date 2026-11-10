<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<CnDialog
		width="form"
		:name="t('pipelinq', 'Email Receipt')"
		size="normal"
		@closing="$emit('close')">
		<ReceiptModalBody v-bind="receiptBody" @update:selectedTemplate="selectTemplate">
			<p class="receipt-modal__recipient">
				{{ t('pipelinq', 'The receipt is sent to the linked customer:') }}
				<strong>{{
					recipient || t('pipelinq', 'No customer email linked')
				}}</strong>
			</p>
		</ReceiptModalBody>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="sending || !recipient"
				@click="send">
				{{ t('pipelinq', 'Send') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import ReceiptModalBody from '../components/pos/ReceiptModalBody.vue'
import receiptModalMixin from '../mixins/receiptModalMixin.js'

/**
 * Why the server did not send the receipt: its refusal, or a failed delivery.
 *
 * @param {boolean} ok Whether the server took the request.
 * @param {object} data Its answer.
 * @return {(string|null)} The message, or null when the receipt was sent.
 */
function emailRefusal(ok, data) {
	if (!ok) {
		return data.error
			|| t('pipelinq', 'Error sending receipt: {error}', {
				error: '',
			})
	}
	if (data.receipt && data.receipt.status === 'failed') {
		return data.receipt.error
			|| t(
				'pipelinq',
				'Mail delivery failed (no SMTP relay configured).',
			)
	}
	return null
}

export default {
	name: 'EmailReceiptModal',
	components: {
		CnDialog,
		NcButton,
		ReceiptModalBody,
	},

	mixins: [receiptModalMixin],

	emits: ['close', 'sent'],
	data() {
		return {
			recipient: '',
			sending: false,
		}
	},

	methods: {
		/**
		 * Read the linked customer email from the preview.
		 *
		 * @param {object} receipt The previewed receipt.
		 * @return {void}
		 */
		showPreview(receipt) {
			this.recipient = receipt.customerEmail || ''
		},

		/**
		 * Submit the email-receipt request.
		 */
		async send() {
			this.sending = true
			this.statusMessage = ''
			try {
				const { ok, data } = await this.postReceipt('email')
				const refusal = emailRefusal(ok, data)
				if (refusal !== null) {
					this.showReceiptError(refusal)
					return
				}
				this.showReceiptSuccess(t('pipelinq', 'Receipt sent successfully'))
				this.$emit('sent', data.receipt)
				this.closeAfterReceipt()
			} catch (e) {
				this.showReceiptError(t(
					'pipelinq',
					'Error sending receipt: {error}',
					{ error: e.message },
				))
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped>
.receipt-modal__recipient {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
