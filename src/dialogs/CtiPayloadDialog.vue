<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - CtiPayloadDialog renders the raw JSON payload of one CTI webhook receipt.
  -
  - It lives in its own file because a modal must never be written inline
  - inside its parent (ADR-004); it was extracted out of CtiEventLog.vue.
  -
  - @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-5.6
  -->
<template>
	<NcDialog
		:name="t('pipelinq', 'Webhook payload')"
		:open="true"
		size="large"
		@closing="$emit('close')">
		<pre class="cti-payload-dialog__pre">{{ pretty }}</pre>
	</NcDialog>
</template>

<script>
import { NcDialog } from '@nextcloud/vue'

export default {
	name: 'CtiPayloadDialog',
	components: { NcDialog },
	props: {
		/**
		 * The `payload_json` of the event-log row being inspected.
		 */
		payload: {
			type: [Object, Array, String, Number, Boolean],
			default: null,
		},
	},
	emits: ['close'],
	computed: {
		/**
		 * Pretty-print the payload, falling back to its string form when it
		 * cannot be serialised (e.g. a cyclic structure).
		 *
		 * @return {string} The formatted payload.
		 * @spec exclude presentational formatter — no business logic
		 */
		pretty() {
			try {
				return JSON.stringify(this.payload, null, 2)
			} catch (e) {
				return String(this.payload)
			}
		},
	},
}
</script>

<style scoped>
.cti-payload-dialog__pre {
	max-height: 480px;
	overflow: auto;
	background: var(--color-background-hover);
	padding: 12px;
	border-radius: 4px;
}
</style>
