<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - CtiDispositionModal collects the post-call disposition (subject + outcome
  - + notes) the agent must fill in before the contactmoment is closed.
  -
  - @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-5.4
  -->
<template>
	<NcDialog
		:name="t('pipelinq', 'Call disposition')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<form class="cti-disposition" @submit.prevent="submit">
			<NcTextField
				v-model="form.subject"
				:label="t('pipelinq', 'Subject')"
				:placeholder="t('pipelinq', 'What was the call about?')"
				required />
			<NcSelect
				v-model="form.outcome"
				:options="outcomeOptions"
				:input-label="t('pipelinq', 'Outcome')"
				:placeholder="t('pipelinq', 'Choose outcome')"
				:reduce="(o) => o.value"
				label="label"
				required />
			<NcTextArea
				v-model="form.notes"
				:label="t('pipelinq', 'Notes')"
				:placeholder="t('pipelinq', 'Optional follow-up notes')"
				rows="3" />
			<NcSelect
				v-if="form.outcome === 'escalated'"
				v-model="form.queue"
				:options="queueOptions"
				:input-label="t('pipelinq', 'Escalation queue')"
				:placeholder="t('pipelinq', 'Choose queue')"
				label="label" />
		</form>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!valid || saving" @click="submit">
				{{ t('pipelinq', 'Save & close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'

const OUTCOMES = [
	{ value: 'resolved', label: 'Resolved' },
	{ value: 'callback', label: 'Callback' },
	{ value: 'escalated', label: 'Escalated' },
	{ value: 'wrong-number', label: 'Wrong number' },
	{ value: 'no-answer', label: 'No answer' },
	{ value: 'abandoned', label: 'Abandoned' },
]

export default {
	name: 'CtiDispositionModal',
	components: { NcButton, NcDialog, NcSelect, NcTextArea, NcTextField },
	props: {
		queueOptions: { type: Array, default: () => [] },
		saving: { type: Boolean, default: false },
	},
	emits: ['close', 'submit'],
	data() {
		return {
			form: {
				subject: '',
				outcome: '',
				notes: '',
				queue: '',
			},
		}
	},
	computed: {
		outcomeOptions() {
			return OUTCOMES.map((o) => ({
				value: o.value,
				label: t('pipelinq', o.label),
			}))
		},
		valid() {
			return (
				this.form.subject.trim().length > 0 && this.form.outcome.length > 0
			)
		},
	},
	methods: {
		submit() {
			if (!this.valid) {
				return
			}
			this.$emit('submit', { ...this.form })
		},
	},
}
</script>

<style scoped>
.cti-disposition {
	display: flex;
	flex-direction: column;
	gap: 12px;
}
</style>
