// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the booking pages write a moment, an amount and a booking status.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnActionsMenu: {}, CnAuditTrailCard: {}, CnDetailCard: {}, CnDetailPage: {}, CnFormDialog: {}, CnStatusBadge: {}, CnTab: {}, CnTabs: {}, openRowTarget: vi.fn(), useObjectSubscription: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcLoadingIcon: {}, NcSelect: {}, NcTextField: {}, NcCheckboxRadioSwitch: {}, NcTextArea: {} }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/components/bookings/ServiceStepsEditor.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/DeleteServiceDialog.vue', () => ({ default: {} }))
vi.mock('../../src/views/bookings/ServiceForm.vue', () => ({ default: {} }))

let section
let card
let service

beforeAll(async () => {
	globalThis.t = (app, text) => `[${text}]`
	section = (await import('../../src/components/bookings/BookingDetailSection.vue')).default
	card = (await import('../../src/components/bookings/BookingsCard.vue')).default
	service = (await import('../../src/views/bookings/ServiceDetail.vue')).default
}, 60000)

describe('the booking formatting', () => {
	it('writes a moment in Dutch, a dash for none, and the value itself when it does not read', () => {
		const iso = '2026-11-10T09:30:00Z'
		for (const page of [section, card]) {
			expect(page.methods.formatDateTime(iso)).toBe(new Date(iso).toLocaleString('nl-NL'))
			expect(page.methods.formatDateTime('')).toBe('-')
			expect(page.methods.formatDateTime(null)).toBe('-')
			expect(page.methods.formatDateTime('nope')).toBe(new Date('nope').toLocaleString('nl-NL'))
		}
	})

	it('writes an amount in its currency, zero for no number, the code for an unknown currency', () => {
		for (const page of [section, service]) {
			expect(page.methods.formatCurrency(12.5, 'USD')).toBe(new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'USD', maximumFractionDigits: 2 }).format(12.5))
			expect(page.methods.formatCurrency('x', 'EUR')).toBe(new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR', maximumFractionDigits: 2 }).format(0))
			expect(page.methods.formatCurrency(3)).toBe(page.methods.formatCurrency(3, undefined))
		}
	})

	it('names a booking status, the status itself when unknown, and a dash for none', () => {
		for (const page of [section, card]) {
			expect(page.methods.statusLabel('no-show')).toBe('[No-show]')
			expect(page.methods.statusLabel('cancelled-by-business')).toBe('[Cancelled (business)]')
			expect(page.methods.statusLabel('odd')).toBe('[odd]')
			expect(page.methods.statusLabel('')).toBe('[-]')
		}
		expect(service.methods.statusLabel('archived')).toBe('[Archived]')
	})
})
