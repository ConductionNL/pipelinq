// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Branches of three pages pinned before they were split into named steps:
 * the payment provider list's load and save, the pipeline card's overdue
 * flag, win chance and stage move, and the booking portal's submit and
 * error message.
 */
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
const payments = vi.hoisted(() => ({ listProviders: vi.fn(), testConnection: vi.fn(), updateProvider: vi.fn() }))
const portal = vi.hoisted(() => ({ fetchAvailability: vi.fn(), fetchServiceBySlug: vi.fn(), submitBooking: vi.fn() }))

vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/vue', () => ({
	NcActionButton: {},
	NcActions: {},
	NcButton: {},
	NcCheckboxRadioSwitch: {},
	NcLoadingIcon: {},
	NcSelect: {},
	NcSettingsSection: {},
	NcTextField: {},
}))
vi.mock('../../src/services/posPaymentApi.js', () => payments)
vi.mock('../../src/services/bookingPortalApi.js', () => portal)
vi.mock('../../src/components/leadScore/LeadScoreBadge.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/AssigneePickerDialog.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/PriorityPickerDialog.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/StagePickerDialog.vue', () => ({ default: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => ({}) }))

let paymentForm
let card
let bookingPortal

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match))
	paymentForm = (await import('../../src/views/settings/PaymentSettingsForm.vue')).default
	card = (await import('../../src/views/pipeline/PipelineCard.vue')).default
	bookingPortal = (await import('../../src/views/portal/BookingPortal.vue')).default
}, 60000)

beforeEach(() => {
	Object.values(dialogs).forEach((fn) => fn.mockReset())
	Object.values(payments).forEach((fn) => fn.mockReset())
	portal.submitBooking.mockReset()
})

afterEach(() => {
	vi.useRealTimers()
})

describe('PaymentSettingsForm', () => {
	/**
	 * @param {object} state The form's state.
	 * @return {object} A form context.
	 */
	function formCtx(state) {
		return {
			...paymentForm.methods,
			loading: false,
			providers: [],
			selectedName: '',
			savingProvider: null,
			credentials: [
				{ id: 'c1', name: 'Live', provider: 'adyen' },
				{ id: 'c2', provider: 'adyen-test' },
				{ id: 'c3', name: 'M', provider: 'mollie' },
			],
			...state,
		}
	}

	it('loads the providers with their stored credential and keeps or picks the selection', async () => {
		payments.listProviders.mockResolvedValue([
			{ name: 'mollie', isActive: false, credentialId: 'c3' },
			{ name: 'adyen', isActive: true, credentialId: 'c2', config: { merchantAccount: null } },
			{ name: 'ccv', isActive: true },
		])
		const ctx = formCtx({ selectedName: 'gone' })
		const pending = paymentForm.methods.refresh.call(ctx)
		expect(ctx.loading).toBe(true)
		await pending
		expect(ctx.loading).toBe(false)
		expect(ctx.providers.map((p) => p.credential)).toEqual([{ label: 'M', value: 'c3' }, { label: 'c2', value: 'c2' }, null])
		expect(ctx.providers[1].config).toEqual({ merchantAccount: '' })
		expect(ctx.providers[2].config).toEqual({ terminalId: '' })
		expect(ctx.selectedName).toBe('adyen')

		const kept = formCtx({ selectedName: 'mollie' })
		await paymentForm.methods.refresh.call(kept)
		expect(kept.selectedName).toBe('mollie')

		payments.listProviders.mockResolvedValue([{ name: 'stripe', isActive: false }])
		const firstOne = formCtx({ selectedName: 'x' })
		await paymentForm.methods.refresh.call(firstOne)
		expect(firstOne.selectedName).toBe('stripe')

		payments.listProviders.mockResolvedValue([])
		const none = formCtx({ selectedName: 'x' })
		await paymentForm.methods.refresh.call(none)
		expect(none.selectedName).toBe('')
	})

	it('says why the providers did not load', async () => {
		payments.listProviders.mockRejectedValue(new Error('down'))
		const ctx = formCtx({})
		await paymentForm.methods.refresh.call(ctx)
		expect(dialogs.showError).toHaveBeenCalledWith('Could not load providers: down')
		expect(ctx.loading).toBe(false)
		payments.listProviders.mockRejectedValue({})
		await paymentForm.methods.refresh.call(ctx)
		expect(dialogs.showError).toHaveBeenLastCalledWith('Could not load providers: netwerkfout')
	})

	it('saves a provider, sending a typed webhook secret only', async () => {
		const provider = { name: 'mollie', displayName: 'Mollie', isActive: true, environment: 'live', testMode: false, credentialId: 'c3', webhookSecret: 'typed' }
		payments.updateProvider.mockResolvedValue({ name: 'mollie', displayName: 'Mollie', isActive: true, webhookSecret: '***SET***' })
		const ctx = formCtx({})
		const pending = paymentForm.methods.onSave.call(ctx, provider)
		expect(ctx.savingProvider).toBe('mollie')
		await pending
		expect(payments.updateProvider).toHaveBeenCalledWith('mollie', {
			isActive: true, environment: 'live', testMode: false, config: {}, credentialId: 'c3', webhookSecret: 'typed',
		})
		expect(provider.webhookSecret).toBe('***SET***')
		expect(provider.credential).toBeNull()
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Provider Mollie saved')
		expect(ctx.savingProvider).toBeNull()

		payments.updateProvider.mockResolvedValue(null)
		const masked = { name: 'ccv', displayName: 'CCV', config: { terminalId: 't' }, webhookSecret: '***SET***' }
		await paymentForm.methods.onSave.call(ctx, masked)
		expect(payments.updateProvider).toHaveBeenLastCalledWith('ccv', {
			isActive: undefined, environment: undefined, testMode: undefined, config: { terminalId: 't' }, credentialId: '',
		})
		expect(masked.webhookSecret).toBe('***SET***')
	})

	it('says why a save failed', async () => {
		payments.updateProvider.mockRejectedValue(new Error('No'))
		const ctx = formCtx({})
		await paymentForm.methods.onSave.call(ctx, { name: 'mollie', webhookSecret: '' })
		expect(dialogs.showError).toHaveBeenCalledWith('Save failed: No')
		payments.updateProvider.mockRejectedValue({})
		await paymentForm.methods.onSave.call(ctx, { name: 'mollie' })
		expect(dialogs.showError).toHaveBeenLastCalledWith('Save failed: unknown')
		expect(ctx.savingProvider).toBeNull()
	})
})

describe('PipelineCard', () => {
	const stages = [{ name: 'Open', order: 1 }, { name: 'Closed', order: 9, isClosed: true }, { name: 'Unordered' }]

	it('flags an overdue lead or request', () => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-06-15T12:00:00Z'))
		const overdue = (entityType, item) => card.computed.isOverdue.call({ entityType, item, stages })
		expect(overdue('lead', { expectedCloseDate: '2026-06-01', stage: 'Open' })).toBe(true)
		expect(overdue('lead', { expectedCloseDate: '2026-07-01', stage: 'Open' })).toBe(false)
		expect(overdue('lead', { stage: 'Open' })).toBe(false)
		expect(overdue('lead', { expectedCloseDate: '2026-06-01', status: 'won' })).toBe(false)
		expect(overdue('lead', { expectedCloseDate: '2026-06-01', status: 'lost' })).toBe(false)
		expect(overdue('lead', { expectedCloseDate: '2026-06-01', stage: 'Closed' })).toBe(false)
		expect(overdue('lead', { expectedCloseDate: '2026-06-01', stage: 'Elsewhere' })).toBe(true)
		expect(overdue('request', { occurredAt: '2026-05-01T12:00:00Z' })).toBe(true)
		expect(overdue('request', { occurredAt: '2026-05-16T12:00:00Z' })).toBe(false)
		expect(overdue('request', { occurredAt: '2026-05-15T11:00:00Z' })).toBe(true)
		expect(overdue('request', {})).toBe(false)
		expect(overdue('task', { expectedCloseDate: '2026-01-01' })).toBe(false)
	})

	it('shows the lead\'s own chance, else its stage\'s', () => {
		const chance = (entityType, probability, cardStage) => card.computed.chance.call({ entityType, item: { probability }, cardStage })
		expect(chance('request', 50, null)).toBeNull()
		expect(chance('lead', '40.4', { probability: 80 })).toBe(40)
		expect(chance('lead', 0, { probability: 80 })).toBe(0)
		for (const blank of [null, undefined, '', 'x']) {
			expect(chance('lead', blank, { probability: '79.5' })).toBe(80)
		}
		expect(chance('lead', null, { probability: null })).toBe(0)
		expect(chance('lead', null, { probability: '' })).toBe(0)
		expect(chance('lead', null, {})).toBeNull()
		expect(chance('lead', null, null)).toBeNull()
	})

	/**
	 * @param {object} state The card's state.
	 * @return {object} A card context.
	 */
	function cardCtx(state) {
		return {
			...card.methods,
			currentColumnValue: 'Open',
			columnProperty: 'stage',
			selectedStage: 'Unordered',
			stages,
			resolvedType: { objectType: 'lead' },
			buildSavePayload: () => ({ id: 'l1', title: 'T' }),
			objectStore: { saveObject: vi.fn(async () => ({})) },
			$emit: vi.fn(),
			...state,
		}
	}

	it('moves a card to another stage, with the stage order when it has one', async () => {
		let ctx = cardCtx({})
		await card.methods.onStageChange.call(ctx, 'Closed')
		expect(ctx.objectStore.saveObject).toHaveBeenCalledWith('lead', { id: 'l1', title: 'T', stage: 'Closed', stageOrder: 9 })
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Lead moved to Closed')
		expect(ctx.$emit).toHaveBeenCalledWith('refresh')

		ctx = cardCtx({})
		await card.methods.onStageChange.call(ctx, 'Unordered')
		expect(ctx.objectStore.saveObject).toHaveBeenCalledWith('lead', { id: 'l1', title: 'T', stage: 'Unordered' })

		ctx = cardCtx({ columnProperty: 'status', currentColumnValue: 'new', resolvedType: { objectType: 'ticket' } })
		await card.methods.onStageChange.call(ctx, 'Open')
		expect(ctx.objectStore.saveObject).toHaveBeenCalledWith('ticket', { id: 'l1', title: 'T', status: 'Open' })
	})

	it('does nothing for the same or no stage, and puts the card back on a failure', async () => {
		const ctx = cardCtx({})
		await card.methods.onStageChange.call(ctx, 'Open')
		await card.methods.onStageChange.call(ctx, '')
		expect(ctx.objectStore.saveObject).not.toHaveBeenCalled()

		const failing = cardCtx({ objectStore: { saveObject: vi.fn(async () => { throw new Error('x') }) } })
		await card.methods.onStageChange.call(failing, 'Closed')
		expect(failing.selectedStage).toBe('Open')
		expect(dialogs.showError).toHaveBeenCalledWith('Failed to move lead. Please try again.')
		expect(failing.$emit).not.toHaveBeenCalled()

		const broken = cardCtx({ buildSavePayload: () => { throw new Error('y') } })
		await card.methods.onStageChange.call(broken, 'Closed')
		expect(broken.selectedStage).toBe('Open')
	})
})

describe('BookingPortal', () => {
	const t = (app, text) => text

	it('maps an error to a friendly message', () => {
		const message = (e) => bookingPortal.methods.friendlyError.call({ t }, e)
		expect(message({ response: { status: 409 } })).toBe('That time was just taken. Please choose another slot.')
		expect(message({ status: 409 })).toBe('That time was just taken. Please choose another slot.')
		expect(message({ response: { status: 404 } })).toBe('This service could not be found.')
		expect(message({ status: 404, response: { status: 500 } })).toBe('Something went wrong. Please try again.')
		expect(message({ response: { status: 500, data: { message: 'Closed today' } } })).toBe('Closed today')
		expect(message({ response: { status: 500, data: {} } })).toBe('Something went wrong. Please try again.')
		expect(message(null)).toBe('Something went wrong. Please try again.')
		expect(message(undefined)).toBe('Something went wrong. Please try again.')
	})

	/**
	 * @param {object} state The portal's state.
	 * @return {object} A portal context.
	 */
	function portalCtx(state) {
		return {
			...bookingPortal.methods,
			t,
			submitError: 'old',
			submitting: false,
			service: { id: 's1' },
			selectedSlot: '2026-06-01T09:00:00Z',
			form: { name: 'Anna', email: 'a@b.nl', phone: '', notes: '' },
			validate: () => true,
			$router: { push: vi.fn() },
			...state,
		}
	}

	it('submits a booking and opens its confirmation', async () => {
		portal.submitBooking.mockResolvedValue({ bookingId: 'b 1' })
		const ctx = portalCtx({})
		const pending = bookingPortal.methods.onSubmit.call(ctx)
		expect(ctx.submitError).toBe('')
		expect(ctx.submitting).toBe(true)
		await pending
		expect(portal.submitBooking).toHaveBeenCalledWith({
			serviceId: 's1', startAt: '2026-06-01T09:00:00Z', name: 'Anna', email: 'a@b.nl', phone: null, notes: null,
		})
		expect(ctx.$router.push).toHaveBeenCalledWith('/booking-confirmation/b%201')
		expect(ctx.submitting).toBe(false)

		portal.submitBooking.mockResolvedValue({ id: 'b2', bookingId: 'x', depositRequired: true })
		const withPhone = portalCtx({ form: { name: 'A', email: 'e', phone: '06', notes: 'n' } })
		await bookingPortal.methods.onSubmit.call(withPhone)
		expect(portal.submitBooking).toHaveBeenLastCalledWith(expect.objectContaining({ phone: '06', notes: 'n' }))
		expect(withPhone.$router.push).toHaveBeenCalledWith('/booking-confirmation/b2')
	})

	it('sends a deposit to the payment page, stops on an invalid form, and shows a failure', async () => {
		const assign = vi.fn()
		const original = window.location
		Object.defineProperty(window, 'location', { value: { assign }, configurable: true })
		try {
			portal.submitBooking.mockResolvedValue({ id: 'b3', depositRequired: true, paymentUrl: 'https://pay' })
			const ctx = portalCtx({})
			await bookingPortal.methods.onSubmit.call(ctx)
			expect(assign).toHaveBeenCalledWith('https://pay')
			expect(ctx.$router.push).not.toHaveBeenCalled()
			expect(ctx.submitting).toBe(false)
		} finally {
			Object.defineProperty(window, 'location', { value: original, configurable: true })
		}

		const invalid = portalCtx({ validate: () => false })
		portal.submitBooking.mockClear()
		await bookingPortal.methods.onSubmit.call(invalid)
		expect(portal.submitBooking).not.toHaveBeenCalled()
		expect(invalid.submitting).toBe(false)
		expect(invalid.submitError).toBe('')

		portal.submitBooking.mockRejectedValue({ response: { status: 409 } })
		const taken = portalCtx({})
		await bookingPortal.methods.onSubmit.call(taken)
		expect(taken.submitError).toBe('That time was just taken. Please choose another slot.')
		expect(taken.submitting).toBe(false)
	})
})
