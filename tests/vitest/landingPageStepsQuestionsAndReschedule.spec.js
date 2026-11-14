// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The campaign landing page section (loading, creating and explaining a
 * refusal), the multi-step service editor, the survey question editor and
 * the reschedule dialog, each run against a plain component context.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

const stub = (name) => ({ name, render: () => null })
const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/vue', () => ({ NcButton: stub('NcButton'), NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'), NcLoadingIcon: stub('NcLoadingIcon'), NcNoteCard: stub('NcNoteCard'), NcSelect: stub('NcSelect'), NcTextField: stub('NcTextField') }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: stub('CnDialog'), CnNoteCard: stub('CnNoteCard') }))
vi.mock('../../src/services/campaignsApi.js', () => ({ createCampaignLandingPage: vi.fn() }))
const objectStore = { fetchCollection: vi.fn() }
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objectStore }))

const { default: LandingPage } = await import('../../src/components/marketing/CampaignLandingPageSection.vue')
const { default: ServiceStepsEditor } = await import('../../src/components/bookings/ServiceStepsEditor.vue')
const { default: QuestionEditor } = await import('../../src/components/surveys/QuestionEditor.vue')
const { default: RescheduleBookingDialog } = await import('../../src/dialogs/RescheduleBookingDialog.vue')
const { createCampaignLandingPage } = await import('../../src/services/campaignsApi.js')

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
	http.get.mockReset()
	createCampaignLandingPage.mockReset()
	objectStore.fetchCollection.mockReset()
})

afterEach(() => {
	vi.useRealTimers()
})

describe('CampaignLandingPageSection', () => {
	const section = () => context(LandingPage, { campaignId: 'c 1' })

	it('loads the campaign and shows its page once it has a route', async () => {
		const s = section()
		http.get.mockResolvedValueOnce({ data: { data: { utmCampaign: 'spring', landingPage: { route: '' } } } })
		LandingPage.mounted.call(s)
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/pipelinq/campaign/c 1')
		expect([s.page, s.routePlaceholder, s.loading]).toEqual([null, '/campagne/spring', false])
		http.get.mockResolvedValueOnce({ data: { landingPage: { route: '/campagne/spring' } } })
		await s.fetchCampaign()
		expect(s.page).toEqual({ route: '/campagne/spring' })
		expect(s.reportHref).toBe('/index.php/apps/pipelinq/reports/campaign?campaign=c%201')
	})

	it('says why the campaign could not be loaded', async () => {
		const s = section()
		http.get.mockRejectedValueOnce({ response: { data: { error: 'No access' } } })
		await s.fetchCampaign()
		const t = section()
		http.get.mockRejectedValueOnce({})
		await t.fetchCampaign()
		expect([s.failure, t.failure, t.campaign, t.routePlaceholder]).toEqual(['No access', 'Could not load this campaign.', null, '/campagne/'])
	})

	it('creates the page on the chosen route and reloads the campaign', async () => {
		const s = section()
		s.route = '/campagne/x'
		createCampaignLandingPage.mockResolvedValueOnce({ page: {} })
		http.get.mockResolvedValueOnce({ data: { landingPage: { route: '/campagne/x' } } })
		await s.create()
		expect([createCampaignLandingPage.mock.calls[0], s.page.route, s.creating, s.failure]).toEqual([['c 1', { route: '/campagne/x' }], '/campagne/x', false, ''])
	})

	it('explains each refusal from portaliq with its code', async () => {
		const s = section()
		createCampaignLandingPage.mockResolvedValueOnce({ error: 'duplicate_route' })
		await s.create()
		expect(s.failure).toBe('Another page on this portal already uses that route. Pick a different one. (duplicate_route)')
		expect(http.get).not.toHaveBeenCalled()
		expect([s.explain('not_found'), s.explain('odd')]).toEqual(['This campaign no longer exists. (not_found)', 'Portaliq refused the request. (odd)'])
		for (const code of ['unknown_portal', 'invalid_article', 'invalid_form', 'write_failed', 'portaliq_missing']) {
			expect(s.explain(code)).not.toMatch(/^Portaliq refused the request/)
		}
		createCampaignLandingPage.mockRejectedValueOnce({ response: { data: { error: 'Timeout' } } })
		await s.create()
		createCampaignLandingPage.mockRejectedValueOnce({})
		const t = section()
		await t.create()
		expect([s.failure, t.failure]).toEqual(['Timeout', 'Could not reach portaliq.'])
	})
})

describe('ServiceStepsEditor', () => {
	const editor = (props = {}) => context(ServiceStepsEditor, { modelValue: [], durationMinutes: null, ...props })

	it('loads the product catalogue and passes it on', async () => {
		const e = editor()
		objectStore.fetchCollection.mockResolvedValueOnce([{ id: 'p1', name: 'Haircut' }, { title: 'No id' }, { id: 'p2', title: 'Wash' }, { id: 'p3' }])
		await ServiceStepsEditor.mounted.call(e)
		expect(objectStore.fetchCollection).toHaveBeenCalledWith('product', { _limit: 500 })
		expect(e.productOptions).toEqual([{ value: 'p1', label: 'Haircut' }, { value: 'p2', label: 'Wash' }, { value: 'p3', label: 'p3' }])
		expect([e.$emit.mock.calls[0][0], e.productsLoading]).toEqual(['catalogue', false])
		expect([e.productOption('p2'), e.productOption('gone'), e.productOption('')]).toEqual([{ value: 'p2', label: 'Wash' }, { value: 'gone', label: 'gone' }, null])
		objectStore.fetchCollection.mockResolvedValueOnce('odd')
		await ServiceStepsEditor.mounted.call(e)
		objectStore.fetchCollection.mockRejectedValueOnce(new Error('x'))
		await ServiceStepsEditor.mounted.call(e)
		expect(e.products).toEqual([])
	})

	it('warns when the steps do not add up to the duration', () => {
		const e = editor({ modelValue: [{ durationMinutes: 20 }, { durationMinutes: '25' }, {}], durationMinutes: 45 })
		expect([e.stepTotal, e.totalWarning]).toEqual([45, ''])
		const off = editor({ modelValue: [{ durationMinutes: 20 }] })
		expect(off.totalWarning).toBe('Multi-step total (20 min) does not match Duration (0 min).')
		expect([editor().totalWarning, editor({ modelValue: 'x' }).steps]).toEqual(['', []])
	})

	it('describes the columns, the row buttons and a new step', () => {
		const e = editor()
		expect(e.columns.map((c) => c.key)).toEqual(['productId', 'quantity', 'unit', 'durationMinutes', 'resourceType', 'skillRequired', 'allowGap'])
		expect([e.labels.moveUp(1), e.labels.moveDown(2), e.labels.remove(3)]).toEqual(['Move step 1 up', 'Move step 2 down', 'Remove step 3'])
		expect(e.newStep()).toEqual({ durationMinutes: 0, resourceType: 'staff', skillRequired: '', allowGap: false })
		expect(e.unitOptions.length).toBeGreaterThan(0)
		expect(e.resourceTypeOptions.map((o) => o.value)).toContain('staff')
		expect(editor().uid).not.toBe(e.uid)
	})
})

describe('QuestionEditor', () => {
	const editor = (value) => context(QuestionEditor, { value })
	const last = (e) => e.$emit.mock.calls.at(-1)[1]

	it('adds a required rating question at the end', () => {
		const e = editor([{ id: 'q1' }])
		e.add()
		const added = last(e)[1]
		expect([added.type, added.required, added.order, added.options]).toEqual(['rating', true, 2, []])
		expect(added.id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
		expect(e.$emit.mock.calls[0][0]).toBe('input')
		const empty = editor(null)
		empty.add()
		expect([last(empty).length, last(empty)[0].order]).toEqual([1, 1])
	})

	it('edits, retypes and removes questions without touching the original', () => {
		const value = [{ id: 'q1', type: 'rating', options: [] }, { id: 'q2', type: 'text' }]
		const e = editor(value)
		e.setProp(0, 'text', 'How was it?')
		expect(last(e)[0]).toEqual({ id: 'q1', type: 'rating', options: [], text: 'How was it?' })
		e.setType(0, 'multiple_choice')
		expect(last(e)[0].options).toEqual(['', ''])
		e.setType(1, 'open')
		expect(last(e)[1]).toEqual({ id: 'q2', type: 'open' })
		e.remove(0)
		expect(last(e)).toEqual([{ id: 'q2', type: 'text' }])
		expect(value[0]).toEqual({ id: 'q1', type: 'rating', options: [] })
	})

	it('keeps the options of a choice question that already has two', () => {
		const e = editor([{ type: 'rating', options: ['Yes', 'No', 'Maybe'] }])
		e.setType(0, 'multiple_choice')
		expect(last(e)[0].options).toEqual(['Yes', 'No', 'Maybe'])
	})

	it('sets, adds and removes options', () => {
		const e = editor([{ options: ['a', 'b'] }, {}])
		e.setOpt(0, 1, 'B')
		expect(last(e)[0].options).toEqual(['a', 'B'])
		e.addOpt(0)
		expect(last(e)[0].options).toEqual(['a', 'b', ''])
		e.addOpt(1)
		expect(last(e)[1].options).toEqual([''])
		e.rmOpt(0, 0)
		expect(last(e)[0].options).toEqual(['b'])
		e.setOpt(1, 0, 'x')
		e.rmOpt(1, 0)
		expect(last(e)[1].options).toEqual([])
	})
})

describe('RescheduleBookingDialog', () => {
	const start = new Date(2026, 4, 15, 10, 0).toISOString()
	const end = new Date(2026, 4, 15, 10, 30).toISOString()
	const dialog = (props = {}) => context(RescheduleBookingDialog, { currentStartAt: start, currentEndAt: end, subject: 'Haircut', ...props })

	it('proposes the same time a week later', () => {
		const d = dialog()
		expect(d.newStartAt).toBe('2026-05-22T10:00')
		expect([d.isValid, d.eyebrow]).toEqual([true, 'Booking · Haircut'])
		expect(d.newSlot).toMatch(/, same length$/)
		expect(d.currentSlot).not.toBe('-')
		expect(dialog({ currentStartAt: '', subject: '' }).newStartAt).toBe('')
		expect(dialog({ subject: '' }).eyebrow).toBe('Booking')
	})

	it('needs a start time other than the current one', () => {
		const d = dialog()
		d.newStartAt = '2026-05-15T10:00'
		expect(d.isValid).toBe(false)
		d.newStartAt = ''
		expect([d.isValid, d.newSlot]).toEqual([false, '-'])
		d.newStartAt = 'not a date'
		expect(d.newSlot).toBe('-')
		expect([d.formatForInput(''), d.formatForInput('garbage'), d.weekLater('garbage')]).toEqual(['', '', ''])
	})

	it('confirms the new start as an ISO time, or says what is wrong', () => {
		const d = dialog()
		d.onConfirm()
		expect(d.$emit.mock.calls[0]).toEqual(['confirm', new Date(2026, 4, 22, 10, 0).toISOString()])
		d.newStartAt = ''
		d.onConfirm()
		expect(d.error).toBe('Pick a new start time.')
		d.newStartAt = 'garbage'
		d.onConfirm()
		expect([d.error, d.$emit.mock.calls.length]).toEqual(['Invalid date / time.', 1])
	})

	it('keeps no length for a booking without a sound end', () => {
		const d = dialog({ currentEndAt: start })
		expect(d.newSlot).toMatch(/, same length$/)
	})
})
