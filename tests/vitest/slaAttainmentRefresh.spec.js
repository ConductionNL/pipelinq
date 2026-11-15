// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The SLA attainment breakdown on a page refresh. The KPI tiles on the same
 * page read the same endpoint, and the library shares one forced request
 * between every reader that passes the refresh's payload. The section must
 * pass it too, or the Refresh action sends the request twice.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-attainment-reporting
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const lib = vi.hoisted(() => ({ fetchEndpointSource: vi.fn() }))
const bus = vi.hoisted(() => ({ handlers: {} }))

vi.mock('@conduction/nextcloud-vue', () => lib)
vi.mock('@nextcloud/event-bus', () => ({
	subscribe: (channel, handler) => {
		bus.handlers[channel] = handler
	},
	unsubscribe: (channel) => {
		delete bus.handlers[channel]
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span />' },
	NcNoteCard: { name: 'NcNoteCard', template: '<div><slot /></div>' },
}))

let Section

beforeAll(async () => {
	Section = (
		await import('../../src/components/sla/SlaAttainmentBreakdownSection.vue')
	).default
})

beforeEach(() => {
	lib.fetchEndpointSource.mockReset()
	lib.fetchEndpointSource.mockResolvedValue({ details: { byGroup: [] } })
	bus.handlers = {}
})

const global = { mixins: [{ methods: { t: (app, text) => text } }] }

describe('SlaAttainmentBreakdownSection', () => {
	it('reads the shared endpoint on mount without forcing it', async () => {
		mount(Section, { global })
		await flushPromises()
		expect(lib.fetchEndpointSource).toHaveBeenCalledTimes(1)
		const [source, , opts] = lib.fetchEndpointSource.mock.calls[0]
		expect(source).toEqual({
			url: '/apps/pipelinq/api/sla/attainment',
			params: { bucket: 'month', groupBy: 'policy' },
		})
		expect(opts.force).toBe(false)
	})

	it('joins the page refresh with its payload, so the tiles and the table send one request', async () => {
		mount(Section, { global })
		await flushPromises()
		const payload = { waitUntil: vi.fn() }
		bus.handlers['cn:page:refresh'](payload)
		await flushPromises()
		const [, , opts] = lib.fetchEndpointSource.mock.calls[1]
		expect(opts).toEqual({ force: true, refresh: payload })
		expect(payload.waitUntil).toHaveBeenCalledTimes(1)
	})
})
