<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  BulkUndoDialog: the preview of undoing a bulk change, before anything is
  written. OpenRegister made the reversal job; this shows how many records get
  their old value back and how many are skipped, and commits on Undo.

  Lives in its own file per ADR-004 (modal-isolation).

  @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md
-->
<template>
	<CnDialog width="form" :name="t('pipelinq', 'Undo bulk change')" @closing="$emit('cancel')">
		<p>
			{{ n('pipelinq', '%n record gets its old value back.', '%n records get their old value back.', writes) }}
		</p>
		<p v-if="skips > 0">
			{{ n('pipelinq', '%n record is skipped.', '%n records are skipped.', skips) }}
		</p>
		<p v-if="error" class="bulk-undo__error" role="alert">
			{{ error }}
		</p>
		<template #actions>
			<NcButton :disabled="busy" @click="$emit('cancel')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="busy" @click="$emit('confirm')">
				{{ t('pipelinq', 'Undo') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'BulkUndoDialog',
	components: { CnDialog, NcButton },
	props: {
		/** The reversal job OpenRegister previewed. */
		reversal: { type: Object, required: true },
		/** Whether the commit is running. */
		busy: { type: Boolean, default: false },
		/** A refusal to show. */
		error: { type: String, default: '' },
	},

	emits: ['cancel', 'confirm'],
	computed: {
		skips() {
			return Number(this.reversal?.counts?.skipped ?? this.reversal?.skipped ?? 0) || 0
		},

		writes() {
			const total = Number(this.reversal?.total ?? 0) || 0
			return Math.max(0, total - this.skips)
		},
	},
}
</script>

<style scoped>
.bulk-undo__error {
	color: var(--color-error-text);
}
</style>
