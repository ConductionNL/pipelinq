/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The v-model a resource's row editors (working hours, vacations) share: the
 * rows the host form binds, its error, and the rows read safely.
 *
 * The editor declares the `update:modelValue` it emits, and supplies its
 * own columns, rule check and new row.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
export default {
	props: {
		/** The rows the host form binds. */
		modelValue: {
			type: Array,
			default: () => [],
		},

		/** An error from the host form, shown instead of the live rule check. */
		error: {
			type: String,
			default: '',
		},
	},

	computed: {
		/**
		 * The rows, or none when the value is not an array.
		 *
		 * @return {Array<object>}
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		rows() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},
	},
}
