<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - PriorityPickerDialog picks the priority of a pipeline card and emits the
  - choice; the parent owns the store write.
  -
  - It lives in its own file because a modal must never be written inline
  - inside its parent (ADR-004); it was extracted out of PipelineCard.vue.
  -->
<template>
	<NcDialog :name="t('pipelinq', 'Set priority')" @closing="$emit('close')">
		<div class="dialog-list">
			<NcButton
				v-for="p in options"
				:key="p.value"
				:variant="current === p.value ? 'primary' : 'secondary'"
				class="dialog-list__item"
				@click="$emit('select', p.value)">
				{{ p.label }}
			</NcButton>
		</div>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'PriorityPickerDialog',
	components: {
		NcButton,
		NcDialog,
	},

	props: {
		/**
		 * Selectable priorities as `{ value, label }` pairs.
		 */
		options: {
			type: Array,
			default: () => [],
		},

		/**
		 * The card's current priority; its button is highlighted.
		 */
		current: {
			type: String,
			default: null,
		},
	},

	emits: ['close', 'select'],
}
</script>

<style scoped>
.dialog-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
}

.dialog-list__item {
	width: 100%;
	justify-content: flex-start;
}
</style>
