<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  DeleteResourceDialog — confirmation prompt for deleting a Resource.
  Extracted to its own file per ADR-004 (modal-isolation).
  @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
-->
<template>
	<NcDialog
		:name="t('pipelinq', 'Delete resource')"
		@closing="$emit('cancel')">
		<p>
			{{ t('pipelinq', 'Are you sure you want to delete "{name}"?', { name }) }}
		</p>
		<template #actions>
			<NcButton @click="$emit('cancel')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="error" @click="$emit('confirm')">
				{{ t('pipelinq', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'DeleteResourceDialog',
	components: { NcButton, NcDialog },
	props: {
		name: { type: String, default: '' },
	},
	emits: ['confirm', 'cancel'],
}
</script>
