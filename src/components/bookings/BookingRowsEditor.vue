<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Card-per-row editor for an array-of-objects field in the booking admin forms
  (a service's multiStep, a resource's workingHours and vacations), which the
  schema-driven CnFormDialog cannot edit.

  The host supplies the column labels, the grid tracks and one `cell-<key>`
  slot per column; this component owns the layout, add / remove / reorder and
  the empty state, so every such list looks and behaves the same.
-->
<template>
	<div class="booking-rows" :style="{ '--booking-rows-columns': gridColumns }">
		<div class="booking-rows__heading">
			<span :id="headingId" class="booking-rows__title">{{ title }}</span>
			<span v-if="$slots.summary && rows.length" class="booking-rows__summary">
				<slot name="summary" />
			</span>
		</div>

		<template v-if="rows.length">
			<div class="booking-rows__columns" aria-hidden="true">
				<span v-if="numbered" />
				<span v-for="column in columns" :key="column.key">{{ column.label }}</span>
				<span />
			</div>
			<ol class="booking-rows__list" :aria-labelledby="headingId">
				<li v-for="(row, idx) in rows" :key="idx" class="booking-rows__row">
					<span v-if="numbered" class="booking-rows__index" aria-hidden="true">{{ idx + 1 }}</span>
					<div
						v-for="column in columns"
						:key="column.key"
						class="booking-rows__cell">
						<slot
							:name="`cell-${column.key}`"
							:row="row"
							:index="idx"
							:update="(value) => updateRow(idx, column.key, value)" />
					</div>
					<div class="booking-rows__actions">
						<template v-if="reorderable">
							<NcButton
								variant="tertiary"
								:disabled="idx === 0"
								:aria-label="labels.moveUp(idx + 1)"
								@click="moveRow(idx, -1)">
								<template #icon>
									<ChevronUp :size="20" />
								</template>
							</NcButton>
							<NcButton
								variant="tertiary"
								:disabled="idx === rows.length - 1"
								:aria-label="labels.moveDown(idx + 1)"
								@click="moveRow(idx, 1)">
								<template #icon>
									<ChevronDown :size="20" />
								</template>
							</NcButton>
						</template>
						<NcButton
							variant="tertiary"
							:aria-label="labels.remove(idx + 1)"
							@click="removeRow(idx)">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
						</NcButton>
					</div>
				</li>
			</ol>
		</template>

		<p v-else class="booking-rows__empty">
			{{ emptyText }}
		</p>

		<div class="booking-rows__footer">
			<NcButton variant="secondary" @click="addRow">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ addLabel }}
			</NcButton>
			<p v-if="message" class="booking-rows__message" :class="`booking-rows__message--${messageType}`" role="status">
				<AlertOutline :size="16" />
				{{ message }}
			</p>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

let instanceCount = 0

export default {
	name: 'BookingRowsEditor',
	components: { AlertOutline, ChevronDown, ChevronUp, NcButton, Plus, TrashCanOutline },

	props: {
		/** The rows being edited. */
		modelValue: {
			type: Array,
			default: () => [],
		},

		/** Heading above the list. */
		title: {
			type: String,
			required: true,
		},

		/**
		 * The columns, in order: `{ key, label, width }`. `width` is the grid
		 * track (e.g. `minmax(8rem, 1fr)`); `key` names the `cell-<key>` slot.
		 */
		columns: {
			type: Array,
			required: true,
		},

		/** Factory for a new row. */
		newRow: {
			type: Function,
			required: true,
		},

		/** Show a row number in front of each row. */
		numbered: {
			type: Boolean,
			default: false,
		},

		/** Show move up / move down buttons. */
		reorderable: {
			type: Boolean,
			default: false,
		},

		/** Text of the empty state. */
		emptyText: {
			type: String,
			default: '',
		},

		/** Label of the add button. */
		addLabel: {
			type: String,
			required: true,
		},

		/** Aria-label builders for the row buttons, each given the 1-based row number. */
		labels: {
			type: Object,
			default: () => ({
				moveUp: (n) => t('pipelinq', 'Move row {n} up', { n }),
				moveDown: (n) => t('pipelinq', 'Move row {n} down', { n }),
				remove: (n) => t('pipelinq', 'Remove row {n}', { n }),
			}),
		},

		/** A warning or error shown beside the add button. */
		message: {
			type: String,
			default: '',
		},

		/** `warning` or `error`. */
		messageType: {
			type: String,
			default: 'warning',
		},
	},

	emits: ['update:modelValue'],

	data() {
		instanceCount += 1
		return { uid: instanceCount }
	},

	computed: {
		rows() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},

		headingId() {
			return `booking-rows-heading-${this.uid}`
		},

		/** Grid tracks: optional number, the host's columns, then a fixed actions track. */
		gridColumns() {
			const buttons = this.reorderable ? 3 : 1
			return [
				this.numbered ? '24px' : null,
				...this.columns.map((c) => c.width || 'minmax(0, 1fr)'),
				`calc(var(--default-clickable-area) * ${buttons})`,
			].filter(Boolean).join(' ')
		},
	},

	methods: {
		emitRows(rows) {
			this.$emit('update:modelValue', rows)
		},

		updateRow(idx, key, value) {
			this.emitRows(this.rows.map((r, i) => (i === idx ? { ...r, [key]: value } : r)))
		},

		addRow() {
			this.emitRows([...this.rows, this.newRow()])
		},

		removeRow(idx) {
			this.emitRows(this.rows.filter((_, i) => i !== idx))
		},

		/**
		 * Move a row by `delta` positions, clamped to the list bounds.
		 *
		 * @param {number} idx Source index.
		 * @param {number} delta Direction (+1 = down, -1 = up).
		 */
		moveRow(idx, delta) {
			const target = idx + delta
			if (target < 0 || target >= this.rows.length) {
				return
			}
			const rows = [...this.rows]
			const [row] = rows.splice(idx, 1)
			rows.splice(target, 0, row)
			this.emitRows(rows)
		},
	},
}
</script>

<style scoped>
.booking-rows {
	--booking-rows-gap: calc(var(--default-grid-baseline) * 2);
	container: booking-rows / inline-size;
	display: flex;
	flex-direction: column;
	gap: var(--booking-rows-gap);
}

.booking-rows__heading {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: var(--booking-rows-gap);
}

.booking-rows__title {
	font-weight: bold;
}

.booking-rows__summary {
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

/* The label row and the cards share one fixed set of tracks, so the labels
   stay above their fields. */
.booking-rows__columns,
.booking-rows__row {
	display: grid;
	grid-template-columns: var(--booking-rows-columns);
	align-items: center;
	column-gap: var(--booking-rows-gap);
}

.booking-rows__columns {
	padding-inline: var(--booking-rows-gap);
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.booking-rows__list {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	margin: 0;
	padding: 0;
	list-style: none;
}

.booking-rows__row {
	padding: var(--default-grid-baseline) var(--booking-rows-gap);
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-dark);
}

.booking-rows__index {
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

.booking-rows__cell {
	min-width: 0;
}

/* The controls sit in a grid track: drop NcSelect's 260px minimum and the
   bottom margins NcSelect and NcInputField reserve for standalone use. */
.booking-rows__row :deep(.v-select.select) {
	min-width: 0;
	margin: 0;
}

.booking-rows__row :deep(.input-field) {
	margin: 0;
}

.booking-rows__actions {
	display: flex;
	justify-content: flex-end;
}

.booking-rows__actions :deep(.button-vue) {
	color: var(--color-text-maxcontrast);
}

.booking-rows__empty {
	margin: 0;
	padding: var(--booking-rows-gap);
	border: 1px dashed var(--color-border-dark);
	border-radius: var(--border-radius-large);
	color: var(--color-text-maxcontrast);
}

.booking-rows__footer {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--booking-rows-gap);
}

.booking-rows__message {
	display: inline-flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	margin: 0;
}

.booking-rows__message--warning {
	color: var(--color-warning-text);
}

.booking-rows__message--error {
	color: var(--color-error-text);
}

/* Narrow dialog: drop the label row and let the cells wrap two per line, the
   actions on a line of their own. */
@container booking-rows (max-width: 560px) {
	.booking-rows__columns {
		display: none;
	}

	.booking-rows__row {
		grid-template-columns: repeat(2, minmax(0, 1fr));
		row-gap: var(--default-grid-baseline);
	}

	.booking-rows__row .booking-rows__index {
		grid-column: 1 / -1;
	}

	.booking-rows__actions {
		grid-column: 1 / -1;
	}
}
</style>
