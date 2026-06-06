<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<NcDialog :name="t('pipelinq', 'Delete webhook')"
		@closing="$emit('cancel')">
		<p>{{ t('pipelinq', 'Delete this webhook subscription?') }}</p>
		<template #actions>
			<NcButton type="tertiary" @click="$emit('cancel')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton type="error" @click="$emit('confirm')">
				{{ t('pipelinq', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'DeleteWebhookDialog',
	components: {
		NcButton,
		NcDialog,
	},
	emits: ['cancel', 'confirm'],
}
</script>
