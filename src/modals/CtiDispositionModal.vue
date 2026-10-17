<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - CtiDispositionModal collects the post-call disposition (subject + outcome
  - + notes) the agent fills in when a call ends, laid out as board
  - PqGesprekAfronden: the number above the title, the outcome as a grid of
  - choices, the escalation queue only for an escalated call, and what the
  - call is logged as under the form. The incoming-call card mounts it when
  - the agent ends the call or the line reports it ended.
  -
  - @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-5.4
  -->
<template>
	<CnDialog
		:name="t('pipelinq', 'Call outcome')"
		:eyebrow="eyebrow"
		:subtitle="
			t(
				'pipelinq',
				'The call has ended. Note what came of it for the next conversation.',
			)
		"
		:open="true"
		size="normal"
		data-testid="cti-disposition"
		@closing="$emit('close')">
		<form class="cti-disposition" @submit.prevent="submit">
			<NcTextField
				v-model="form.subject"
				:label="t('pipelinq', 'Subject')"
				:placeholder="t('pipelinq', 'What was the call about?')"
				required />
			<fieldset class="cti-disposition__outcomes">
				<legend>{{ t('pipelinq', 'Outcome') }}</legend>
				<NcCheckboxRadioSwitch
					v-for="option in outcomeOptions"
					:key="option.value"
					v-model="form.outcome"
					:value="option.value"
					name="cti-disposition-outcome"
					type="radio">
					{{ option.label }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<div v-if="form.outcome === 'escalated'">
				<NcSelect
					v-model="form.queue"
					:options="queueOptions"
					:inputLabel="t('pipelinq', 'Escalation queue (optional)')"
					:placeholder="t('pipelinq', 'Choose queue')"
					label="label" />
				<p class="cti-disposition__hint">
					{{
						t(
							'pipelinq',
							'Only for an escalated call. The ticket goes to this queue.',
						)
					}}
				</p>
			</div>
			<NcTextArea
				v-model="form.notes"
				:label="t('pipelinq', 'Notes (optional)')"
				:placeholder="t('pipelinq', 'Optional follow-up notes')"
				rows="3" />
			<dl
				v-if="facts.length"
				class="cti-disposition__facts"
				data-testid="cti-disposition-facts">
				<template v-for="fact in facts" :key="fact.label">
					<dt>{{ fact.label }}</dt>
					<dd>{{ fact.value }}</dd>
				</template>
			</dl>
		</form>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!valid || saving"
				data-testid="cti-disposition-save"
				@click="submit">
				<template #icon>
					<Check :size="20" />
				</template>
				{{ t('pipelinq', 'Save and close') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'

const OUTCOMES = [
	{ value: 'resolved', label: 'Resolved' },
	{ value: 'callback', label: 'Callback' },
	{ value: 'escalated', label: 'Escalated' },
	{ value: 'wrong-number', label: 'Wrong number' },
	{ value: 'no-answer', label: 'No answer' },
	{ value: 'abandoned', label: 'Hung up' },
]

export default {
	name: 'CtiDispositionModal',
	components: {
		Check,
		CnDialog,
		NcButton,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	props: {
		queueOptions: { type: Array, default: () => [] },
		saving: { type: Boolean, default: false },
		/** The caller's number, shown above the title. */
		number: { type: String, default: '' },
		/** Who was on the line, when known. */
		callerName: { type: String, default: '' },
		/** How long the call took, in seconds; negative when unknown. */
		durationSeconds: { type: Number, default: -1 },
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

		/**
		 * @return {string} "Call · <number>", or "Call" without a number.
		 */
		eyebrow() {
			return this.number
				? t('pipelinq', 'Call · {number}', { number: this.number })
				: t('pipelinq', 'Call')
		},

		/**
		 * The facts under the form: who called, how long, and what the call
		 * is logged as.
		 *
		 * @return {Array<{label: string, value: string}>}
		 */
		facts() {
			const facts = []
			if (this.callerName) {
				facts.push({
					label: t('pipelinq', 'Client'),
					value: this.callerName,
				})
			}
			if (this.durationSeconds >= 0) {
				const minutes = Math.floor(this.durationSeconds / 60)
				const seconds = this.durationSeconds % 60
				facts.push({
					label: t('pipelinq', 'Duration'),
					value: t('pipelinq', '{minutes} minutes {seconds} seconds', {
						minutes,
						seconds,
					}),
				})
			}
			facts.push({
				label: t('pipelinq', 'Logged as'),
				value: t('pipelinq', 'Contact moment'),
			})
			return facts
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

.cti-disposition__outcomes {
	display: grid;
	grid-template-columns: repeat(3, minmax(0, 1fr));
	gap: 4px 12px;
	border: 0;
	margin: 0;
	padding: 0;
}

.cti-disposition__outcomes legend {
	font-weight: bold;
	margin-bottom: 4px;
}

.cti-disposition__hint {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.cti-disposition__facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 6px 16px;
	margin: 0;
}

.cti-disposition__facts dt {
	color: var(--color-text-maxcontrast);
}

.cti-disposition__facts dd {
	margin: 0;
}
</style>
