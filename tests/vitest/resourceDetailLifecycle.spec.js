// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The resource page's wiring: the live subscription it opens and what it reads on mount.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const subscription = vi.hoisted(() => ({ useObjectSubscription: vi.fn() }))
const objectStore = vi.hoisted(() => ({ store: null }))
const stub = (name) => ({ default: { name, render: () => null } })

vi.mock('@conduction/nextcloud-vue', async (orig) => ({ ...(await orig()), useObjectSubscription: (...args) => subscription.useObjectSubscription(...args) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../src/components/bookings/ResourceHoursEditor.vue', () => stub('A'))
vi.mock('../../src/components/bookings/ResourceVacationsEditor.vue', () => stub('B'))
vi.mock('../../src/components/KpiFigure.vue', () => stub('C'))
vi.mock('../../src/dialogs/DeleteResourceDialog.vue', () => stub('D'))
vi.mock('../../src/views/bookings/ResourceForm.vue', () => stub('E'))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objectStore.store }))

let ResourceDetail

beforeAll(async () => {
	ResourceDetail = (await import('../../src/views/bookings/ResourceDetail.vue')).default
}, 60000)

beforeEach(() => {
	subscription.useObjectSubscription.mockReset()
	objectStore.store = { objectTypeRegistry: { resource: { register: 'r' } } }
})

describe('ResourceDetail', () => {
	it('subscribes to the stored resource, enabled once the resource type is registered', () => {
		expect(ResourceDetail.setup({ id: 'r-1' })).toEqual({})

		const [store, type, liveId, options] = subscription.useObjectSubscription.mock.calls[0]
		expect(store).toBe(objectStore.store)
		expect(type).toBe('appointmentResource')
		expect(liveId.value).toBe('r-1')
		expect(options.enabled.value).toBe(true)
	})

	it('keeps a new resource, or an unregistered type, out of the subscription', () => {
		ResourceDetail.setup({ id: 'new' })
		const [, , newId, newOptions] = subscription.useObjectSubscription.mock.calls[0]
		expect(newId.value).toBeNull()
		expect(newOptions.enabled.value).toBe(false)

		objectStore.store = { objectTypeRegistry: null }
		ResourceDetail.setup({ id: 'r-1' })
		expect(subscription.useObjectSubscription.mock.calls[1][3].enabled.value).toBe(false)
	})

	it('reads the object store from the store module', () => {
		expect(ResourceDetail.computed.objectStore.call({})).toBe(objectStore.store)
	})

	it('reads the resource, then its week figures and staff line, on mount', async () => {
		const order = []
		const ctx = {
			isNew: false,
			resourceId: 'r-1',
			objectStore: { fetchObject: vi.fn(async () => order.push('resource')) },
			fetchWeekFigures: vi.fn(async () => order.push('week')),
			fetchStaffLine: vi.fn(async () => order.push('staff')),
		}

		await ResourceDetail.mounted.call(ctx)

		expect(ctx.objectStore.fetchObject).toHaveBeenCalledWith('appointmentResource', 'r-1')
		expect(order[0]).toBe('resource')
		expect(order.slice(1).sort()).toEqual(['staff', 'week'])
	})

	it('reads nothing for a new resource', async () => {
		const ctx = { isNew: true, objectStore: { fetchObject: vi.fn() }, fetchWeekFigures: vi.fn(), fetchStaffLine: vi.fn() }
		await ResourceDetail.mounted.call(ctx)
		expect(ctx.objectStore.fetchObject).not.toHaveBeenCalled()
		expect(ctx.fetchWeekFigures).not.toHaveBeenCalled()
	})
})
