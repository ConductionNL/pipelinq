// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Show a form's validation errors only once they mean something
 * (pipelinq-audit-admin-forms-pos).
 *
 * The host component has a `form` object and an `errors` computed that
 * validates it (and drives `isValid`). A freshly opened New client or New
 * lead form used to greet the user with "Name is required" in red before
 * anything was typed. With this mixin a field's error shows once the field
 * has changed, or for every field once a save was attempted
 * (`markSaveAttempted()`). Validity itself is unchanged.
 *
 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/client-forms/spec.md#requirement-errors-wait-for-the-user
 */
export default {
	data() {
		return {
			touchedFields: {},
			saveAttempted: false,
			lastFormSnapshot: null,
		}
	},

	computed: {
		/**
		 * The errors to show: touched fields only, or all after a save attempt.
		 *
		 * @return {Record<string, string>} The visible errors.
		 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/client-forms/spec.md#requirement-errors-wait-for-the-user
		 */
		shownErrors() {
			if (this.saveAttempted) {
				return this.errors
			}
			return Object.fromEntries(
				Object.entries(this.errors).filter(
					([key]) => this.touchedFields[key],
				),
			)
		},
	},

	watch: {
		form: {
			deep: true,
			immediate: true,
			/**
			 * Mark every field whose value changed as touched.
			 *
			 * @param {object} value The form.
			 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/client-forms/spec.md#requirement-errors-wait-for-the-user
			 */
			handler(value) {
				const snapshot = JSON.parse(JSON.stringify(value || {}))
				const previous = this.lastFormSnapshot
				this.lastFormSnapshot = snapshot
				if (previous === null) {
					return
				}
				const touched = { ...this.touchedFields }
				for (const key of Object.keys(snapshot)) {
					if (
						JSON.stringify(snapshot[key])
						!== JSON.stringify(previous[key])
					) {
						touched[key] = true
					}
				}
				this.touchedFields = touched
			},
		},
	},

	methods: {
		/**
		 * From now on, show every error.
		 *
		 * @return {void}
		 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/client-forms/spec.md#requirement-errors-wait-for-the-user
		 */
		markSaveAttempted() {
			this.saveAttempted = true
		},
	},
}
