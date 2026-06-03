<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<div class="avg-deadline" :class="`avg-deadline--${color}`">
		<span v-if="breached" class="avg-deadline__breached">
			{{ t('pipelinq', 'OVERSCHREDEN') }}
		</span>
		<span v-else class="avg-deadline__remaining">
			{{ n('pipelinq', '%n day remaining', '%n days remaining', days) }}
		</span>
		<span class="avg-deadline__due">{{ t('pipelinq', 'Due') }}: {{ dueString }}</span>
		<span v-if="extended" class="avg-deadline__extended">
			{{ t('pipelinq', 'Extended +60 days') }}
		</span>
	</div>
</template>

<script>
import { daysRemaining, getUrgencyColor, deadlineString } from '../../utils/avg/deadlineUtils.js'

export default {
	name: 'DeadlineCounter',
	props: {
		/** The ISO 8601 legal deadline. */
		deadline: {
			type: String,
			default: '',
		},
		/** The extension in days (60 when extended). */
		extendedDays: {
			type: Number,
			default: 0,
		},
	},
	computed: {
		/**
		 * Whole days remaining (may be negative).
		 *
		 * @return {number} The days remaining.
		 */
		days() {
			return daysRemaining(this.deadline) ?? 0
		},
		/**
		 * Whether the deadline has been breached.
		 *
		 * @return {boolean} True when breached.
		 */
		breached() {
			return this.days < 0
		},
		/**
		 * Whether the deadline was extended.
		 *
		 * @return {boolean} True when extended.
		 */
		extended() {
			return this.extendedDays > 0
		},
		/**
		 * The urgency colour class suffix.
		 *
		 * @return {string} The colour.
		 */
		color() {
			return getUrgencyColor(this.days)
		},
		/**
		 * The human-readable due date string.
		 *
		 * @return {string} The formatted deadline.
		 */
		dueString() {
			return deadlineString(this.deadline)
		},
	},
}
</script>

<style scoped>
.avg-deadline {
	display: inline-flex;
	flex-direction: column;
	gap: 2px;
	padding: 4px 8px;
	border-radius: var(--border-radius);
	font-size: 0.9em;
}
.avg-deadline--green { color: var(--color-success); }
.avg-deadline--yellow { color: var(--color-warning); }
.avg-deadline--red { color: var(--color-error); font-weight: bold; }
.avg-deadline--grey { color: var(--color-text-maxcontrast); }
.avg-deadline__breached { font-weight: bold; text-transform: uppercase; }
.avg-deadline__due { color: var(--color-text-maxcontrast); font-size: 0.85em; }
.avg-deadline__extended { font-size: 0.8em; font-style: italic; }
</style>
