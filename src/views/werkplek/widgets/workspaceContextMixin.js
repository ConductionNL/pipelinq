// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The werkplek page's workspace bag, which its widgets read and write: the
 * injected `cnWorkspaceContext`, unwrapped whether the provided ref arrives
 * as `{ value }` or as the bag itself.
 *
 * @spec openspec/specs/kcc-werkplek/spec.md
 */
export default {
	inject: {
		cnWorkspaceContext: { default: null },
	},

	computed: {
		/**
		 * The page's workspace bag; the provided ref may arrive unwrapped or as `{ value }`.
		 *
		 * @return {object|null}
		 * @spec openspec/specs/kcc-werkplek/spec.md
		 */
		workspace() {
			const c = this.cnWorkspaceContext
			if (!c || typeof c !== 'object') {
				return null
			}
			return 'value' in c ? c.value : c
		},
	},
}
