/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The row actions of the BI export lists: the list view each page asks for,
 * row navigation, the connection test, the run retry and the job switch.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
	openRowTarget: vi.fn(),
	useListView: vi.fn((schema, options) => ({ schema, options })),
	showError: vi.fn(),
	showSuccess: vi.fn(),
	inject: vi.fn(() => 'sidebar-state'),
	exportApi: {
		testDestination: vi.fn(),
		retryRun: vi.fn(),
		enableJob: vi.fn(),
		disableJob: vi.fn(),
	},
}))

vi.mock('@conduction/nextcloud-vue', () => ({ CnIndexPage: {}, openRowTarget: mocks.openRowTarget, useListView: mocks.useListView }))
vi.mock('@nextcloud/dialogs', () => ({ showError: mocks.showError, showSuccess: mocks.showSuccess }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {} }))
// The node-environment SFC build wraps setup() in a useSSRContext() call.
vi.mock('vue', async (importOriginal) => ({ ...(await importOriginal()), inject: mocks.inject, useSSRContext: () => ({}) }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => 'object-store' }))
vi.mock('../../src/services/exportApi.js', () => ({ exportApi: mocks.exportApi }))
vi.mock('../../src/modals/ExportTestRunModal.vue', () => ({ default: {} }))
vi.mock('../../src/components/FullExportSection.vue', () => ({ default: {} }))

const pages = {}

beforeAll(async () => {
	globalThis.t = (app, text) => text
	for (const name of ['ExportDestinations', 'ExportRuns', 'ExportJobs']) {
		pages[name] = (await import(`../../src/views/export/${name}.vue`)).default
	}
}, 60000)

beforeEach(() => {
	vi.clearAllMocks()
})

/**
 * A method context: the translator, a router, a refresh and the busy row.
 *
 * @param {object} [extra] Fields to add.
 * @return {object} The context.
 */
function context(extra = {}) {
	return {
		t: globalThis.t,
		$router: { push: vi.fn() },
		refresh: vi.fn(async () => {}),
		busyId: 'untouched',
		...extra,
	}
}

describe('ExportDestinations', () => {
	const page = () => pages.ExportDestinations

	it('asks for the destination list view with the injected sidebar and the object store', () => {
		expect(page().setup()).toEqual({ schema: 'exportDestination', options: { sidebarState: 'sidebar-state', objectStore: 'object-store' } })
		expect(mocks.inject).toHaveBeenCalledWith('sidebarState', null)
	})

	it('shows its columns in order', () => {
		expect(page().computed.visibleColumns.call({})).toEqual(['name', 'type', 'connectorSourceId', 'validationStatus', 'lastValidatedAt'])
	})

	it('opens a destination and starts a new one', () => {
		const ctx = context()
		const event = { button: 1 }
		page().methods.openDestination.call(ctx, { id: 'd-1' }, event)
		expect(mocks.openRowTarget).toHaveBeenCalledWith(event, { name: 'ExportDestinationDetail', params: { id: 'd-1' } }, ctx.$router)

		page().methods.createNew.call(ctx)
		expect(ctx.$router.push).toHaveBeenCalledWith({ name: 'ExportDestinationNew' })
	})

	it('reports a valid connection, refreshes, and frees the row', async () => {
		const ctx = context()
		mocks.exportApi.testDestination.mockImplementation(async () => {
			expect(ctx.busyId).toBe('d-1')
			return { valid: true }
		})
		await page().methods.testConnection.call(ctx, { id: 'd-1' })
		expect(mocks.exportApi.testDestination).toHaveBeenCalledWith('d-1')
		expect(mocks.showSuccess).toHaveBeenCalledWith('Connection valid')
		expect(mocks.showError).not.toHaveBeenCalled()
		expect(ctx.refresh).toHaveBeenCalledTimes(1)
		expect(ctx.busyId).toBe(null)
	})

	it('reports a failed connection and still refreshes', async () => {
		const ctx = context()
		mocks.exportApi.testDestination.mockResolvedValue({ valid: false })
		await page().methods.testConnection.call(ctx, { id: 'd-1' })
		expect(mocks.showError).toHaveBeenCalledWith('Connection failed')
		expect(mocks.showSuccess).not.toHaveBeenCalled()
		expect(ctx.refresh).toHaveBeenCalledTimes(1)
	})

	it('reports a test that could not run, without refreshing', async () => {
		const ctx = context()
		mocks.exportApi.testDestination.mockRejectedValue(new Error('500'))
		await page().methods.testConnection.call(ctx, { id: 'd-1' })
		expect(mocks.showError).toHaveBeenCalledWith('Connection test failed')
		expect(ctx.refresh).not.toHaveBeenCalled()
		expect(ctx.busyId).toBe(null)
	})
})

describe('ExportRuns', () => {
	const page = () => pages.ExportRuns

	it('asks for the run list view, newest start first', () => {
		expect(page().setup()).toEqual({
			schema: 'exportRun',
			options: { sidebarState: 'sidebar-state', objectStore: 'object-store', defaultSort: { key: 'startedAt', order: 'desc' } },
		})
	})

	it('shows its columns in order', () => {
		expect(page().computed.visibleColumns.call({})).toEqual(['jobId', 'status', 'rowCount', 'byteCount', 'startedAt', 'endedAt'])
	})

	it('retries only failed, partial and skipped runs', () => {
		const canRetry = page().methods.canRetry
		expect(['failed', 'partial', 'skipped_overlap', 'succeeded', 'running', 'pending', undefined].map((status) => canRetry({ status })))
			.toEqual([true, true, true, false, false, false, false])
	})

	it('opens a run', () => {
		const ctx = context()
		page().methods.openRun.call(ctx, { id: 'r-1' }, undefined)
		expect(mocks.openRowTarget).toHaveBeenCalledWith(undefined, { name: 'ExportRunDetail', params: { id: 'r-1' } }, ctx.$router)
	})

	it('queues a retry, refreshes and frees the row', async () => {
		const ctx = context()
		mocks.exportApi.retryRun.mockImplementation(async () => expect(ctx.busyId).toBe('r-1'))
		await page().methods.retry.call(ctx, { id: 'r-1' })
		expect(mocks.exportApi.retryRun).toHaveBeenCalledWith('r-1')
		expect(mocks.showSuccess).toHaveBeenCalledWith('A new run has been queued')
		expect(ctx.refresh).toHaveBeenCalledTimes(1)
		expect(ctx.busyId).toBe(null)
	})

	it('shows the server message of a failed retry, or a fallback', async () => {
		const ctx = context()
		mocks.exportApi.retryRun.mockRejectedValueOnce(new Error('Job is disabled'))
		await page().methods.retry.call(ctx, { id: 'r-1' })
		mocks.exportApi.retryRun.mockRejectedValueOnce(new Error(''))
		await page().methods.retry.call(ctx, { id: 'r-2' })
		expect(mocks.showError.mock.calls).toEqual([['Job is disabled'], ['Could not retry the run']])
		expect(ctx.refresh).not.toHaveBeenCalled()
		expect(ctx.busyId).toBe(null)
	})
})

describe('ExportJobs', () => {
	const page = () => pages.ExportJobs

	it('opens a job, starts a new one and opens the test run of a row', () => {
		const ctx = context({ testRunJobId: null })
		page().methods.openJob.call(ctx, { id: 'j-1' }, 'evt')
		expect(mocks.openRowTarget).toHaveBeenCalledWith('evt', { name: 'ExportJobDetail', params: { id: 'j-1' } }, ctx.$router)
		page().methods.createNew.call(ctx)
		expect(ctx.$router.push).toHaveBeenCalledWith({ name: 'ExportJobNew' })
		page().methods.testRun.call(ctx, { id: 'j-2' })
		expect(ctx.testRunJobId).toBe('j-2')
	})

	it('enables and disables a job, then refreshes the index', async () => {
		const onRefreshEvent = vi.fn(async () => {})
		const ctx = context({ $refs: { index: { onRefreshEvent } } })
		await page().methods.setEnabled.call(ctx, { id: 'j-1' }, true)
		await page().methods.setEnabled.call(ctx, { id: 'j-1' }, false)
		expect(mocks.exportApi.enableJob).toHaveBeenCalledWith('j-1')
		expect(mocks.exportApi.disableJob).toHaveBeenCalledWith('j-1')
		expect(mocks.showSuccess.mock.calls).toEqual([['Job enabled'], ['Job disabled']])
		expect(onRefreshEvent).toHaveBeenCalledTimes(2)
	})

	it('works without a mounted index', async () => {
		await expect(page().methods.setEnabled.call(context({ $refs: {} }), { id: 'j-1' }, true)).resolves.toBeUndefined()
	})

	it('reports a failed switch and rethrows it so the toggle reverts', async () => {
		const error = new Error('Destination is invalid')
		mocks.exportApi.enableJob.mockRejectedValueOnce(error)
		await expect(page().methods.setEnabled.call(context({ $refs: {} }), { id: 'j-1' }, true)).rejects.toBe(error)
		expect(mocks.showError).toHaveBeenCalledWith('Could not change job status')
		expect(mocks.showSuccess).not.toHaveBeenCalled()
	})
})
