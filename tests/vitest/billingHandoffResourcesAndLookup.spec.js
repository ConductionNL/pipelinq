// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client billing hand-off section (who may send, the period, and what
 * each answer from billing means), the bookable resource form, the booking
 * rows editor and the POS customer lookup, each run against a plain
 * component context.
 *
 * @spec openspec/specs/billing-handoff/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

const stub = (name) => ({ name, render: () => null })
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/vue', () => ({ NcButton: stub('NcButton'), NcLoadingIcon: stub('NcLoadingIcon'), NcNoteCard: stub('NcNoteCard'), NcSelect: stub('NcSelect'), NcTextField: stub('NcTextField') }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: stub('CnDialog') }))
vi.mock('../../src/components/bookings/ResourceHoursEditor.vue', () => ({ default: stub('ResourceHoursEditor') }))
vi.mock('../../src/components/bookings/ResourceVacationsEditor.vue', () => ({ default: stub('ResourceVacationsEditor') }))
const billingApi = vi.hoisted(() => ({ getBillingHandoffAvailability: vi.fn(), sendToBilling: vi.fn() }))
vi.mock('../../src/services/billingHandoffApi.js', () => billingApi)
vi.mock('../../src/services/posCustomerApi.js', () => ({ searchCustomers: vi.fn() }))

const { default: Handoff } = await import('../../src/components/billing/ClientBillingHandoffSection.vue')
const { default: ResourceForm } = await import('../../src/views/bookings/ResourceForm.vue')
const { default: BookingRowsEditor } = await import('../../src/components/bookings/BookingRowsEditor.vue')
const { default: CustomerLookupModal } = await import('../../src/modals/CustomerLookupModal.vue')
const { searchCustomers } = await import('../../src/services/posCustomerApi.js')

/**
 * A component context: props and extras, data, computed values as getters
 * and methods bound.
 *
 * @param {object} comp The component definition.
 * @param {object} [props] Its props.
 * @param {object} [extra] Anything else on `this`.
 * @return {object} The context.
 */
function context(comp, props = {}, extra = {}) {
	const ctx = { $emit: vi.fn(), ...props, ...extra }
	Object.assign(ctx, comp.data ? comp.data.call(ctx) : {})
	for (const [name, def] of Object.entries(comp.computed || {})) {
		Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
	}
	for (const [name, fn] of Object.entries(comp.methods || {})) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	vi.clearAllMocks()
	for (const fn of [...Object.values(billingApi), searchCustomers]) {
		fn.mockReset()
	}
})

afterEach(() => {
	vi.useRealTimers()
})

describe('ClientBillingHandoffSection', () => {
	const section = (props = {}) => context(Handoff, { clientId: '', cnSectionContext: null, ...props })

	it('proposes the current month as the period', () => {
		vi.useFakeTimers({ now: new Date(2026, 1, 10, 12), toFake: ['Date'] })
		const s = section()
		const iso = (d) => d.toISOString().slice(0, 10)
		expect([s.periodStart, s.periodEnd]).toEqual([iso(new Date(2026, 1, 1)), iso(new Date(2026, 1, 28))])
	})

	it('loads who may send for the client of the page, nothing without one', async () => {
		const s = section({ cnSectionContext: { value: { objectId: 'c1' } } })
		billingApi.getBillingHandoffAvailability.mockResolvedValueOnce({ available: true, isManager: true, deepLinkUrl: '/shillinq' })
		s.lastResult = { status: 'synced' }
		Handoff.watch.resolvedId.handler.call(s)
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect([billingApi.getBillingHandoffAvailability.mock.calls[0], s.lastResult, s.showSendControls, s.hasContent, s.deepLinkUrl]).toEqual([['c1'], null, true, true, '/shillinq'])
		billingApi.getBillingHandoffAvailability.mockRejectedValueOnce(new Error('x'))
		await s.loadAvailability()
		expect([s.showSendControls, s.hasContent, s.loading]).toEqual([false, false, false])
		const none = section()
		none.availability = { available: true, isManager: true }
		await none.loadAvailability()
		expect([none.showSendControls, billingApi.getBillingHandoffAvailability.mock.calls.length]).toEqual([false, 2])
	})

	it('shows the link to billing to someone who may not send', () => {
		const s = section({ clientId: 'c1' })
		s.availability = { available: true, isManager: false, deepLinkUrl: '/x' }
		expect([s.showSendControls, s.hasContent]).toEqual([false, true])
	})

	it('sends the period once, and only with a client and both dates', async () => {
		const s = section({ clientId: 'c1' })
		Object.assign(s, { periodStart: '2026-01-01', periodEnd: '2026-01-31' })
		billingApi.sendToBilling.mockResolvedValueOnce({ status: 'synced', invoiceNumber: 'F-1', entryCount: 4 })
		await s.send()
		expect(billingApi.sendToBilling).toHaveBeenCalledWith('c1', '2026-01-01', '2026-01-31')
		expect([s.lastResultType, s.lastResultMessage, s.busy]).toEqual(['success', 'Sent to billing — draft invoice F-1 created (4 entries).', false])
		s.periodEnd = ''
		await s.send()
		s.periodEnd = '2026-01-31'
		s.busy = true
		await s.send()
		await section().send()
		expect(billingApi.sendToBilling).toHaveBeenCalledTimes(1)
	})

	it('explains each answer from billing', async () => {
		const s = section({ clientId: 'c1' })
		const says = (lastResult) => {
			s.lastResult = lastResult
			return [s.lastResultType, s.lastResultMessage]
		}
		expect(says(null)).toEqual(['success', ''])
		expect(says({ status: 'synced', duplicated: true, invoiceNumber: 'F-1' })).toEqual(['success', 'Already sent — Shillinq returned the existing draft invoice F-1.'])
		expect(says({ status: 'empty' })).toEqual(['success', 'No approved, un-billed hours in this period.'])
		expect(says({ status: 'conflict' })).toEqual(['warning', 'This batch is already being processed by Shillinq — try again shortly.'])
		expect(says({ status: 'unmapped', message: 'No rate' })).toEqual(['error', 'No rate'])
		expect(says({ status: 'unmapped' })[1]).toMatch(/^Shillinq could not resolve this client or a rate/)
		expect(says({ status: 'failed', message: 'Down' })).toEqual(['error', 'Down'])
		expect(says({ status: 'odd' })).toEqual(['error', 'Could not send this batch to billing.'])
		billingApi.sendToBilling.mockRejectedValueOnce({ response: { data: { status: 'conflict' } } })
		await s.send()
		expect(s.lastResult).toEqual({ status: 'conflict' })
		billingApi.sendToBilling.mockRejectedValueOnce(new Error('x'))
		await s.send()
		expect(s.lastResult).toEqual({ status: 'failed' })
	})
})

describe('ResourceForm', () => {
	const form = (resource = {}) => {
		const f = context(ResourceForm, { resource }, { emptyForm: ResourceForm.methods.emptyForm })
		ResourceForm.watch.resource.handler.call(f, resource)
		return f
	}

	it('starts empty and fills from a resource with its own copies', () => {
		const fresh = form()
		expect([fresh.form.type, fresh.form.bookable, fresh.form.maxConcurrent, fresh.skillsCsv]).toEqual(['staff', true, 1, ''])
		const resource = { id: 'r1', name: 'Room A', type: 'room', skills: ['wifi', 'beamer'], workingHours: [{ day: 1, openTime: '09:00', closeTime: '17:00' }], vacations: 'none' }
		const f = form(resource)
		expect([f.form.name, f.skillsCsv, f.form.vacations, f.form.status]).toEqual(['Room A', 'wifi, beamer', [], 'active'])
		f.form.workingHours[0].day = 5
		expect(resource.workingHours[0].day).toBe(1)
		expect([f.typeOptions.map((o) => o.value), f.statusOptions.map((o) => o.value)]).toEqual([['staff', 'room', 'equipment'], ['active', 'inactive', 'archived']])
	})

	it('needs a name and a type, and sound hours and vacations', () => {
		const f = form({ name: 'Ann' })
		expect(f.isValid).toBe(true)
		f.form.workingHours = [{ openTime: '17:00', closeTime: '09:00' }]
		expect([f.hoursError, f.isValid]).toEqual(['Open time must be before close time.', false])
		f.form.workingHours = []
		f.form.vacations = [{ startDate: '2026-02-02', endDate: '2026-02-01' }]
		expect([f.vacationError, f.isValid]).toEqual(['Vacation start date must be on or before the end date.', false])
		f.form.vacations = []
		f.form.type = ''
		expect(f.isValid).toBe(false)
		f.form.type = 'room'
		f.form.name = '  '
		f.onSave()
		expect([f.errors.name, f.$emit.mock.calls.length]).toEqual(['Name is required', 0])
		f.form.name = 'Ann'
		f.validateField('other')
		expect(f.errors.name).toBe('Name is required')
		f.validateField('name')
		expect(f.errors.name).toBe('')
	})

	it('saves a clean copy with its id', () => {
		const f = form({ id: 'r1', name: 'Ann', workingHours: [{ day: 1, openTime: '09:00', closeTime: '17:00', _key: 'x' }], vacations: [{ startDate: '2026-07-01', endDate: '2026-07-14' }] })
		f.onSkillsInput(' Dutch, ,first aid ')
		expect(f.form.skills).toEqual(['Dutch', 'first aid'])
		f.onSkillsInput(null)
		f.onSkillsInput('Dutch')
		f.onSave()
		const saved = f.$emit.mock.calls[0][1]
		expect([f.$emit.mock.calls[0][0], saved.id, saved.skills, saved.workingHours, saved.vacations]).toEqual(['save', 'r1', ['Dutch'], [{ day: 1, openTime: '09:00', closeTime: '17:00' }], [{ startDate: '2026-07-01', endDate: '2026-07-14', label: '' }]])
		const fresh = form()
		fresh.form.name = 'New'
		fresh.onSave()
		expect('id' in fresh.$emit.mock.calls[0][1]).toBe(false)
	})
})

describe('BookingRowsEditor', () => {
	const editor = (props = {}) => context(BookingRowsEditor, { modelValue: [{ a: 1 }, { a: 2 }, { a: 3 }], columns: [{ key: 'a', width: '80px' }, { key: 'b' }], newRow: () => ({ a: 0 }), numbered: false, reorderable: false, ...props })

	it('lays out a column per field plus the buttons', () => {
		expect(editor().gridColumns).toBe('80px minmax(0, 1fr) calc(var(--default-clickable-area) * 1)')
		expect(editor({ numbered: true, reorderable: true }).gridColumns).toBe('24px 80px minmax(0, 1fr) calc(var(--default-clickable-area) * 3)')
		const first = editor()
		expect(editor().headingId).not.toBe(first.headingId)
		expect(editor({ modelValue: null }).rows).toEqual([])
	})

	it('updates, adds, removes and moves rows without touching the original', () => {
		const e = editor()
		const emitted = () => e.$emit.mock.calls.at(-1)[1]
		e.updateRow(1, 'a', 9)
		expect(emitted()).toEqual([{ a: 1 }, { a: 9 }, { a: 3 }])
		e.addRow()
		expect(emitted()).toEqual([{ a: 1 }, { a: 2 }, { a: 3 }, { a: 0 }])
		e.removeRow(0)
		expect(emitted()).toEqual([{ a: 2 }, { a: 3 }])
		e.moveRow(2, -1)
		expect(emitted()).toEqual([{ a: 1 }, { a: 3 }, { a: 2 }])
		e.moveRow(0, 1)
		expect(emitted()).toEqual([{ a: 2 }, { a: 1 }, { a: 3 }])
		const calls = e.$emit.mock.calls.length
		e.moveRow(0, -1)
		e.moveRow(2, 1)
		expect([e.$emit.mock.calls.length, e.modelValue]).toEqual([calls, [{ a: 1 }, { a: 2 }, { a: 3 }]])
		expect(e.$emit.mock.calls[0][0]).toBe('update:modelValue')
	})

	it('labels the row buttons by number', () => {
		const labels = BookingRowsEditor.props.labels.default()
		expect([labels.moveUp(2), labels.moveDown(3), labels.remove(1)]).toEqual(['Move row 2 up', 'Move row 3 down', 'Remove row 1'])
	})
})

describe('CustomerLookupModal', () => {
	it('searches after a pause once two characters are typed', async () => {
		vi.useFakeTimers()
		searchCustomers.mockResolvedValue([{ id: 'c1' }])
		const m = context(CustomerLookupModal)
		m.query = 'j'
		m.onSearchInput()
		expect([m.hasSearched, m.debounceHandle]).toEqual([false, null])
		m.query = 'ja'
		m.onSearchInput()
		m.query = ' jan '
		m.onSearchInput()
		await vi.advanceTimersByTimeAsync(300)
		expect(searchCustomers.mock.calls).toEqual([['jan', 20]])
		expect([m.results, m.hasSearched, m.loading]).toEqual([[{ id: 'c1' }], true, false])
		m.query = ''
		m.onSearchInput()
		expect([m.results, m.hasSearched]).toEqual([[], false])
	})

	it('says when the search failed and passes the choice on', async () => {
		const m = context(CustomerLookupModal)
		m.query = 'x'
		await m.runSearch()
		expect(searchCustomers).not.toHaveBeenCalled()
		m.query = 'jan'
		searchCustomers.mockRejectedValueOnce(new Error('x'))
		await m.runSearch()
		expect([m.error, m.results, m.hasSearched]).toEqual(['Error searching. Try again later.', [], true])
		m.onSelect({ id: 'c1' })
		m.onCancel()
		expect(m.$emit.mock.calls).toEqual([['select', { id: 'c1' }], ['cancel']])
	})

	it('clears a pending search when it closes', () => {
		vi.useFakeTimers()
		const m = context(CustomerLookupModal)
		m.query = 'jan'
		m.onSearchInput()
		CustomerLookupModal.beforeUnmount.call(m)
		vi.advanceTimersByTime(500)
		expect(searchCustomers).not.toHaveBeenCalled()
	})
})
