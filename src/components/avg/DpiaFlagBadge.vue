<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<span v-if="flagged" class="avg-dpia-badge" :title="t('pipelinq', 'This request is flagged for Data Protection Impact Assessment review')">
		<MagnifyScan :size="16" />
		{{ t('pipelinq', 'DPIA') }}
	</span>
</template>

<script>
import MagnifyScan from 'vue-material-design-icons/MagnifyScan.vue'

export default {
	name: 'DpiaFlagBadge',
	components: {
		MagnifyScan,
	},
	props: {
		/** Whether the request is DPIA-flagged. */
		flagged: {
			type: Boolean,
			default: false,
		},
	},
}
</script>

<style scoped>
.avg-dpia-badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 16px);
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element);
	font-size: 0.8em;
	font-weight: bold;
}
</style>
