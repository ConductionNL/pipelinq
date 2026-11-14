// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The public booking portal client: every call goes under /portal/api/booking
 * and both answer shapes (a bare list, or a wrapped one) are read.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const axios = { get: vi.fn(), post: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

const api = await import('../../src/services/bookingPortalApi.js')
const base = '/index.php/apps/pipelinq/portal/api/booking'

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

describe('bookingPortalApi', () => {
	it('reads the services from a bare list or a wrapped one', async () => {
		axios.get.mockResolvedValueOnce({ data: [{ id: 's1' }] })
		expect(await api.fetchServices()).toEqual([{ id: 's1' }])
		expect(axios.get).toHaveBeenLastCalledWith(base + '/services')
		axios.get.mockResolvedValueOnce({ data: { services: [{ id: 's2' }] } })
		expect(await api.fetchServices()).toEqual([{ id: 's2' }])
		axios.get.mockResolvedValueOnce({ data: {} })
		expect(await api.fetchServices()).toEqual([])
	})

	it('finds a service by slug or by id, and null when neither matches', async () => {
		axios.get.mockResolvedValue({ data: [{ id: 's1', slug: 'intake' }, { id: 's2', slug: 'other' }] })
		expect(await api.fetchServiceBySlug('intake')).toEqual({ id: 's1', slug: 'intake' })
		expect(await api.fetchServiceBySlug('s2')).toEqual({ id: 's2', slug: 'other' })
		expect(await api.fetchServiceBySlug('missing')).toBeNull()
	})

	it('asks availability for a service and date, in either answer shape', async () => {
		axios.get.mockResolvedValueOnce({ data: ['09:00'] })
		expect(await api.fetchAvailability('s1', '2026-11-12')).toEqual(['09:00'])
		expect(axios.get).toHaveBeenLastCalledWith(base + '/availability', { params: { serviceId: 's1', date: '2026-11-12' } })
		axios.get.mockResolvedValueOnce({ data: { slots: ['10:00'] } })
		expect(await api.fetchAvailability('s1', 'd')).toEqual(['10:00'])
		axios.get.mockResolvedValueOnce({ data: {} })
		expect(await api.fetchAvailability('s1', 'd')).toEqual([])
	})

	it('posts a booking and returns the answer', async () => {
		axios.post.mockResolvedValueOnce({ data: { id: 'b1' } })
		expect(await api.submitBooking({ serviceId: 's1' })).toEqual({ id: 'b1' })
		expect(axios.post).toHaveBeenCalledWith(base + '/book', { serviceId: 's1' })
	})

	it('reads a booking by its encoded id directly under the base', async () => {
		axios.get.mockResolvedValueOnce({ data: { id: 'a/b' } })
		expect(await api.fetchBooking('a/b')).toEqual({ id: 'a/b' })
		expect(axios.get).toHaveBeenCalledWith(base + '/a%2Fb')
	})
})
