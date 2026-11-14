// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The service page's wiring: the live subscription it opens, what it reads on mount,
 * the steps watcher, the board header and side column it declares, and the resource
 * type label.
 *
 * @spec openspec/changes/round3-review-points/specs/appointment-booking/spec.md
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const subscription = vi.hoisted(() => ({ useObjectSubscription: vi.fn() }))
const objectStore = vi.hoisted(() => ({ store: { objectTypeRegistry: { service: { register: 'r' } } } }))

vi.mock('@conduction/nextcloud-vue', async (orig) => ({ ...(await orig()), useObjectSubscription: (...args) => subscription.useObjectSubscription(...args) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../src/components/bookings/ServiceStepsEditor.vue', () => ({ default: { name: 'A', render: () => null } }))
vi.mock('../../src/dialogs/DeleteServiceDialog.vue', () => ({ default: { name: 'B', render: () => null } }))
vi.mock('../../src/views/bookings/ServiceForm.vue', () => ({ default: { name: 'C', render: () => null } }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objectStore.store }))

let ServiceDetail

beforeAll(async () => {
	globalThis.t = (app, text) => text
	ServiceDetail = (await import('../../src/views/bookings/ServiceDetail.vue')).default
}, 60000)

beforeEach(() => {
	subscription.useObjectSubscription.mockReset()
	objectStore.store = { objectTypeRegistry: { service: { register: 'r' } } }
})

describe('ServiceDetail setup', () => {
	it('subscribes to the stored service, enabled once the service type is registered', () => {
		expect(ServiceDetail.setup({ id: 's-1' })).toEqual({})

		const [store, type, liveId, options] = subscription.useObjectSubscription.mock.calls[0]
		expect(store).toBe(objectStore.store)
		expect(type).toBe('appointmentService')
		expect(liveId.value).toBe('s-1')
		expect(options.enabled.value).toBe(true)
	})

	it('keeps a new service, or an unregistered type, out of the subscription', () => {
		ServiceDetail.setup({ id: 'new' })
		let [, , liveId, options] = subscription.useObjectSubscription.mock.calls[0]
		expect(liveId.value).toBeNull()
		expect(options.enabled.value).toBe(false)

		objectStore.store = { objectTypeRegistry: {} }
		ServiceDetail.setup({ id: 's-1' })
		;[, , liveId, options] = subscription.useObjectSubscription.mock.calls[1]
		expect(liveId.value).toBe('s-1')
		expect(options.enabled.value).toBe(false)
	})
})

describe('ServiceDetail declarations', () => {
	it('declares the board header with its status pill and meta line', () => {
		const header = ServiceDetail.computed.boardHeader.call({})
		expect(header.breadcrumb).toEqual({ label: 'Services', route: 'Services', separator: '/' })
		expect(header.statusPill.field).toBe('status')
		expect(header.statusPill.colorMap).toEqual({ active: 'success', draft: 'default', archived: 'default' })
		expect(header.headerMeta).toBe('{durationMinutes} minutes · {price|currency}')
		expect(Object.isFrozen(header)).toBe(true)
	})

	it('declares the bookings figure, the upcoming appointments and the matching resources in the side column', () => {
		const side = ServiceDetail.computed.sideColumn.call({})
		expect(side.map((card) => [card.id, card.type])).toEqual([['service-bookings', 'stat'], ['service-appointments', 'record-list'], ['service-resources', 'record-list']])
		expect(side[0].content).toMatchObject({ endpointSource: { url: '/apps/pipelinq/api/appointment-services/@objectId/figures' }, valueField: 'bookingsThisWeek' })
		expect(side[1].content).toMatchObject({ schema: 'appointmentBooking', filter: { serviceId: '@objectId', 'startAt[gte]': '@now' }, sort: { startAt: 'asc' }, rowRoute: 'BookingDetail' })
		expect(side[2].content).toMatchObject({ endpointSource: { url: '/apps/pipelinq/api/appointment-services/@objectId/eligible-resources', responsePath: 'resources' }, rowRoute: 'ResourceDetail', emptyText: 'No resource matches' })
	})

	it('reads the object store from the store module', () => {
		expect(ServiceDetail.computed.objectStore.call({})).toBe(objectStore.store)
	})
})

describe('ServiceDetail lifecycle', () => {
	it('reads the stored service on mount, and not for a new one', async () => {
		const fetchObject = vi.fn()
		await ServiceDetail.mounted.call({ isNew: false, serviceId: 's-1', objectStore: { fetchObject } })
		expect(fetchObject).toHaveBeenCalledWith('appointmentService', 's-1')

		fetchObject.mockClear()
		await ServiceDetail.mounted.call({ isNew: true, serviceId: 'new', objectStore: { fetchObject } })
		expect(fetchObject).not.toHaveBeenCalled()
	})

	it('loads the product names of the steps whenever they change, at once', () => {
		const loadProductNames = vi.fn()
		const steps = [{ productId: 'p-1' }]
		ServiceDetail.watch.steps.handler.call({ loadProductNames }, steps)
		expect(loadProductNames).toHaveBeenCalledWith(steps)
		expect(ServiceDetail.watch.steps.immediate).toBe(true)
	})

	it('labels a resource type, and keeps an unknown one as it is', () => {
		expect(ServiceDetail.methods.resourceTypeLabel('staff')).toBe('Staff')
		expect(ServiceDetail.methods.resourceTypeLabel('hovercraft')).toBe('hovercraft')
	})
})
