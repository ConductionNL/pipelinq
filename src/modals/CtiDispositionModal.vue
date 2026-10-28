<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - CtiDispositionModal collects the post-call disposition (subject, outcome
  - and notes) when a call has ended, laid out as board PqGesprekAfronden:
  - the number over the title, the subject, the outcome as six choices, the
  - escalation queue when the call was escalated, notes, and what the call
  - was: the client, how long it took and the contact moment it is on.
  -
  - @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-5.4
  -->
<template>
	<CnDialog
		:name="t('pipelinq', 'Call outcome')"
		:eyebrow="eyebrow"
		:subtitle="t('pipelinq', 'The call has ended. Record what came of it for the next conversation.')"
		width="form"
		data-testid="cti-disposition-dialog"
		@closing="$emit('close')">
		<form class="cti-disposition" @submit.prevent="submit">
			<NcTextField
				v-model="form.subject"
				:label="t('pipelinq', 'Subject')"
				labelOutside
				data-testid="cti-disposition-subject"
				required />
			<fieldset class="cti-disposition__outcomes" data-testid="cti-disposition-outcome">
				<legend>{{ t('pipelinq', 'Outcome') }}</legend>
				<NcCheckboxRadioSwitch
					v-for="option in outcomeOptions"
					:key="option.value"
					v-model="form.outcome"
					type="radio"
					:value="option.value"
					name="cti-disposition-outcome">
					{{ option.label }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<div v-if="form.outcome === 'escalated' && queueOptions.length > 0" class="cti-disposition__queue">
				<NcSelect
					v-model="form.queue"
					:options="queueOptions"
					:inputLabel="t('pipelinq', 'Escalation queue (optional)')"
					labelOutside
					label="label"
					:reduce="(o) => o.value"
					data-testid="cti-disposition-queue" />
				<p class="cti-disposition__hint">
					{{ t('pipelinq', 'Only shown when the outcome is Escalated. The ticket goes to this queue.') }}
				</p>
			</div>
			<NcTextArea
				v-model="form.notes"
				:label="t('pipelinq', 'Notes (optional)')"
				labelOutside
				rows="3"
				data-testid="cti-disposition-notes" />
			<dl v-if="facts.length > 0" class="cti-disposition__facts" data-testid="cti-disposition-facts">
				<template v-for="fact in facts" :key="fact.label">
					<dt>{{ fact.label }}</dt>
					<dd>
						<a v-if="fact.href" :href="fact.href">{{ fact.value }}</a>
						<template v-else>
							{{ fact.value }}
						</template>
					</dd>
				</template>
			</dl>
		</form>
		<template #actions>
			<NcButton data-testid="cti-disposition-cancel" @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!valid || saving"
				data-testid="cti-disposition-submit"
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
import { n } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import { readablePhone } from '../utils/readablePhone.js'

const OUTCOMES = [
	{ value: 'resolved', label: 'Resolved' },
	{ value: 'callback', label: 'Callback' },
	{ value: 'escalated', label: 'Escalated' },
	{ value: 'wrong-number', label: 'Wrong number' },
	{ value: 'no-answer', label: 'No answer' },
	{ value: 'abandoned', label: 'Abandoned' },
]

/**
 * How long a call took, in words ("4 minutes 12 seconds").
 *
 * @param {number} seconds The length in seconds.
 * @return {string} The length, or '' when unknown.
 */
export function callLength(seconds) {
	if (!Number.isFinite(seconds) || seconds < 0) {
		return ''
	}
	const minutes = Math.floor(seconds / 60)
	const rest = Math.round(seconds % 60)
	const parts = []
	if (minutes > 0) {
		parts.push(n('pipelinq', '%n minute', '%n minutes', minutes))
	}
	parts.push(n('pipelinq', '%n second', '%n seconds', rest))
	return parts.join(' ')
}

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
		/** The number that called. */
		number: { type: String, default: '' },
		/** Who called, when the screen pop found them. */
		clientName: { type: String, default: '' },
		/** How long the call took, in seconds; -1 when unknown. */
		durationSeconds: { type: Number, default: -1 },
		/** What the call is recorded as ("Contact moment at PQ-2026-0417"). */
		recordedAs: { type: String, default: '' },
		/** Where that record opens. */
		recordedHref: { type: String, default: '' },
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
		/** @return {string} "Call · 06 41 22 87 90". */
		eyebrow() {
			return [t('pipelinq', 'Conversation'), readablePhone(this.number)].filter(Boolean).join(' · ')
		},

		outcomeOptions() {
			return OUTCOMES.map((o) => ({
				value: o.value,
				label: t('pipelinq', o.label),
			}))
		},

		/** @return {Array<{label: string, value: string, href?: string}>} What the call was. */
		facts() {
			const facts = []
			if (this.clientName) {
				facts.push({ label: t('pipelinq', 'Client'), value: this.clientName })
			}
			const length = callLength(this.durationSeconds)
			if (length) {
				facts.push({ label: t('pipelinq', 'Duration'), value: length })
			}
			if (this.recordedAs) {
				facts.push({ label: t('pipelinq', 'Recorded as'), value: this.recordedAs, href: this.recordedHref || undefined })
			}
			return facts
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
	gap: 16px;
}

.cti-disposition__outcomes {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 4px 16px;
	margin: 0;
	padding: 0;
	border: 0;
}

.cti-disposition__outcomes legend {
	grid-column: 1 / -1;
	margin-bottom: 6px;
	padding: 0;
	font-size: 14px;
	font-weight: 600;
}

.cti-disposition__queue {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.cti-disposition__hint {
	margin: 0;
	font-size: 13px;
	line-height: normal;
	color: var(--color-text-maxcontrast);
}

.cti-disposition__facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 24px;
	margin: 0;
	font-size: 13px;
}

.cti-disposition__facts dt {
	color: var(--color-text-maxcontrast);
}

.cti-disposition__facts dd {
	margin: 0;
}
</style>
