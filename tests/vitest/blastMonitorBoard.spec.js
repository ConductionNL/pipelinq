// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Board PqVerzendmonitor: the blast name as a bold page title, "Verzending
 * annuleren" as an outlined button in the error ink, and the recent events
 * stamped to the minute ("10:02").
 *
 * @spec openspec/specs/marketing-ui/spec.md#requirement-live-send-monitor
 */
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

const at = new Date(2026, 9, 28, 10, 2, 41).toISOString()
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(async (url) => (url.includes('/deliveries')
			? { data: [{ id: 'e1', deliveredAt: at, email: 'anna@ziggo.example', status: 'delivered' }] }
			: { data: { id: 'b1', name: 'Subsidieronde', status: 'sending', totals: { queued: 4, sent: 6 } } })),
		post: vi.fn(),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { props: ['variant'], template: '<button :data-variant="variant"><slot /></button>' },
	NcLoadingIcon: { template: '<i />' },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnBuildiqEditButton: { template: '<span />' },
	CnStatusBadge: { props: ['label'], template: '<span class="badge">{{ label }}</span>' },
}))

globalThis.t = (_app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, k) => vars[k]) : text)
const { default: BlastMonitor } = await import('../../src/views/blasts/BlastMonitor.vue')
const source = readFileSync(path.resolve(__dirname, '../../src/views/blasts/BlastMonitor.vue'), 'utf8')

/**
 * @return {Promise<object>} The mounted monitor.
 */
async function mountMonitor() {
	const wrapper = mount(BlastMonitor, {
		props: { id: 'b1' },
		global: { mocks: { t: globalThis.t }, stubs: { 'router-link': { template: '<a><slot /></a>' } } },
	})
	await flushPromises()
	return wrapper
}

describe('the send monitor as board PqVerzendmonitor', () => {
	it('stamps a recent event to the minute', async () => {
		const wrapper = await mountMonitor()
		expect(wrapper.find('.blast-monitor__timeline time').text()).toBe('10:02')
		wrapper.unmount()
	})

	it('draws the cancel button outlined in the error ink, not filled', async () => {
		const wrapper = await mountMonitor()
		const cancel = wrapper.find('.blast-monitor__cancel')
		expect(cancel.exists()).toBe(true)
		expect(cancel.attributes('data-variant')).toBe('secondary')
		expect(source).toMatch(/\.blast-monitor__cancel[^{]*\{[^}]*color: var\(--color-error-text\)/)
		wrapper.unmount()
	})

	it('sets the page title bold at 28px on a 1.2 line', () => {
		expect(source).toMatch(/\.blast-monitor__header h1 \{[^}]*font-weight: 700;[^}]*line-height: 1\.2;/)
	})

	it('draws the progress figure at 28px and the event rows at 14px', () => {
		expect(source).toMatch(/strong \{\s*font-size: 28px;\s*line-height: 1\.2;/)
		expect(source).toMatch(/\.blast-monitor__timeline li \{[^}]*font-size: 14px;/)
	})
})
