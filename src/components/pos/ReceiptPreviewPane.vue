<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<!--
The receipt preview in the print and email dialogs (PqKassaAfrekenen): a label,
a spinner while the preview loads, then the receipt text as typed in a
fixed-width font through nextcloud-vue CnTextWidget `monospace`.
-->
<template>
	<div class="receipt-preview">
		<span class="receipt-preview__label">{{
			t('pipelinq', 'Receipt preview')
		}}</span>
		<div v-if="loading" class="receipt-preview__loading">
			<NcLoadingIcon :size="20" />
		</div>
		<CnTextWidget
			v-else
			class="receipt-preview__body"
			:content="receiptContent"
			:objectData="receiptData" />
	</div>
</template>

<script>
import { CnTextWidget } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon } from '@nextcloud/vue'

const RECEIPT_CONTENT = Object.freeze({ monospace: true, textField: 'receipt' })

export default {
	name: 'ReceiptPreviewPane',
	components: {
		CnTextWidget,
		NcLoadingIcon,
	},

	props: {
		content: {
			type: String,
			default: '',
		},

		loading: {
			type: Boolean,
			default: false,
		},
	},

	computed: {
		/**
		 * The text widget's settings: the receipt as typed, read from the object below.
		 *
		 * @return {object} The widget content.
		 * @spec openspec/specs/pos-receipt-engine/spec.md#requirement-the-receipt-preview-shows-the-text-as-typed
		 */
		receiptContent() {
			return RECEIPT_CONTENT
		},

		/**
		 * The receipt text, or a line that says there is none.
		 *
		 * @return {{receipt: string}} The object the widget reads.
		 * @spec openspec/specs/pos-receipt-engine/spec.md#requirement-the-receipt-preview-shows-the-text-as-typed
		 */
		receiptData() {
			return { receipt: this.content || t('pipelinq', 'No preview available') }
		},
	},
}
</script>

<style scoped>
.receipt-preview {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.receipt-preview__label {
	font-weight: bold;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.receipt-preview__loading {
	display: flex;
	justify-content: center;
	padding: 24px 0;
}

.receipt-preview__body {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	max-height: 320px;
	overflow-y: auto;
}
</style>
