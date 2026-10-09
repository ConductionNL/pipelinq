// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the resident sees on a request ticket (portal-resident-view-preview,
 * tasks 2.1 to 2.3).
 *
 * @spec openspec/specs/resident-view-preview/spec.md#requirement-a-request-ticket-previews-the-residents-view-req-rvp-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()
const handlers = {}
vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => get(...args) } }))
vi.mock('@nextcloud/event-bus', () => ({
	subscribe: (channel, fn) => {
		handlers[channel] = fn
	},
	unsubscribe: (channel) => {
		delete handlers[channel]
	},
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p, params = {}) =>
		'/index.php' + p.replace(/\{(\w+)\}/g, (_m, k) => params[k]),
}))

const { default: ResidentViewSection } =
	await import('../../src/components/ResidentViewSection.vue')

const VIEW = {
	bespoke: {
		number: 'REQ-1',
		subject: 'Bin not emptied',
		status: 'in_progress',
		body: 'Since Monday',
		assigneeHidden: true,
		notes: [{ author: 'handler', message: 'We will come by on Thursday.' }],
	},
	portaliq: null,
	internalFields: ['assignee', 'notes', 'priority'],
}

function mountSection(props) {
	return mount(ResidentViewSection, {
		props: { ticketId: 't-1', ticketType: 'request', ...props },
		global: {
			mocks: { t: (_app, text) => text },
			stubs: { NcLoadingIcon: true },
		},
	})
}

describe('ResidentViewSection', () => {
	beforeEach(() => get.mockReset())

	it('shows the resident portal view and no internal note', async () => {
		get.mockResolvedValue({ data: VIEW })
		const wrapper = mountSection()
		await flushPromises()
		expect(get).toHaveBeenCalledWith(
			'/index.php/apps/pipelinq/api/tickets/t-1/resident-view',
		)
		expect(wrapper.text()).toContain('Bin not emptied')
		expect(wrapper.text()).toContain('We will come by on Thursday.')
		expect(wrapper.text()).not.toContain('Handler')
		expect(wrapper.text()).toContain(
			'Everything else on this ticket stays internal.',
		)
	})

	it('lists the internal fields on demand', async () => {
		get.mockResolvedValue({ data: VIEW })
		const wrapper = mountSection()
		await flushPromises()
		expect(wrapper.find('[data-testid="resident-view-internal"]').exists()).toBe(
			false,
		)
		await wrapper.find('.resident-view__toggle').trigger('click')
		const list = wrapper.find('[data-testid="resident-view-internal"]').text()
		expect(list).toContain('Priority')
		expect(list).toContain('Notes')
	})

	it('shows the organisation portal panel when there is one', async () => {
		get.mockResolvedValue({
			data: {
				...VIEW,
				portaliq: {
					label: 'x',
					fields: { title: 'Bin', customerMessage: 'Thursday' },
				},
			},
		})
		const wrapper = mountSection()
		await flushPromises()
		const panel = wrapper.find('[data-testid="resident-view-portaliq"]').text()
		expect(panel).toContain('Message to the customer')
		expect(panel).toContain('Thursday')
	})

	it('draws nothing and reads nothing on a complaint', async () => {
		const wrapper = mountSection({ ticketType: 'complaint' })
		await flushPromises()
		expect(get).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="resident-view"]').exists()).toBe(false)
	})

	it('reloads after a saved message to the customer and on the page refresh signal', async () => {
		get.mockResolvedValue({ data: VIEW })
		const wrapper = mountSection({ customerMessage: 'old' })
		await flushPromises()
		await wrapper.setProps({ customerMessage: 'new' })
		await flushPromises()
		expect(get).toHaveBeenCalledTimes(2)
		handlers['cn:page:refresh']()
		await flushPromises()
		expect(get).toHaveBeenCalledTimes(3)
	})

	it('is mounted on TicketDetail with the ticket type and message as props', () => {
		const manifest = JSON.parse(
			fs.readFileSync(
				path.resolve(__dirname, '../../src/manifest.json'),
				'utf8',
			),
		)
		const page = manifest.pages.find((p) => p.id === 'TicketDetail')
		const widget = page.config.bodyWidgets.find(
			(w) => w.component === 'ResidentViewSection',
		)
		expect(widget.props).toEqual({
			ticketId: '@objectId',
			ticketType: '@object.ticketType',
			customerMessage: '@object.customerMessage',
			status: '@object.status',
		})
		expect(widget.title).toBeUndefined()
	})
})
