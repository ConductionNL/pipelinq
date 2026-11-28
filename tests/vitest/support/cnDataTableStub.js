/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A stand-in for nextcloud-vue's CnDataTable in specs that cannot load the
 * library: heads from `columns`, one row per entry of `rows` (class from
 * `rowClass`), each cell from its `#column-<key>` slot or the row value, and
 * the `#row-actions` slot last. The props it was given are on the root as
 * data attributes, so a spec can check `filterable` and `localHeaders`.
 */
import { h } from 'vue'

export const CnDataTableStub = {
	name: 'CnDataTable',
	props: { columns: Array, rows: Array, schema: Object, rowClass: Function, filterable: Boolean, localHeaders: Boolean },
	render() {
		const columns = this.columns || []
		const head = h('thead', [h('tr', [
			...columns.map((col) => h('th', col.label)),
			this.$slots['row-actions'] ? h('th', this.$slots['actions-header']?.()) : null,
		])])
		const body = h('tbody', (this.rows || []).map((row) => h('tr', { class: this.rowClass ? this.rowClass(row) : '' }, [
			...columns.map((col) => h('td', this.$slots['column-' + col.key]?.({ row, value: row[col.key] }) ?? String(row[col.key] ?? ''))),
			this.$slots['row-actions'] ? h('td', this.$slots['row-actions']({ row })) : null,
		])))
		return h('table', {
			'data-stub': 'CnDataTable',
			'data-filterable': String(!!this.filterable),
			'data-local-headers': String(!!this.localHeaders),
		}, [head, body])
	},
}
