<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  ConfirmDialog — generic confirmation prompt.

  Replaces window.confirm(), which hydra gate-34 reports because a native
  dialog is not themable, not translatable through the app's own l10n, cannot
  be styled for the Nextcloud shell, and on some platforms is suppressible by
  the browser — in which case it returns false and the guarded action silently
  never runs.

  Lives in its own file per ADR-004 (modal-isolation). One generic component
  rather than eight near-identical delete prompts; the sibling dialogs in this
  directory that carry entity-specific copy stay as they are.

  @spec openspec/specs/declarative-view-system/spec.md
-->
<template>
	<NcDialog :name="name" @closing="$emit('cancel')">
		<p class="confirm-dialog__message">
			{{ message }}
		</p>
		<template #actions>
			<NcButton @click="$emit('cancel')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton :variant="variant" @click="$emit('confirm')">
				{{ confirmLabel || t('pipelinq', 'Confirm') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'ConfirmDialog',
	components: { NcButton, NcDialog },
	props: {
		/** Dialog title. */
		name: { type: String, required: true },
		/** Body text explaining what is about to happen. */
		message: { type: String, required: true },
		/** Label for the confirming button; defaults to "Confirm". */
		confirmLabel: { type: String, default: '' },
		/** NcButton variant for the confirming button. */
		variant: { type: String, default: 'error' },
	},
	emits: ['confirm', 'cancel'],
}
</script>

<style scoped>
.confirm-dialog__message {
	margin: 0;
	white-space: pre-line;
}
</style>
