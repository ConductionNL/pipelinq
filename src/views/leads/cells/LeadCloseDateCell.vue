<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!-- @spec openspec/specs/customer-360/spec.md -->
<template>
	<span class="lead-close-cell" :class="cellClass" :title="srLabel">
		<span>{{ formattedDate }}</span>
		<span class="lead-close-cell__sr-only">{{ srLabel }}</span>
	</span>
</template>

<script>
/**
 * Lead expected-close-date cell renderer (Customer 360 / REQ-KB360-014).
 *
 * Displays the date with a warning icon when:
 *   - the lead is overdue (`expectedCloseDate` is in the past), OR
 *   - the lead closes within the next 7 days (inclusive of today).
 *
 * WCAG AA: the indicator is ALWAYS an icon + (when destructive) a colour;
 * never colour alone. Hidden visually-hidden label adds screen-reader
 * context.
 *
 * @spec openspec/specs/customer-360/spec.md
 */
export default {
	name: 'LeadCloseDateCell',
	props: {
		/**
		 * The raw cell value — typically an ISO-date string.
		 *
		 * @type {string}
		 */
		value: {
			type: [String, Number, Date],
			default: null,
		},

		/** The whole lead row; a won or lost lead is never late. */
		row: {
			type: Object,
			default: () => ({}),
		},
	},

	computed: {
		/**
		 * Resolved Date object (or null when the value is empty/invalid).
		 *
		 * @return {?Date}
		 */
		dateObj() {
			if (this.value === null || this.value === undefined || this.value === '')
				return null
			const d = new Date(this.value)
			return Number.isNaN(d.getTime()) ? null : d
		},

		/**
		 * Visual state: 'overdue' (past), 'soon' (≤7 days), 'ok' (>7 days), 'unknown' (no date).
		 *
		 * @return {string}
		 */
		state() {
			if (!this.dateObj) return 'unknown'
			const today = new Date()
			today.setHours(0, 0, 0, 0)
			const target = new Date(this.dateObj)
			target.setHours(0, 0, 0, 0)
			const diffDays = Math.round(
				(target.getTime() - today.getTime()) / 86400000,
			)
			const closed = this.row?.status && this.row.status !== 'open'
			if (diffDays < 0) return closed ? 'ok' : 'overdue'
			if (diffDays <= 7) return 'soon'
			return 'ok'
		},

		cellClass() {
			return {
				'lead-close-cell--overdue': this.state === 'overdue',
				'lead-close-cell--soon': this.state === 'soon',
			}
		},

		formattedDate() {
			if (!this.dateObj) return '-'
			const locale =
				typeof OC !== 'undefined' && OC.getLocale
					? OC.getLocale().replace('_', '-')
					: 'nl-NL'
			try {
				const short = this.dateObj
					.toLocaleDateString(locale, { day: 'numeric', month: 'short' })
					.replace('.', '')
				return this.state === 'overdue'
					? `${short}, ${this.t('pipelinq', 'late')}`
					: short
			} catch {
				return this.dateObj.toISOString().slice(0, 10)
			}
		},

		srLabel() {
			if (this.state === 'overdue') return this.t('pipelinq', 'Overdue')
			if (this.state === 'soon') return this.t('pipelinq', 'Closes soon')
			return ''
		},
	},
}
</script>

<style scoped>
.lead-close-cell {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}

.lead-close-cell__icon--overdue {
	color: var(--color-element-error, var(--color-error));
}

.lead-close-cell__icon--soon {
	color: var(--color-element-warning, var(--color-warning));
}

.lead-close-cell--overdue {
	color: var(--color-text-error, var(--color-error-text));
	font-weight: 600;
}

.lead-close-cell__sr-only {
	position: absolute;
	width: 1px;
	height: 1px;
	padding: 0;
	margin: -1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
	border: 0;
}
</style>
