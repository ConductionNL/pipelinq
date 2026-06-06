<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<NcDialog :name="t('pipelinq', 'Webhook test result')"
		@closing="$emit('closing')">
		<p>{{ message }}</p>
	</NcDialog>
</template>

<script>
import { NcDialog } from '@nextcloud/vue'

export default {
	name: 'WebhookTestResultDialog',
	components: {
		NcDialog,
	},
	props: {
		message: {
			type: String,
			required: true,
		},
	},
	emits: ['closing'],
}
</script>
