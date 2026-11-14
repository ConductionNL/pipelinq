// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the export run detail page loads a run, labels its status, lists its
 * file manifest and schema snapshots, and retries a run that did not
 * finish. The export API and toasts are mocked.
 *
 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const exportApi = vi.hoisted(() => ({ getRun: vi.fn(), retryRun: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('../../src/services/exportApi.js', () => ({ exportApi }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@conduction/nextcloud-vue', () => {
	const stub = (name) => ({
		name,
		inheritAttrs: false,
		render() {
			return h('div', { 'data-name': name }, [this.$slots.header?.(), this.$slots.default?.()])
		},
	})
	return {
		CnDetailCard: stub('CnDetailCard'),
		CnDetailPage: stub('CnDetailPage'),
		CnStatusBadge: stub('CnStatusBadge'),
	}
})
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		inheritAttrs: false,
		render() {
			return h('button', this.$slots.default?.())
		},
	},
}))

globalThis.t = (_app, text) => text

const { default: ExportRunDetail } = await import('../../src/views/export/ExportRunDetail.vue')

/**
 * Mount the page.
 *
 * @param {object} props The props.
 * @param {object} params The route params.
 * @return {{wrapper: object, push: Function}} The wrapper and router push.
 */
function mountPage(props = {}, params = {}) {
	const push = vi.fn()
	const wrapper = mount(ExportRunDetail, {
		props,
		global: { mocks: { t: (_app, text) => text, $router: { push }, $route: { params } } },
	})
	return { wrapper, push }
}

beforeEach(() => {
	exportApi.getRun.mockReset()
	exportApi.retryRun.mockReset()
	dialogs.showError.mockReset()
	dialogs.showSuccess.mockReset()
})

describe('ExportRunDetail loading', () => {
	it('loads nothing without a run id', async () => {
		const { wrapper } = mountPage()
		await flushPromises()
		expect(exportApi.getRun).not.toHaveBeenCalled()
		expect(wrapper.vm.statusLabel).toBe('Unknown')
		expect(wrapper.vm.badgeStatus).toBe('info')
	})

	it('loads the run and its snapshots by route id', async () => {
		exportApi.getRun.mockResolvedValue({
			run: {
				status: 'succeeded',
				fileManifestJson: [{ path: 'a.csv', size_bytes: 10, rows_in_file: 2, sha256: 'abc', upload_status: 'ok' }],
			},
			snapshots: [{ pipelinqSchemaName: 'lead', comparedToPrevious: ['added: score'] }],
		})
		const { wrapper } = mountPage({}, { id: 'r-1' })
		await flushPromises()
		expect(exportApi.getRun).toHaveBeenCalledWith('r-1')
		expect(wrapper.vm.manifest).toHaveLength(1)
		expect(wrapper.text()).toContain('a.csv')
		expect(wrapper.text()).toContain('added: score')
		expect(wrapper.vm.statusLabel).toBe('Succeeded')
		expect(wrapper.vm.badgeStatus).toBe('success')
		expect(wrapper.vm.canRetry).toBe(false)
		expect(wrapper.vm.loading).toBe(false)
	})

	it('prefers the explicit prop over the route', () => {
		exportApi.getRun.mockResolvedValue({})
		const { wrapper } = mountPage({ exportRunId: 'p-1', id: 'p-2' }, { id: 'r-1' })
		expect(wrapper.vm.runId).toBe('p-1')
	})

	it('treats a missing run, snapshots or manifest as empty', async () => {
		exportApi.getRun.mockResolvedValue({ run: { fileManifestJson: 'not-a-list' } })
		const { wrapper } = mountPage({ id: 'r-1' })
		await flushPromises()
		expect(wrapper.vm.manifest).toEqual([])
		expect(wrapper.vm.snapshots).toEqual([])
		expect(wrapper.text()).toContain('No files in this run')
		expect(wrapper.text()).toContain('No schema snapshots')
		exportApi.getRun.mockResolvedValue({})
		await wrapper.vm.load()
		expect(wrapper.vm.run).toEqual({})
	})

	it('shows the error message, or its own, when loading fails', async () => {
		exportApi.getRun.mockRejectedValueOnce(new Error('Run not found'))
		const { wrapper } = mountPage({ id: 'r-1' })
		await flushPromises()
		expect(dialogs.showError).toHaveBeenLastCalledWith('Run not found')
		exportApi.getRun.mockRejectedValueOnce({})
		await wrapper.vm.load()
		expect(dialogs.showError).toHaveBeenLastCalledWith('Could not load the run')
		expect(wrapper.vm.loading).toBe(false)
	})
})

describe('ExportRunDetail status', () => {
	it.each([
		['pending', 'Queued', 'info', false],
		['running', 'Running', 'info', false],
		['partial', 'Partial', 'warning', true],
		['failed', 'Failed', 'error', true],
		['skipped_overlap', 'Skipped (overlap)', 'warning', true],
		['archived', 'archived', 'info', false],
	])('labels %s as %s with a %s badge', async (status, label, badge, retry) => {
		exportApi.getRun.mockResolvedValue({ run: { status } })
		const { wrapper } = mountPage({ id: 'r-1' })
		await flushPromises()
		expect(wrapper.vm.statusLabel).toBe(label)
		expect(wrapper.vm.badgeStatus).toBe(badge)
		expect(wrapper.vm.canRetry).toBe(retry)
		expect(wrapper.text().includes('Retry')).toBe(retry)
	})
})

describe('ExportRunDetail retry', () => {
	it('queues a new run and returns to the run list', async () => {
		exportApi.getRun.mockResolvedValue({ run: { status: 'failed' } })
		exportApi.retryRun.mockResolvedValue({})
		const { wrapper, push } = mountPage({ id: 'r-1' })
		await flushPromises()
		await wrapper.vm.retry()
		expect(exportApi.retryRun).toHaveBeenCalledWith('r-1')
		expect(dialogs.showSuccess).toHaveBeenCalledWith('A new run has been queued')
		expect(push).toHaveBeenCalledWith({ name: 'ExportRuns' })
		expect(wrapper.vm.busy).toBe(false)
	})

	it('stays on the page when the retry fails', async () => {
		exportApi.getRun.mockResolvedValue({ run: { status: 'failed' } })
		const { wrapper, push } = mountPage({ id: 'r-1' })
		await flushPromises()
		exportApi.retryRun.mockRejectedValueOnce(new Error('Locked'))
		await wrapper.vm.retry()
		expect(dialogs.showError).toHaveBeenLastCalledWith('Locked')
		exportApi.retryRun.mockRejectedValueOnce({})
		await wrapper.vm.retry()
		expect(dialogs.showError).toHaveBeenLastCalledWith('Could not retry the run')
		expect(push).not.toHaveBeenCalled()
		expect(wrapper.vm.busy).toBe(false)
	})
})
