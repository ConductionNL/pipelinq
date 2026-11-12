// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The props the detail-page header actions take from CnDetailPage's
 * `#actions` slot: the object the page shows and its id, each with the same
 * type and default.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn(), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {} }))
vi.mock('../../src/dialogs/LogVisitDialog.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/CancelBookingDialog.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/RescheduleBookingDialog.vue', () => ({ default: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

globalThis.t = (_app, text) => text

const actions = {
	BookingHeaderActions: (await import('../../src/components/bookings/BookingHeaderActions.vue')).default,
	LogVisitAction: (await import('../../src/components/LogVisitAction.vue')).default,
	SocialPostHeaderActions: (await import('../../src/components/marketing/SocialPostHeaderActions.vue')).default,
}

describe.each(Object.keys(actions))('%s', (name) => {
	const component = actions[name]

	it('declares the object and its id with their types and defaults', () => {
		const props = { ...Object.assign({}, ...(component.mixins || []).map((m) => m.props || {})), ...component.props }
		expect(props.object).toMatchObject({ type: Object, default: null })
		expect(props.objectId).toMatchObject({ type: String, default: '' })
	})

	it('takes them from the slot, and defaults to none', () => {
		const given = shallowMount(component, { props: { object: { id: 'o1' }, objectId: 'o1' }, global: { mocks: { t: globalThis.t } } })
		expect([given.vm.object, given.vm.objectId]).toEqual([{ id: 'o1' }, 'o1'])
		const bare = shallowMount(component, { global: { mocks: { t: globalThis.t } } })
		expect([bare.vm.object, bare.vm.objectId]).toEqual([null, ''])
	})
})
