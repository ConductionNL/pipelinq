<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<NcDialog
		:name="t('pipelinq', 'Email Receipt')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="receipt-modal">
			<NcSelect
				v-model="selectedTemplate"
				:inputLabel="t('pipelinq', 'Receipt Template')"
				:options="templateOptions"
				:placeholder="t('pipelinq', 'Select template')"
				label="label"
				:clearable="false"
				@update:modelValue="loadPreview" />

			<p class="receipt-modal__recipient">
				{{ t('pipelinq', 'The receipt is sent to the linked customer:') }}
				<strong>{{
					recipient || t('pipelinq', 'No customer email linked')
				}}</strong>
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
			<NcButton
				variant="primary"
				:disabled="sending || !recipient"
				@click="send">
				{{ t('pipelinq', 'Send') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'
import ReceiptPreviewPane from '../components/pos/ReceiptPreviewPane.vue'

export default {
	name: 'EmailReceiptModal',
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

	emits: ['close', 'sent'],
	data() {
		return {
			selectedTemplate: null,
			previewText: '',
			previewLoading: false,
			recipient: '',
			sending: false,
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
		 * Load the rendered receipt preview and the linked customer email.
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
					this.recipient = data.receipt.customerEmail || ''
				}
			} finally {
				this.previewLoading = false
			}
		},

		/**
		 * Submit the email-receipt request.
		 */
		async send() {
			this.sending = true
			this.statusMessage = ''
			try {
				const body = {}
				if (this.selectedTemplate) {
					body.template = this.selectedTemplate.id
				}
				const response = await fetch(
					generateUrl(
						`/apps/pipelinq/api/pos-transactions/${this.transactionId}/receipt/email`,
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
						|| t('pipelinq', 'Error sending receipt: {error}', {
							error: '',
						})
					return
				}
				if (data.receipt && data.receipt.status === 'failed') {
					this.statusType = 'error'
					this.statusMessage =
						data.receipt.error
						|| t(
							'pipelinq',
							'Mail delivery failed (no SMTP relay configured).',
						)
					return
				}
				this.statusType = 'success'
				this.statusMessage = t('pipelinq', 'Receipt sent successfully')
				this.$emit('sent', data.receipt)
			} catch (e) {
				this.statusType = 'error'
				this.statusMessage = t(
					'pipelinq',
					'Error sending receipt: {error}',
					{ error: e.message },
				)
			} finally {
				this.sending = false
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

.receipt-modal__recipient {
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
