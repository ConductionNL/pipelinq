<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<div class="receipt-preview">
		<span class="receipt-preview__label">{{
			t('pipelinq', 'Receipt preview')
		}}</span>
		<div v-if="loading" class="receipt-preview__loading">
			<NcLoadingIcon :size="20" />
		</div>
		<pre v-else class="receipt-preview__body">{{
			content || t('pipelinq', 'No preview available')
		}}</pre>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'ReceiptPreviewPane',
	components: {
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
	font-family: monospace;
	font-size: 12px;
	line-height: 1.35;
	white-space: pre-wrap;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	margin: 0;
	max-height: 320px;
	overflow-y: auto;
}
</style>
