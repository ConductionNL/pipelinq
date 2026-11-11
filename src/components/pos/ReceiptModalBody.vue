<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<!--
The body the print and email receipt dialogs share: the template choice,
the dialog's own line (the slot), the receipt preview and the status line.
-->
<template>
	<div class="receipt-modal">
		<NcSelect
			:modelValue="selectedTemplate"
			:inputLabel="t('pipelinq', 'Receipt Template')"
			:options="templateOptions"
			:placeholder="t('pipelinq', 'Select template')"
			label="label"
			:clearable="false"
			@update:modelValue="$emit('update:selectedTemplate', $event)" />

		<slot />

		<ReceiptPreviewPane :content="previewText" :loading="previewLoading" />

		<p
			v-if="statusMessage"
			class="receipt-modal__status"
			:class="statusClass">
			{{ statusMessage }}
		</p>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import ReceiptPreviewPane from './ReceiptPreviewPane.vue'

export default {
	name: 'ReceiptModalBody',
	components: {
		NcSelect,
		ReceiptPreviewPane,
	},

	props: {
		selectedTemplate: {
			type: Object,
			default: null,
		},

		templateOptions: {
			type: Array,
			default: () => [],
		},

		previewText: {
			type: String,
			default: '',
		},

		previewLoading: {
			type: Boolean,
			default: false,
		},

		statusMessage: {
			type: String,
			default: '',
		},

		statusType: {
			type: String,
			default: '',
		},
	},

	emits: ['update:selectedTemplate'],

	computed: {
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
}
</script>

<style scoped>
.receipt-modal {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
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
