<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<CnDialog
		width="form"
		:name="decision === 'approve' ? t('pipelinq', 'Approve destruction list') : t('pipelinq', 'Reject destruction list')"
		size="normal"
		@closing="$emit('close')">
		<div class="destruction-decision">
			<p v-if="decision === 'approve'">
				{{ n('pipelinq', 'Approving lets OpenRegister destroy %n record on this list. Records under a legal hold are kept.', 'Approving lets OpenRegister destroy %n records on this list. Records under a legal hold are kept.', entryCount) }}
			</p>
			<template v-else>
				<p>{{ t('pipelinq', 'The records on this list are kept. OpenRegister needs a reason for the rejection.') }}</p>
				<NcTextArea
					v-model="reason"
					:label="t('pipelinq', 'Reason')"
					rows="3" />
			</template>
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				:variant="decision === 'approve' ? 'error' : 'primary'"
				:disabled="!valid || busy"
				@click="confirm">
				{{ decision === 'approve' ? t('pipelinq', 'Approve') : t('pipelinq', 'Reject') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { NcButton, NcTextArea } from '@nextcloud/vue'

/**
 * Confirms approving or rejecting a destruction list; a rejection asks for the
 * reason OpenRegister requires.
 *
 * @spec openspec/changes/platform-client-retention/tasks.md#3-review-and-back-fill
 */
export default {
	name: 'DestructionListDecisionModal',
	components: { CnDialog, NcButton, NcTextArea },
	props: {
		decision: {
			type: String,
			required: true,
			validator: (value) => ['approve', 'reject'].includes(value),
		},

		entryCount: {
			type: Number,
			default: 0,
		},

		busy: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'confirm'],

	data() {
		return { reason: '' }
	},

	computed: {
		/**
		 * Whether the decision can be sent: a rejection needs a reason.
		 *
		 * @return {boolean}
		 */
		valid() {
			return this.decision === 'approve' || this.reason.trim() !== ''
		},
	},

	methods: {
		/**
		 * Emit the confirmed decision.
		 *
		 * @return {void}
		 */
		confirm() {
			if (!this.valid) {
				return
			}
			this.$emit('confirm', this.decision === 'reject' ? { reason: this.reason.trim() } : {})
		},
	},
}
</script>

<style scoped>
.destruction-decision {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
</style>
