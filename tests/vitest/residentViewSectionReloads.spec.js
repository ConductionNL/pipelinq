// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The resident-view panel beyond its first render: the handler row when the
 * portal shows it, a reload whenever the ticket, its message or its status
 * changes, the refresh channel released on unmount, and a failed load.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()
const channels = vi.hoisted(() => ({ subscribed: [], unsubscribed: [] }))
vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => get(...args) } }))
vi.mock('@nextcloud/event-bus', () => ({
	subscribe: (channel) => channels.subscribed.push(channel),
	unsubscribe: (channel) => channels.unsubscribed.push(channel),
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p, params = {}) => '/index.php' + p.replace(/\{(\w+)\}/g, (_m, k) => params[k]),
}))

globalThis.t = (_app, text) => text

const { default: ResidentViewSection } = await import('../../src/components/ResidentViewSection.vue')

const VIEW = { bespoke: { number: 'REQ-1', subject: 'Bin', assignee: 'Anna de Boer' }, portaliq: null, internalFields: [] }

function mountSection(props = {}) {
	return mount(ResidentViewSection, {
		props: { ticketId: 't-1', ticketType: 'request', ...props },
		global: { mocks: { t: (_app, text) => text }, stubs: { NcLoadingIcon: true } },
	})
}

describe('ResidentViewSection after its first render', () => {
	beforeEach(() => {
		get.mockReset()
		channels.subscribed.length = 0
		channels.unsubscribed.length = 0
	})

	it('names the handler when the portal does not hide them', async () => {
		get.mockResolvedValue({ data: VIEW })
		const wrapper = mountSection()
		await flushPromises()

		expect(wrapper.text()).toContain('Handler')
		expect(wrapper.text()).toContain('Anna de Boer')
	})

	it('reloads when the ticket, its message or its status changes', async () => {
		get.mockResolvedValue({ data: VIEW })
		const wrapper = mountSection()
		await flushPromises()
		expect(get).toHaveBeenCalledTimes(1)

		await wrapper.setProps({ ticketId: 't-2' })
		await flushPromises()
		expect(get).toHaveBeenLastCalledWith('/index.php/apps/pipelinq/api/tickets/t-2/resident-view')

		await wrapper.setProps({ customerMessage: 'We are on it' })
		await flushPromises()
		await wrapper.setProps({ status: 'completed' })
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(4)
	})

	it('releases the refresh channel it subscribed to when unmounted', async () => {
		get.mockResolvedValue({ data: VIEW })
		const wrapper = mountSection()
		await flushPromises()

		wrapper.unmount()

		expect(channels.subscribed).toHaveLength(1)
		expect(channels.unsubscribed).toEqual(channels.subscribed)
	})

	it('says so when the view cannot be loaded', async () => {
		get.mockRejectedValue(new Error('500'))
		const wrapper = mountSection()
		await flushPromises()

		expect(wrapper.text()).toContain('What the resident sees could not be loaded.')
	})

	it('loads nothing for a ticket that is not a request', async () => {
		mountSection({ ticketType: 'complaint' })
		await flushPromises()

		expect(get).not.toHaveBeenCalled()
	})
})
