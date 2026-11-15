// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lead forecast tab (category changes and the commit justification),
 * the menu structure settings, enrolling a customer in a loyalty programme,
 * the lead close date cell and the ticket answer dialog, each run against a
 * plain component context.
 *
 * @spec openspec/specs/pipeline/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

const stub = (name) => ({ name, render: () => null })
const toasts = []
const emitted = []
const state = vi.hoisted(() => ({ values: {} }))
const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars = {}) => globalThis.t(app, text, vars) }))
vi.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => state.values[key] ?? fallback }))
vi.mock('@nextcloud/event-bus', () => ({ emit: (name, payload) => emitted.push([name, payload]) }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@nextcloud/vue', () => ({ NcButton: stub('NcButton'), NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'), NcNoteCard: stub('NcNoteCard'), NcSelect: stub('NcSelect'), NcSettingsSection: stub('NcSettingsSection') }))
for (const name of ['NcButton', 'NcCheckboxRadioSwitch', 'NcNoteCard', 'NcSelect', 'NcTextField']) {
	vi.doMock(`@nextcloud/vue/components/${name}`, () => ({ default: stub(name) }))
}
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: stub('CnDialog') }))
vi.mock('../../src/modals/CommitJustificationModal.vue', () => ({ default: stub('CommitJustificationModal') }))
vi.mock('../../src/components/CustomerReplySection.vue', () => ({ default: stub('CustomerReplySection') }))
vi.mock('../../src/services/menuStructureSetting.js', () => ({ saveMenuStructure: vi.fn() }))
const objectStore = { fetchObject: vi.fn() }
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objectStore }))

const { default: LeadForecastTab } = await import('../../src/views/leads/LeadForecastTab.vue')
const { default: MenuStructureSettings } = await import('../../src/views/settings/MenuStructureSettings.vue')
const { default: LoyaltyAccountCreation } = await import('../../src/views/loyalty/LoyaltyAccountCreation.vue')
const { default: LeadCloseDateCell } = await import('../../src/views/leads/cells/LeadCloseDateCell.vue')
const { default: TicketAnswerDialog } = await import('../../src/dialogs/TicketAnswerDialog.vue')
const { saveMenuStructure } = await import('../../src/services/menuStructureSetting.js')

/**
 * A component context: props and extras, methods bound, data, and computed
 * values as getters.
 *
 * @param {object} comp The component definition.
 * @param {object} [props] Its props.
 * @param {object} [extra] Anything else on `this`.
 * @return {object} The context.
 */
function context(comp, props = {}, extra = {}) {
	const ctx = { $emit: vi.fn(), t: globalThis.t, ...props, ...extra }
	for (const [name, fn] of Object.entries(comp.methods || {})) {
		ctx[name] = fn.bind(ctx)
	}
	Object.assign(ctx, comp.data ? comp.data.call(ctx) : {})
	for (const [name, def] of Object.entries(comp.computed || {})) {
		Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
	}
	return ctx
}

beforeEach(() => {
	toasts.length = 0
	emitted.length = 0
	state.values = {}
	for (const fn of [http.get, http.post, saveMenuStructure, objectStore.fetchObject]) {
		fn.mockReset()
	}
	globalThis.OC = { requestToken: 'tok', getLocale: () => 'en_GB' }
})

afterEach(() => {
	vi.useRealTimers()
})

describe('LeadForecastTab', () => {
	const tab = (objectData = {}) => context(LeadForecastTab, { objectData })

	it('starts from the lead category and locks a closed lead', () => {
		const open = tab({ forecast_category: 'best_case', forecast_category_history: [{ a: 1 }] })
		expect([open.selected, open.locked, open.history]).toEqual([{ id: 'best_case', label: 'Best-case' }, false, [{ a: 1 }]])
		expect([tab().selected.id, tab({ forecast_category: 'closed_won' }).locked, tab({ forecast_category: 'closed_lost' }).locked]).toEqual(['pipeline', true, true])
		expect([open.categoryLabel('odd'), open.categoryOptions.length]).toEqual(['odd', 6])
	})

	it('saves a change at once unless a large deal is committed without a reason', () => {
		const small = tab({ value: 50000 })
		small.onChange({ id: 'commit' })
		expect(small.$emit.mock.calls[0]).toEqual(['update', { forecast_category: 'commit', commit_justification: '' }])
		const big = tab({ value: '75000' })
		big.onChange(null)
		big.onChange({ id: 'omitted' })
		expect(big.$emit.mock.calls).toEqual([['update', { forecast_category: 'omitted', commit_justification: '' }]])
		big.onChange({ id: 'commit' })
		expect([big.showJustification, big.pending, big.$emit.mock.calls.length]).toEqual([true, 'commit', 1])
		const reasoned = tab({ value: 75000, commit_justification: 'Signed letter of intent' })
		reasoned.onChange({ id: 'commit' })
		expect(reasoned.$emit.mock.calls[0][1].commit_justification).toBe('Signed letter of intent')
	})

	it('commits with the given reason, or puts the category back on cancel', () => {
		const t1 = tab({ value: 75000, forecast_category: 'best_case' })
		t1.onChange({ id: 'commit' })
		t1.confirmJustification('Board approved the budget')
		expect([t1.$emit.mock.calls[0][1], t1.showJustification, t1.pending, t1.justification]).toEqual([{ forecast_category: 'commit', commit_justification: 'Board approved the budget' }, false, null, 'Board approved the budget'])
		t1.onChange({ id: 'commit' })
		expect([t1.showJustification, t1.$emit.mock.calls.length]).toEqual([false, 2])
		const t2 = tab({ value: 75000, forecast_category: 'best_case' })
		t2.onChange({ id: 'commit' })
		t2.selected = { id: 'commit' }
		t2.cancelJustification()
		expect([t2.selected.id, t2.showJustification, t2.pending, t2.$emit.mock.calls.length]).toEqual(['best_case', false, null, 0])
		const t3 = tab()
		t3.confirmJustification('No pending choice')
		expect(t3.$emit.mock.calls[0][1].forecast_category).toBe('commit')
	})
})

describe('MenuStructureSettings', () => {
	it('reads the stored structure and modules, keeping only known modules', () => {
		state.values = { menu_structure: 'full', menu_modules: 'pos, Loyalty, unknown' }
		const s = context(MenuStructureSettings)
		expect([s.structure, s.modules, s.stored]).toEqual(['full', ['pos', 'loyalty'], { structure: 'full', modules: ['pos', 'loyalty'] }])
		expect(s.moduleOptions.map((o) => o.key)).toEqual(['sales', 'marketing', 'pos', 'products', 'contracts', 'loyalty'])
		state.values = {}
		expect([context(MenuStructureSettings).structure, context(MenuStructureSettings).modules]).toEqual(['simple', []])
	})

	it('saves the menu and keeps what was stored', async () => {
		const s = context(MenuStructureSettings)
		s.structure = 'full'
		s.modules = ['loyalty', 'odd', 'sales']
		saveMenuStructure.mockResolvedValueOnce({ structure: 'full', modules: ['sales', 'loyalty'] })
		await s.save()
		expect(saveMenuStructure).toHaveBeenCalledWith({ structure: 'full', modules: ['sales', 'loyalty'] }, { url: '/index.php/apps/pipelinq/api/settings', requestToken: 'tok' })
		expect([s.stored, s.modules, s.saved, s.saving, s.error]).toEqual([{ structure: 'full', modules: ['sales', 'loyalty'] }, ['sales', 'loyalty'], true, false, null])
	})

	it('puts the stored menu back when saving fails', async () => {
		state.values = { menu_modules: 'pos' }
		const s = context(MenuStructureSettings)
		s.structure = 'full'
		s.modules = []
		saveMenuStructure.mockRejectedValueOnce(new Error('x'))
		await s.save()
		expect([s.structure, s.modules, s.saved, s.error]).toEqual(['simple', ['pos'], false, 'The menu could not be saved. Try again.'])
	})
})

describe('LoyaltyAccountCreation', () => {
	it('loads the programmes under whichever id they carry', async () => {
		const c = context(LoyaltyAccountCreation)
		http.get.mockResolvedValueOnce({ data: { results: [{ '@self': { id: 'p1' }, name: 'Stamps', termsUrl: '/t' }, { programmeId: 'p2' }] } })
		LoyaltyAccountCreation.mounted.call(c)
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/pipelinq/loyaltyProgramme?_limit=200')
		expect(c.programmeOptions).toEqual([{ id: 'p1', label: 'Stamps', termsUrl: '/t' }, { id: 'p2', label: 'p2', termsUrl: undefined }])
		http.get.mockResolvedValueOnce({ data: [{ id: 'p3' }] })
		await c.loadProgrammes()
		expect(c.programmes.map((p) => p.id)).toEqual(['p3'])
		http.get.mockRejectedValueOnce(new Error('x'))
		await c.loadProgrammes()
		expect(toasts).toEqual([['error', 'Failed to load programmes']])
	})

	it('enrols only with a customer, a programme and the opt-in', async () => {
		vi.useFakeTimers({ now: new Date('2026-05-15T12:00:00Z'), toFake: ['Date'] })
		const c = context(LoyaltyAccountCreation)
		await c.enroll()
		c.customerId = 'cust1'
		c.selectedProgramme = { id: 'p1', termsUrl: '/terms' }
		expect([c.canSubmit, c.termsUrl]).toEqual([false, '/terms'])
		await c.enroll()
		expect(http.post).not.toHaveBeenCalled()
		c.optInAccepted = true
		http.post.mockResolvedValueOnce({ data: { '@self': { id: 'acc1' } } })
		await c.enroll()
		expect(http.post.mock.calls[0]).toEqual(['/index.php/apps/openregister/api/objects/pipelinq/customerLoyaltyAccount', {
			customerId: 'cust1', programmeId: 'p1', currentBalance: 0, lifetimePoints: 0, status: 'active', optInAccepted: true,
			optInTimestamp: '2026-05-15T12:00:00.000Z', optInTermsVersion: '1.0', createdOn: '2026-05-15T12:00:00.000Z', lastActivityDate: '2026-05-15T12:00:00.000Z',
		}])
		expect(c.resultId).toBe('acc1')
		c.result = { accountId: 'acc2' }
		expect(c.resultId).toBe('acc2')
		c.result = {}
		expect(c.resultId).toBe('')
		http.post.mockRejectedValueOnce(new Error('x'))
		await c.enroll()
		expect(toasts).toEqual([['error', 'Failed to create loyalty account']])
	})
})

describe('LeadCloseDateCell', () => {
	const cell = (value, row = {}) => context(LeadCloseDateCell, { value, row })

	beforeEach(() => {
		vi.useFakeTimers({ now: new Date(2026, 4, 15, 12), toFake: ['Date'] })
	})

	it('calls an open lead past its date overdue, and a closed one fine', () => {
		const late = cell(new Date(2026, 4, 14, 9))
		expect([late.state, late.srLabel, late.cellClass, late.formattedDate]).toEqual(['overdue', 'Overdue', { 'lead-close-cell--overdue': true, 'lead-close-cell--soon': false }, '14 May, late'])
		expect(cell(new Date(2026, 4, 14), { status: 'won' }).state).toBe('ok')
		expect(cell(new Date(2026, 4, 14), { status: 'open' }).state).toBe('overdue')
	})

	it('calls a date within a week soon, and later ones fine', () => {
		const soon = cell(new Date(2026, 4, 22, 23))
		expect([soon.state, soon.srLabel, soon.cellClass['lead-close-cell--soon'], soon.formattedDate]).toEqual(['soon', 'Closes soon', true, '22 May'])
		expect([cell(new Date(2026, 4, 15)).state, cell(new Date(2026, 4, 23)).state, cell(new Date(2026, 4, 23)).srLabel]).toEqual(['soon', 'ok', ''])
	})

	it('shows a dash for no date or one it cannot read', () => {
		for (const value of [null, undefined, '', 'garbage']) {
			const c = cell(value)
			expect([c.dateObj, c.state, c.formattedDate]).toEqual([null, 'unknown', '-'])
		}
		delete globalThis.OC
		expect(cell('2026-06-30').formattedDate).toMatch(/30/)
	})
})

describe('TicketAnswerDialog', () => {
	const dialog = (id) => context(TicketAnswerDialog, {}, { $route: { params: { id } }, $refs: {} })

	it('loads the ticket and its client for the header', async () => {
		objectStore.fetchObject.mockImplementation(async (type) => (type === 'ticket' ? { reference: 'T-7', title: 'Broken bin', client: 'c1' } : { name: 'Acme' }))
		const d = dialog('t1')
		expect([d.eyebrow, d.subtitle]).toEqual(['Answer', ''])
		await TicketAnswerDialog.created.call(d)
		expect(objectStore.fetchObject.mock.calls).toEqual([['ticket', 't1'], ['client', 'c1']])
		expect([d.eyebrow, d.subtitle]).toEqual(['Answer · T-7', 'Acme · T-7 · Broken bin'])
	})

	it('loads nothing without an id, and forgets a ticket it cannot read', async () => {
		await dialog(undefined).loadTicket()
		expect(objectStore.fetchObject).not.toHaveBeenCalled()
		objectStore.fetchObject.mockResolvedValueOnce({ title: 'No client' })
		const d = dialog('t1')
		await d.loadTicket()
		expect([objectStore.fetchObject.mock.calls.length, d.clientName, d.subtitle]).toEqual([1, '', 'No client'])
		objectStore.fetchObject.mockRejectedValueOnce(new Error('x'))
		await d.loadTicket()
		expect(d.ticket).toBeNull()
	})

	it('sends through the reply section and closes after a save', () => {
		const d = dialog('t1')
		const save = vi.fn()
		d.$refs.reply = { save }
		d.replyState.waitForCustomer = false
		d.send()
		expect(save).toHaveBeenCalledWith(false)
		d.$refs = {}
		d.send()
		d.onSaved()
		expect([toasts, emitted, d.$emit.mock.calls]).toEqual([[['success', 'The answer is saved. The customer can read it on the portal.']], [['cn:page:refresh', {}]], [['close']]])
	})
})
