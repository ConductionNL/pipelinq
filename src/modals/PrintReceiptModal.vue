<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<CnDialog
		width="form"
		:name="t('pipelinq', 'Print Receipt')"
		size="normal"
		@closing="$emit('close')">
		<ReceiptModalBody v-bind="receiptBody" @update:selectedTemplate="selectTemplate">
			<p v-if="printerDevice" class="receipt-modal__device">
				{{ t('pipelinq', 'Configured printer:') }}
				<strong>{{ printerDevice }}</strong>
			</p>
		</ReceiptModalBody>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="printing" @click="print">
				{{ t('pipelinq', 'Print') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import ReceiptModalBody from '../components/pos/ReceiptModalBody.vue'
import receiptModalMixin from '../mixins/receiptModalMixin.js'

export default {
	name: 'PrintReceiptModal',
	components: {
		CnDialog,
		NcButton,
		ReceiptModalBody,
	},

	mixins: [receiptModalMixin],

	emits: ['close', 'printed'],
	data() {
		return {
			printerDevice: '',
			printing: false,
		}
	},

	methods: {
		/**
		 * The print modal reads nothing from the preview beyond its text.
		 *
		 * @return {void}
		 */
		showPreview() {},

		/**
		 * Request the ESC/POS byte stream for the transaction.
		 *
		 * The browser cannot open a raw socket to a thermal printer; the server
		 * returns the ESC/POS bytes (base64) and records the print in the audit
		 * log. Live spooling to a device is handled by the configured printer
		 * bridge in production.
		 */
		async print() {
			this.printing = true
			this.statusMessage = ''
			try {
				const { ok, data } = await this.postReceipt('print')
				if (!ok) {
					this.showReceiptError(data.error || t('pipelinq', 'Error printing receipt: {error}', { error: '' }))
					return
				}
				this.printerDevice = (data.receipt && data.receipt.printerDevice) || ''
				this.showReceiptSuccess(t('pipelinq', 'Receipt sent to printer'))
				this.$emit('printed', data.receipt)
				this.closeAfterReceipt()
			} catch (e) {
				this.showReceiptError(t(
					'pipelinq',
					'Error printing receipt: {error}',
					{ error: e.message },
				))
			} finally {
				this.printing = false
			}
		},
	},
}
</script>

<style scoped>
.receipt-modal__device {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
