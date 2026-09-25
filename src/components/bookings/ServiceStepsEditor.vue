<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Editor for a service's multiStep composition: one card per step with add,
  delete and reorder. Used by ServiceForm and by the service edit dialog's
  `#field-multiStep` slot, since the schema form cannot edit an array of
  objects.

  Warns when the step total disagrees with the service duration
  (REQ-APT-001 "duration sums to multi-step total").

  @spec openspec/specs/appointment-booking/spec.md
-->
<template>
	<div class="service-steps" data-testid="service-steps-editor">
		<div class="service-steps__heading">
			<span :id="headingId" class="service-steps__title">
				{{ t('pipelinq', 'Multi-step composition') }}
			</span>
			<span v-if="steps.length" class="service-steps__total">
				{{ t('pipelinq', '{sum} of {duration} min', { sum: stepTotal, duration: durationMinutes || 0 }) }}
			</span>
		</div>

		<template v-if="steps.length">
			<div class="service-steps__columns" aria-hidden="true">
				<span />
				<span>{{ t('pipelinq', 'Duration (min)') }}</span>
				<span>{{ t('pipelinq', 'Resource type') }}</span>
				<span>{{ t('pipelinq', 'Skill required') }}</span>
				<span>{{ t('pipelinq', 'Allow gap') }}</span>
				<span />
			</div>
			<ol class="service-steps__list" :aria-labelledby="headingId">
				<li v-for="(step, idx) in steps" :key="idx" class="service-steps__step">
					<span class="service-steps__index" aria-hidden="true">{{ idx + 1 }}</span>
					<div class="service-steps__cell service-steps__cell--duration">
						<NcTextField
							:modelValue="String(step.durationMinutes ?? 0)"
							type="number"
							min="0"
							labelOutside
							:aria-label="t('pipelinq', 'Step {n} duration in minutes', { n: idx + 1 })"
							@update:modelValue="(v) => updateStep(idx, 'durationMinutes', Number(v) || 0)" />
					</div>
					<div class="service-steps__cell service-steps__cell--type">
						<NcSelect
							:modelValue="step.resourceType"
							:inputId="`service-step-resource-${uid}-${idx}`"
							:aria-label-combobox="t('pipelinq', 'Step {n} resource type', { n: idx + 1 })"
							labelOutside
							:clearable="false"
							:options="resourceTypeOptions"
							:reduce="(o) => o.value"
							label="label"
							@update:modelValue="(v) => updateStep(idx, 'resourceType', v)" />
					</div>
					<div class="service-steps__cell service-steps__cell--skill">
						<NcTextField
							:modelValue="step.skillRequired || ''"
							labelOutside
							:placeholder="t('pipelinq', 'Any')"
							:aria-label="t('pipelinq', 'Step {n} required skill', { n: idx + 1 })"
							@update:modelValue="(v) => updateStep(idx, 'skillRequired', v)" />
					</div>
					<div class="service-steps__cell service-steps__cell--gap">
						<NcCheckboxRadioSwitch
							:modelValue="!!step.allowGap"
							type="switch"
							:aria-label="t('pipelinq', 'Step {n} allows a gap', { n: idx + 1 })"
							@update:modelValue="(v) => updateStep(idx, 'allowGap', v)" />
					</div>
					<div class="service-steps__actions">
						<NcButton
							variant="tertiary"
							:disabled="idx === 0"
							:aria-label="t('pipelinq', 'Move step {n} up', { n: idx + 1 })"
							@click="moveStep(idx, -1)">
							<template #icon>
								<ChevronUp :size="20" />
							</template>
						</NcButton>
						<NcButton
							variant="tertiary"
							:disabled="idx === steps.length - 1"
							:aria-label="t('pipelinq', 'Move step {n} down', { n: idx + 1 })"
							@click="moveStep(idx, 1)">
							<template #icon>
								<ChevronDown :size="20" />
							</template>
						</NcButton>
						<NcButton
							variant="tertiary"
							:aria-label="t('pipelinq', 'Remove step {n}', { n: idx + 1 })"
							@click="removeStep(idx)">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
						</NcButton>
					</div>
				</li>
			</ol>
		</template>

		<p v-else class="service-steps__empty">
			{{ t('pipelinq', 'Single-step service. Add steps to split it across resources.') }}
		</p>

		<div class="service-steps__footer">
			<NcButton variant="secondary" @click="addStep">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('pipelinq', 'Add step') }}
			</NcButton>
			<p v-if="totalWarning" class="service-steps__warning" role="status">
				<AlertOutline :size="16" />
				{{ totalWarning }}
			</p>
		</div>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'


let instanceCount = 0

export default {
	name: 'ServiceStepsEditor',
	components: {
		AlertOutline,
		ChevronDown,
		ChevronUp,
		NcButton,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextField,
		Plus,
		TrashCanOutline,
	},

	props: {
		/** The steps: `{ durationMinutes, resourceType, skillRequired, allowGap }[]`. */
		modelValue: {
			type: Array,
			default: () => [],
		},

		/** The service's total duration, checked against the step total. */
		durationMinutes: {
			type: Number,
			default: null,
		},
	},

	emits: ['update:modelValue'],

	data() {
		instanceCount += 1
		return { uid: instanceCount }
	},

	computed: {
		steps() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},

		headingId() {
			return `service-steps-heading-${this.uid}`
		},

		resourceTypeOptions() {
			return [
				{ value: 'staff', label: t('pipelinq', 'Staff') },
				{ value: 'room', label: t('pipelinq', 'Room') },
				{ value: 'equipment', label: t('pipelinq', 'Equipment') },
			]
		},

		stepTotal() {
			return this.steps.reduce((acc, s) => acc + (Number(s.durationMinutes) || 0), 0)
		},

		totalWarning() {
			if (this.steps.length === 0 || this.stepTotal === this.durationMinutes) {
				return ''
			}
			return t(
				'pipelinq',
				'Multi-step total ({sum} min) does not match Duration ({duration} min).',
				{ sum: this.stepTotal, duration: this.durationMinutes || 0 },
			)
		},
	},

	methods: {
		emitSteps(steps) {
			this.$emit('update:modelValue', steps)
		},

		updateStep(idx, key, value) {
			this.emitSteps(this.steps.map((s, i) => (i === idx ? { ...s, [key]: value } : s)))
		},

		addStep() {
			this.emitSteps([
				...this.steps,
				{ durationMinutes: 0, resourceType: 'staff', skillRequired: '', allowGap: false },
			])
		},

		removeStep(idx) {
			this.emitSteps(this.steps.filter((_, i) => i !== idx))
		},

		/**
		 * Move a step by `delta` positions, clamped to the list bounds.
		 *
		 * @param {number} idx Source index.
		 * @param {number} delta Direction (+1 = down, -1 = up).
		 */
		moveStep(idx, delta) {
			const target = idx + delta
			if (target < 0 || target >= this.steps.length) {
				return
			}
			const steps = [...this.steps]
			const [step] = steps.splice(idx, 1)
			steps.splice(target, 0, step)
			this.emitSteps(steps)
		},
	},
}
</script>

<style scoped>
.service-steps {
	--service-steps-gap: calc(var(--default-grid-baseline) * 2);
	/* Fixed-width actions track (three icon buttons), so the column labels
	   and the step cards resolve to the same widths. */
	--service-steps-columns: 24px minmax(5rem, 7rem) minmax(8rem, 1fr) minmax(8rem, 1fr) 4.5rem calc(var(--default-clickable-area) * 3);
	container: service-steps / inline-size;
	display: flex;
	flex-direction: column;
	gap: var(--service-steps-gap);
}

.service-steps__heading {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: var(--service-steps-gap);
}

.service-steps__title {
	font-weight: bold;
}

.service-steps__total {
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

.service-steps__columns,
.service-steps__step {
	display: grid;
	grid-template-columns: var(--service-steps-columns);
	align-items: center;
	column-gap: var(--service-steps-gap);
}

.service-steps__columns {
	padding-inline: var(--service-steps-gap);
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.service-steps__list {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	margin: 0;
	padding: 0;
	list-style: none;
}

.service-steps__step {
	padding: var(--default-grid-baseline) var(--service-steps-gap);
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-dark);
}

.service-steps__index {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	border-radius: 50%;
	background-color: var(--color-main-background);
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
	font-variant-numeric: tabular-nums;
}

/* The controls sit in a grid track: drop NcSelect's 260px minimum and the
   bottom margins NcSelect and NcInputField reserve for standalone use. */
.service-steps__step :deep(.v-select.select) {
	min-width: 0;
	margin: 0;
}

.service-steps__step :deep(.input-field) {
	margin: 0;
}

.service-steps__actions {
	display: flex;
	justify-content: flex-end;
}

.service-steps__actions :deep(.button-vue) {
	color: var(--color-text-maxcontrast);
}

.service-steps__empty {
	margin: 0;
	padding: var(--service-steps-gap);
	border: 1px dashed var(--color-border-dark);
	border-radius: var(--border-radius-large);
	color: var(--color-text-maxcontrast);
}

.service-steps__footer {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--service-steps-gap);
}

.service-steps__warning {
	display: inline-flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	margin: 0;
	color: var(--color-warning-text);
}

.service-steps__cell {
	min-width: 0;
}

/* Narrow dialog: drop the column header and wrap each step onto three lines. */
@container service-steps (max-width: 560px) {
	.service-steps__columns {
		display: none;
	}

	.service-steps__step {
		grid-template-columns: 24px 1fr 1fr;
		grid-template-areas:
			'index duration type'
			'. skill gap'
			'. actions actions';
		row-gap: var(--default-grid-baseline);
	}

	.service-steps__index {
		grid-area: index;
	}

	.service-steps__cell--duration {
		grid-area: duration;
	}

	.service-steps__cell--type {
		grid-area: type;
	}

	.service-steps__cell--skill {
		grid-area: skill;
	}

	.service-steps__cell--gap {
		grid-area: gap;
	}

	.service-steps__actions {
		grid-area: actions;
	}
}
</style>
