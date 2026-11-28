<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  The footer of a bulk dialog that previews before it commits: Cancel, then
  Preview until a preview stands (or was refused), then Confirm.

  @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.2
-->
<template>
	<NcButton :disabled="committing" @click="$emit('close')">
		{{ t('pipelinq', 'Cancel') }}
	</NcButton>
	<NcButton
		v-if="!previewed"
		variant="primary"
		:disabled="!canPreview || previewing"
		:data-testid="`${testidPrefix}-preview`"
		@click="$emit('preview')">
		<template #icon>
			<NcLoadingIcon v-if="previewing" :size="18" />
		</template>
		{{ t('pipelinq', 'Preview') }}
	</NcButton>
	<NcButton
		v-else
		variant="primary"
		:disabled="committing || changed === 0"
		:data-testid="`${testidPrefix}-confirm`"
		@click="$emit('confirm')">
		<template #icon>
			<Check :size="18" />
		</template>
		{{ t('pipelinq', 'Confirm') }}
	</NcButton>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'

export default {
	name: 'BulkPreviewActions',
	components: { Check, NcButton, NcLoadingIcon },

	props: {
		/** Whether a preview stands that can be confirmed. */
		previewed: { type: Boolean, default: false },
		/** Whether the dialog has what a preview needs. */
		canPreview: { type: Boolean, default: false },
		/** Whether the preview is running. */
		previewing: { type: Boolean, default: false },
		/** Whether the commit is running. */
		committing: { type: Boolean, default: false },
		/** How many records the preview changes. */
		changed: { type: Number, default: 0 },
		/** The data-testid prefix of the Preview and Confirm buttons. */
		testidPrefix: { type: String, required: true },
	},

	emits: ['close', 'preview', 'confirm'],
}
</script>
