/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * What the lead and request forms share for a host that drives them: the
 * `showActions` switch for the built-in buttons, the form's validity (no
 * errors and a title), and the `update:valid` event that surfaces it.
 *
 * The host has an `errors` computed and a `form` with a `title`.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-45
 */
export default {
	props: {
		/**
		 * Render the built-in Cancel / Save buttons. Set to `false` when the
		 * host supplies its own action buttons (e.g. a parent NcDialog driving
		 * the form via a ref + the `update:valid` event).
		 *
		 * Defaults ON deliberately: a host that supplies its own action bar
		 * opts OUT. Inverting the name would make every ordinary use pass a
		 * negative prop just to get the normal form.
		 */
		showActions: {
			type: Boolean,
			default: true,
		},
	},

	computed: {
		isValid() {
			return Object.keys(this.errors).length === 0 && this.form.title?.trim()
		},
	},

	watch: {
		// Surface validity so a host (e.g. a parent NcDialog) can enable or
		// disable its own submit button.
		isValid: {
			immediate: true,
			handler(val) {
				this.$emit('update:valid', !!val)
			},
		},
	},
}
