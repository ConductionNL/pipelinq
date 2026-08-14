<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<NcDialog
		:name="t('pipelinq', 'Justification required for large commitment')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="commit-justification">
			<p class="commit-justification__intro">
				{{
					t(
						'pipelinq',
						'Why are you confident in this deal? (e.g. decision-maker engaged, contract draft signed)',
					)
				}}
			</p>
			<NcTextArea
				v-model="reason"
				:label="t('pipelinq', 'Justification')"
				:placeholder="t('pipelinq', 'Why are you confident in this deal?')"
				:error="showError"
				:helper-text="
					showError
						? t('pipelinq', 'Please enter at least 10 characters.')
						: ''
				"
				rows="4" />
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!valid" @click="save">
				{{ t('pipelinq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcTextArea } from '@nextcloud/vue'

export default {
	name: 'CommitJustificationModal',
	components: { NcButton, NcDialog, NcTextArea },
	props: {
		initialReason: {
			type: String,
			default: '',
		},
	},
	emits: ['close', 'save'],
	data() {
		return {
			reason: this.initialReason,
			touched: false,
		}
	},
	computed: {
		valid() {
			return this.reason.trim().length >= 10
		},
		showError() {
			return this.touched && !this.valid
		},
	},
	methods: {
		save() {
			this.touched = true
			if (!this.valid) {
				return
			}
			this.$emit('save', this.reason.trim())
		},
	},
}
</script>

<style scoped>
.commit-justification__intro {
	margin-bottom: 12px;
	color: var(--color-text-maxcontrast);
}
</style>
