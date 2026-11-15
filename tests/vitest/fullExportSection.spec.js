// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Take your data with you" on the BI export page: hidden unless the server
 * answers the state of the last export (administrators only), the state in
 * words, and Export all data starting an export and showing it queued.
 *
 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const dialogsMock = vi.hoisted(() => ({ showError: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/dialogs', () => dialogsMock)
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: { disabled: Boolean },
		emits: ['click'],
		render() {
			return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
}))

/**
 * Translate as the identity, filling `{name}` placeholders.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} [vars] The placeholder values.
 * @return {string} The text.
 */
function t(app, text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? String(vars[key]) : match))
}
globalThis.t = t

const { default: FullExportSection } = await import('../../src/components/FullExportSection.vue')

/**
 * Mount the section and let its first read settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountIt() {
	const w = mount(FullExportSection, { global: { mocks: { t } } })
	await flushPromises()
	return w
}

const stateLine = (w) => w.find('[data-testid="full-export-state"]').text()

beforeEach(() => {
	vi.clearAllMocks()
})

describe('FullExportSection', () => {
	it('stays hidden for anyone the server refuses', async () => {
		axiosMock.get.mockRejectedValue(Object.assign(new Error('403'), { response: { status: 403 } }))

		const w = await mountIt()

		expect(axiosMock.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/export/full')
		expect(w.find('[data-testid="full-export-section"]').exists()).toBe(false)
	})

	it('says no export was made yet', async () => {
		axiosMock.get.mockResolvedValue({ data: { state: 'none' } })

		const w = await mountIt()

		expect(w.find('h3').text()).toBe('Take your data with you')
		expect(stateLine(w)).toBe('No export has been made yet.')
		expect(w.find('[data-testid="full-export-start"]').attributes('disabled')).toBeUndefined()
	})

	it('names the archive of the last export', async () => {
		axiosMock.get.mockResolvedValue({ data: { state: 'ready', name: 'pipelinq-export-2026-11-14.zip', fileId: '42', at: '2026-11-14T10:00:00+00:00' } })

		const w = await mountIt()

		expect(stateLine(w)).toContain('Last export: pipelinq-export-2026-11-14.zip, in your Files since ')
	})

	it('says when the last export failed', async () => {
		axiosMock.get.mockResolvedValue({ data: { state: 'failed', at: '2026-11-14T10:00:00+00:00' } })

		const w = await mountIt()

		expect(stateLine(w)).toMatch(/^The last export, on .+, could not be built\. Try again\.$/)
	})

	it('starts an export and shows it queued, with the button off', async () => {
		axiosMock.get.mockResolvedValueOnce({ data: { state: 'none' } })
			.mockResolvedValueOnce({ data: { state: 'queued', at: '2026-11-14T10:00:00+00:00' } })
		axiosMock.post.mockResolvedValue({ status: 202, data: { status: 'queued' } })
		const w = await mountIt()

		await w.find('[data-testid="full-export-start"]').trigger('click')
		await flushPromises()

		expect(axiosMock.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/export/full')
		expect(stateLine(w)).toBe('The archive is being prepared. You get a notification when it is in your Files.')
		expect(w.find('[data-testid="full-export-start"]').attributes('disabled')).toBeDefined()
	})

	it('shows the server reason when the export cannot be started', async () => {
		axiosMock.get.mockResolvedValue({ data: { state: 'none' } })
		axiosMock.post.mockRejectedValue({ response: { data: { error: 'Only an administrator can export all data.' } } })
		const w = await mountIt()

		await w.find('[data-testid="full-export-start"]').trigger('click')
		await flushPromises()

		expect(dialogsMock.showError).toHaveBeenCalledWith('Only an administrator can export all data.')
		expect(stateLine(w)).toBe('No export has been made yet.')
	})
})
