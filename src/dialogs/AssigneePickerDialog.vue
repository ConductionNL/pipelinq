<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - AssigneePickerDialog picks the user a pipeline card is assigned to and
  - emits the choice; the parent owns the store write.
  -
  - It lives in its own file because a modal must never be written inline
  - inside its parent (ADR-004); it was extracted out of PipelineCard.vue.
  -->
<template>
	<NcDialog :name="t('pipelinq', 'Assign user')" @closing="$emit('close')">
		<NcSelect
			v-model="picked"
			:options="options"
			:clearable="true"
			:input-label="t('pipelinq', 'Assignee')"
			@update:model-value="$emit('select', $event)" />
	</NcDialog>
</template>

<script>
import { NcDialog, NcSelect } from '@nextcloud/vue'

export default {
	name: 'AssigneePickerDialog',
	components: {
		NcDialog,
		NcSelect,
	},
	props: {
		/**
		 * The user ids that can be assigned.
		 */
		options: {
			type: Array,
			default: () => [],
		},
		/**
		 * The currently assigned user id, pre-selected when the dialog opens.
		 */
		assignee: {
			type: String,
			default: null,
		},
	},
	emits: ['close', 'select'],
	data() {
		return {
			picked: this.assignee,
		}
	},
}
</script>
