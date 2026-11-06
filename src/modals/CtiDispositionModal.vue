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
			<div class="cti-disposition__field">
				<label for="cti-disposition-subject" class="cti-disposition__label">{{ t('pipelinq', 'Subject') }}</label>
				<NcTextField
					id="cti-disposition-subject"
					v-model="form.subject"
					:label="t('pipelinq', 'Subject')"
					labelOutside
					aria-required="true"
					:error="subjectTouched && !form.subject.trim()"
					data-testid="cti-disposition-subject"
					@blur="subjectTouched = true" />
			</div>
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
				<label for="cti-disposition-queue" class="cti-disposition__label">{{ t('pipelinq', 'Escalation queue') }}<span class="cti-disposition__optional"> {{ t('pipelinq', '(optional)') }}</span></label>
				<NcSelect
					v-model="form.queue"
					inputId="cti-disposition-queue"
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
			<div class="cti-disposition__field">
				<label for="cti-disposition-notes" class="cti-disposition__label">{{ t('pipelinq', 'Notes') }}<span class="cti-disposition__optional"> {{ t('pipelinq', '(optional)') }}</span></label>
				<NcTextArea
					id="cti-disposition-notes"
					v-model="form.notes"
					:label="t('pipelinq', 'Notes (optional)')"
					labelOutside
					rows="3"
					data-testid="cti-disposition-notes" />
			</div>
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

export const OUTCOMES = [
	{ value: 'resolved', label: 'Resolved' },
	{ value: 'callback', label: 'Callback' },
	{ value: 'escalated', label: 'Escalated' },
	{ value: 'wrong-number', label: 'Wrong number' },
	{ value: 'no-answer', label: 'No answer' },
	{ value: 'abandoned', label: 'Hung up' },
]

/**
 * The outcome labels, each through a literal t() call so the catalogue finds them.
 *
 * @return {{[key: string]: string}} Translated label per outcome label.
 * @spec openspec/specs/cti-screenpop-adapter/spec.md
 */
function outcomeLabels() {
	return {
		Resolved: t('pipelinq', 'Resolved'),
		Callback: t('pipelinq', 'Callback'),
		Escalated: t('pipelinq', 'Escalated'),
		'Wrong number': t('pipelinq', 'Wrong number'),
		'No answer': t('pipelinq', 'No answer'),
		'Hung up': t('pipelinq', 'Hung up'),
	}
}

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

			subjectTouched: false,
		}
	},

	computed: {
		/** @return {string} "Call · 06 41 22 87 90". */
		eyebrow() {
			return [t('pipelinq', 'Conversation'), readablePhone(this.number)].filter(Boolean).join(' · ')
		},

		/**
		 * The outcomes the agent picks from, with their board words.
		 *
		 * @return {Array<object>} One option per outcome.
		 *
		 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-disposition-outcomes-drive-workflow-req-cti-009
		 */
		outcomeOptions() {
			const labels = outcomeLabels()
			return OUTCOMES.map((o) => ({
				value: o.value,
				label: labels[o.label],
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
		/**
		 * Sends the wrap-up when the subject and the outcome are filled in.
		 *
		 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-disposition-outcomes-drive-workflow-req-cti-009
		 */
		submit() {
			this.subjectTouched = true
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

.cti-disposition__field,
.cti-disposition__queue {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.cti-disposition__label {
	font-size: 14px;
	font-weight: 600;
}

.cti-disposition__optional {
	font-weight: 400;
	color: var(--color-text-maxcontrast);
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

/* Nextcloud's core dt/dd box (130px, end-aligned, padded) would push each label away from its value. */
.cti-disposition__facts dt {
	width: auto;
	padding: 0;
	text-align: start;
	white-space: normal;
	color: var(--color-text-maxcontrast);
}

.cti-disposition__facts dd {
	margin: 0;
	padding: 0;
}
</style>
