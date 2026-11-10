/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The export jobs list's table: its row action, every column with its
 * widget, labels and the functions a cell calls, and the quick filters,
 * pinned before the table was split into named column builders.
 */
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnIndexPage: {}, openRowTarget: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../src/modals/ExportTestRunModal.vue', () => ({ default: {} }))
vi.mock('../../src/services/exportApi.js', () => ({ exportApi: {} }))

let jobs

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => `T:${text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match))}`
	jobs = (await import('../../src/views/export/ExportJobs.vue')).default
}, 60000)

/**
 * @param {unknown} value Part of the table.
 * @return {unknown} It with every function replaced by a marker.
 */
function shape(value) {
	if (typeof value === 'function') {
		return 'fn'
	}
	if (Array.isArray(value)) {
		return value.map(shape)
	}
	if (value && typeof value === 'object') {
		return Object.fromEntries(Object.entries(value).map(([key, inner]) => [key, shape(inner)]))
	}
	return value
}

describe('ExportJobs.data', () => {
	it('declares the table the list shows', () => {
		const vm = { testRun: vi.fn(), setEnabled: vi.fn() }
		const state = jobs.data.call(vm)
		expect(shape(state)).toEqual({
			register: 'pipelinq',
			schema: 'exportJob',
			testRunJobId: null,
			rowActions: [{ id: 'test-run', label: 'T:Test run', icon: 'PlayOutline', handler: 'fn' }],
			columns: [
				{ key: 'name', label: 'T:Job', secondary: '{format} · {mode}', secondaryLabels: { format: { csv: 'CSV', parquet: 'Parquet', jsonl: 'JSONL' }, mode: { full: 'T:full', incremental: 'T:incremental' } }, cellClass: 'cn-cell--strong' },
				{ key: 'sourceSchemas', label: 'T:Dataset', widget: 'badge' },
				{ key: 'destinationId', label: 'T:Destination', widget: 'fkResolve', widgetProps: { register: 'pipelinq', schema: 'exportDestination', labelField: 'name', secondaryTemplate: '{type}', secondaryLabels: { type: { s3: 'Amazon S3', azure_data_lake: 'Azure Data Lake', gcs: 'Google Cloud Storage', bigquery: 'BigQuery', snowflake: 'Snowflake', sftp: 'SFTP', postgres: 'PostgreSQL' } } } },
				{ key: 'scheduleCron', label: 'T:Schedule', formatter: 'exportSchedule', secondary: 'fn' },
				{ key: 'lastRunStatus', label: 'T:Last run', widget: 'badge', widgetProps: { colorMap: { succeeded: 'success', partial: 'warning', failed: 'error' } }, secondary: 'fn' },
				{ key: 'nextRunAt', hidden: true, label: 'T:Next run', formatter: 'exportMoment', emptyValue: 'T:Not scheduled' },
				{ key: 'enabled', label: 'T:Enabled', widget: 'switch', widgetProps: { checked: 'fn', onToggle: 'fn', ariaLabel: 'fn' } },
			],
			quickFilters: [
				{ label: 'T:All', filter: {}, default: true, showCount: true },
				{ label: 'T:Enabled', filter: { enabled: true }, showCount: true },
				{ label: 'T:Last run failed', filter: { lastRunStatus: 'failed' }, showCount: true },
			],
		})
		expect(Object.keys(state.columns[0])).toEqual(['key', 'label', 'secondary', 'secondaryLabels', 'cellClass'])
	})

	it('wires the cell functions to the row and the page', () => {
		const vm = { testRun: vi.fn(), setEnabled: vi.fn() }
		const { rowActions, columns } = jobs.data.call(vm)
		const row = { name: 'Nightly', enabled: true, scheduleCron: '0 2 * * *' }
		rowActions[0].handler(row)
		expect(vm.testRun).toHaveBeenCalledWith(row)
		const enabled = columns[6].widgetProps
		expect(enabled.checked(row)).toBe(true)
		expect(enabled.checked({ enabled: 'yes' })).toBe(false)
		enabled.onToggle(row, false)
		expect(vm.setEnabled).toHaveBeenCalledWith(row, false)
		expect(enabled.ariaLabel(row)).toBe('T:Run Nightly on its schedule')
		expect(typeof columns[3].secondary(row)).toBe('string')
		expect(columns[3].secondary({})).toBe('')
		expect(typeof columns[4].secondary({ lastRunStatus: 'failed' })).toBe('string')
	})
})
