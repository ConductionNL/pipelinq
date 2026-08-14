<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - StagePickerDialog lists the pipeline stages a card can be moved to and
  - emits the picked stage; the parent owns the store write.
  -
  - It lives in its own file because a modal must never be written inline
  - inside its parent (ADR-004); it was extracted out of PipelineCard.vue.
  -->
<template>
	<NcDialog :name="t('pipelinq', 'Move to stage')" @closing="$emit('close')">
		<div class="dialog-list">
			<NcButton
				v-for="stage in stages"
				:key="stage.name"
				variant="secondary"
				class="dialog-list__item"
				@click="$emit('select', stage)">
				{{ stage.name }}
			</NcButton>
		</div>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'StagePickerDialog',
	components: {
		NcButton,
		NcDialog,
	},

	props: {
		/**
		 * The stages of the pipeline the card sits on.
		 */
		stages: {
			type: Array,
			default: () => [],
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
