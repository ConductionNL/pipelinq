<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<NcDialog
		:name="t('pipelinq', 'Print Receipt')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="receipt-modal">
			<NcSelect
				v-model="selectedTemplate"
				:input-label="t('pipelinq', 'Receipt Template')"
				:options="templateOptions"
				:placeholder="t('pipelinq', 'Select template')"
				label="label"
				:clearable="false"
				@update:model-value="loadPreview" />

			<p v-if="printerDevice" class="receipt-modal__device">
				{{ t('pipelinq', 'Configured printer:') }}
				<strong>{{ printerDevice }}</strong>
			</p>

			<ReceiptPreviewPane :content="previewText" :loading="previewLoading" />

			<p
				v-if="statusMessage"
				class="receipt-modal__status"
				:class="statusClass">
				{{ statusMessage }}
			</p>
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="printing" @click="print">
				{{ t('pipelinq', 'Print') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcSelect } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import ReceiptPreviewPane from '../components/pos/ReceiptPreviewPane.vue'

export default {
	name: 'PrintReceiptModal',
	components: {
		NcDialog,
		NcButton,
		NcSelect,
		ReceiptPreviewPane,
	},
	props: {
		transactionId: {
			type: String,
			required: true,
		},
		templates: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['close', 'printed'],
	data() {
		return {
			selectedTemplate: null,
			previewText: '',
			previewLoading: false,
			printerDevice: '',
			printing: false,
			statusMessage: '',
			statusType: '',
		}
	},
	computed: {
		/**
		 * Template options for the NcSelect dropdown.
		 *
		 * @return {Array} The options.
		 */
		templateOptions() {
			return this.templates.map((tpl) => ({
				id: tpl.id,
				label: tpl.name || tpl.id,
			}))
		},
		/**
		 * CSS class for the status message.
		 *
		 * @return {object} The class binding.
		 */
		statusClass() {
			return {
				'receipt-modal__status--error': this.statusType === 'error',
				'receipt-modal__status--success': this.statusType === 'success',
			}
		},
	},
	async mounted() {
		await this.loadPreview()
	},
	methods: {
		/**
		 * Load the rendered receipt preview.
		 */
		async loadPreview() {
			this.previewLoading = true
			this.statusMessage = ''
			try {
				const params = this.selectedTemplate
					? `?template=${encodeURIComponent(this.selectedTemplate.id)}`
					: ''
				const response = await fetch(
					generateUrl(
						`/apps/pipelinq/api/pos-transactions/${this.transactionId}/receipt/preview${params}`,
					),
					{
						headers: {
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
					},
				)
				const data = await response.json().catch(() => ({}))
				if (response.ok && data.receipt) {
					this.previewText = data.receipt.text || ''
				}
			} finally {
				this.previewLoading = false
			}
		},
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
				const body = {}
				if (this.selectedTemplate) {
					body.template = this.selectedTemplate.id
				}
				const response = await fetch(
					generateUrl(
						`/apps/pipelinq/api/pos-transactions/${this.transactionId}/receipt/print`,
					),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
						body: JSON.stringify(body),
					},
				)
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					this.statusType = 'error'
					this.statusMessage =
						data.error
						|| t('pipelinq', 'Error printing receipt: {error}', {
							error: '',
						})
					return
				}
				this.printerDevice =
					(data.receipt && data.receipt.printerDevice) || ''
				this.statusType = 'success'
				this.statusMessage = t('pipelinq', 'Receipt sent to printer')
				this.$emit('printed', data.receipt)
			} catch (e) {
				this.statusType = 'error'
				this.statusMessage = t(
					'pipelinq',
					'Error printing receipt: {error}',
					{ error: e.message },
				)
			} finally {
				this.printing = false
			}
		},
	},
}
</script>

<style scoped>
.receipt-modal {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.receipt-modal__device {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.receipt-modal__status {
	font-size: 13px;
	margin: 0;
}

.receipt-modal__status--error {
	color: var(--color-error);
}

.receipt-modal__status--success {
	color: var(--color-success);
}
</style>
