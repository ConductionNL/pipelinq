// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The resident's requests page as used: the list, opening a request with its
 * notes, replying when a reply is allowed (the list is read again), the
 * reasons a load, an open or a reply failed, and submitting a new request
 * with its confirmation, the rate-limit message or the server's reason.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
	requests: vi.fn(),
	request: vi.fn(),
	replyRequest: vi.fn(),
	submitRequest: vi.fn(),
}))
vi.mock('../../src/portal/portalApi.js', () => ({ portalApi: api }))

function t (app, text, vars) {
  return text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m))
}
globalThis.t = t

const { default: PortalRequests } =
	await import('../../src/portal/views/PortalRequests.vue')

async function mountPage() {
	const wrapper = mount(PortalRequests, { global: { mocks: { t } } })
	await flushPromises()
	return wrapper
}

const ROWS = [
	{
		id: 'r-1',
		number: 'V-1',
		subject: 'Paspoort',
		status: 'Open',
		date: '2026-11-01',
	},
]

describe('the portal requests page', () => {
	beforeEach(() => {
		Object.values(api).forEach((fn) => fn.mockReset())
		api.requests.mockResolvedValue({ items: ROWS })
	})

	it('lists the requests and opens one with its notes', async () => {
		api.request.mockResolvedValue({
			id: 'r-1',
			subject: 'Paspoort',
			body: 'Verlopen',
			notes: [{ author: 'Gemeente', message: 'In behandeling' }],
			canReply: false,
		})
		const wrapper = await mountPage()
		expect(wrapper.findAll('tbody td').map((td) => td.text())).toEqual([
			'V-1',
			'Paspoort',
			'Open',
			'2026-11-01',
		])

		await wrapper.find('tbody button').trigger('click')
		await flushPromises()
		expect(api.request).toHaveBeenCalledWith('r-1')
		expect(wrapper.find('.portal-detail h2').text()).toBe('Paspoort')
		expect(wrapper.find('.portal-detail li').text().replace(/\s+/g, ' ')).toBe(
			'Gemeente: In behandeling',
		)
		expect(wrapper.find('.portal-detail form').exists()).toBe(false)
	})

	it('sends a reply when allowed and reads the list again', async () => {
		api.request.mockResolvedValue({
			id: 'r-1',
			subject: 'Paspoort',
			notes: [],
			canReply: true,
		})
		api.replyRequest.mockResolvedValue({
			id: 'r-1',
			subject: 'Paspoort',
			notes: [{ author: 'Ik', message: 'Dank' }],
			canReply: true,
		})
		const wrapper = await mountPage()
		await wrapper.find('tbody button').trigger('click')
		await flushPromises()
		await wrapper.find('#portal-reply').setValue('Dank')
		await wrapper.find('.portal-detail form').trigger('submit')
		await flushPromises()
		expect(api.replyRequest).toHaveBeenCalledWith('r-1', 'Dank')
		expect(wrapper.find('#portal-reply').element.value).toBe('')
		expect(api.requests).toHaveBeenCalledTimes(2)
	})

	it('shows why loading, opening or replying failed', async () => {
		api.requests.mockRejectedValueOnce({})
		const empty = await mountPage()
		expect(empty.find('[role="alert"]').text()).toBe('Could not load requests.')
		expect(empty.find('table').exists()).toBe(false)

		api.request.mockRejectedValueOnce(new Error('Not yours'))
		const wrapper = await mountPage()
		await wrapper.find('tbody button').trigger('click')
		await flushPromises()
		expect(wrapper.find('[role="alert"]').text()).toBe('Not yours')

		api.request.mockResolvedValueOnce({ id: 'r-1', notes: [], canReply: true })
		api.replyRequest.mockRejectedValueOnce(new Error('Closed'))
		await wrapper.find('tbody button').trigger('click')
		await flushPromises()
		await wrapper.find('.portal-detail form').trigger('submit')
		await flushPromises()
		expect(wrapper.find('[role="alert"]').text()).toBe('Closed')
	})

	it('submits a new request and confirms it', async () => {
		api.submitRequest.mockResolvedValue({
			requestId: 'V-2',
			estimatedResponseTime: '5 days',
		})
		const wrapper = await mountPage()
		await wrapper.find('#portal-subject').setValue('Afval')
		await wrapper.find('#portal-body').setValue('Container kapot')
		await wrapper.findAll('form').at(-1).trigger('submit')
		await flushPromises()
		expect(api.submitRequest).toHaveBeenCalledWith({
			subject: 'Afval',
			body: 'Container kapot',
			categoryId: '',
			attachments: [],
		})
		expect(wrapper.find('[role="status"]').text()).toBe(
			'Your request has been submitted (V-2). Expected response: 5 days.',
		)
		expect(wrapper.find('#portal-subject').element.value).toBe('')
	})

	it('names a rate limit or the server reason when submitting fails, and marks the fields', async () => {
		api.submitRequest
			.mockRejectedValueOnce({ errorCode: 'rateLimited' })
			.mockRejectedValueOnce({})
			.mockRejectedValueOnce(new Error('Too long'))
		const wrapper = await mountPage()
		const messages = []
		for (let i = 0; i < 3; i++) {
			await wrapper.findAll('form').at(-1).trigger('submit')
			await flushPromises()
			messages.push(wrapper.find('[role="alert"]').text())
		}
		expect(messages).toEqual([
			'Please wait 60 minutes before submitting another request.',
			'Could not submit the request.',
			'Too long',
		])
		expect(wrapper.find('#portal-subject').attributes('aria-invalid')).toBe(
			'true',
		)
		expect(wrapper.find('#portal-body').attributes('aria-describedby')).toBe(
			'portal-requests-error',
		)
		expect(
			wrapper.find('button[type="submit"]').attributes('disabled'),
		).toBeUndefined()
	})
})
