/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The till's checkout path: storing and confirming the sale, spending points
 * and gift cards from a split, booking each part as a tender, and issuing the
 * gift cards sold on the sale. Each method runs against a plain context so the
 * requests it makes and what it reports are read directly.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn(), showWarning: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {}, CnDetailCard: {}, CnDetailPage: {}, CnResourceSelect: {} }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcCheckboxRadioSwitch: {}, NcNoteCard: {}, NcSelect: {}, NcTextField: {} }))
for (const icon of ['AccountPlus', 'Barcode', 'CashRegister', 'Check', 'Close', 'PauseCircleOutline', 'Plus']) {
	vi.doMock(`vue-material-design-icons/${icon}.vue`, () => ({ default: {} }))
}
vi.mock('../../src/components/pos/PaymentMethodSelector.vue', () => ({ default: {} }))
vi.mock('../../src/components/pos/PosLineItemRow.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/PosPaymentDialog.vue', () => ({ default: {} }))
vi.mock('../../src/modals/CustomerLookupModal.vue', () => ({ default: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

let form

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match))
	form = (await import('../../src/views/pos/PosTransactionForm.vue')).default
}, 60000)

/**
 * A context with the form's methods bound and the given state.
 *
 * @param {object} state The state.
 * @return {object} The context.
 */
function context(state = {}) {
	const ctx = {
		transaction: { tenderType: 'cash', reference: '' },
		lines: [],
		loyalty: null,
		saving: false,
		showPayment: true,
		$router: { push: vi.fn() },
		...state,
	}
	for (const [name, fn] of Object.entries(form.methods)) {
		if (!(name in ctx)) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	Object.values(dialogs).forEach((fn) => fn.mockReset())
})

describe('PosTransactionForm persistSale', () => {
	it('stores nothing further when the cart could not be stored', async () => {
		const syncCustomerAttachment = vi.fn()
		const ctx = context({ syncCart: vi.fn().mockResolvedValue(null), syncCustomerAttachment })

		expect(await ctx.persistSale(true)).toBeNull()
		expect(ctx.syncCart).toHaveBeenCalledWith({ header: true })
		expect(syncCustomerAttachment).not.toHaveBeenCalled()
		expect(http.post).not.toHaveBeenCalled()
	})

	it('links the customer and confirms the stored sale', async () => {
		const ctx = context({ syncCart: vi.fn().mockResolvedValue('tx/1'), syncCustomerAttachment: vi.fn().mockResolvedValue() })
		http.post.mockResolvedValue({})

		expect(await ctx.persistSale(true)).toBe('tx/1')
		expect(ctx.syncCustomerAttachment).toHaveBeenCalledWith('tx/1')
		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/pos-transactions/tx%2F1/confirm')
	})

	it('leaves a parked sale unconfirmed', async () => {
		const ctx = context({ syncCart: vi.fn().mockResolvedValue('tx1'), syncCustomerAttachment: vi.fn().mockResolvedValue() })

		expect(await ctx.persistSale(false)).toBe('tx1')
		expect(http.post).not.toHaveBeenCalled()
	})

	it('still returns the sale when the confirm is refused, and says so', async () => {
		const ctx = context({ syncCart: vi.fn().mockResolvedValue('tx1'), syncCustomerAttachment: vi.fn().mockResolvedValue() })
		http.post.mockRejectedValue(new Error('409'))

		expect(await ctx.persistSale(true)).toBe('tx1')
		expect(dialogs.showError).toHaveBeenCalledWith('Transaction saved but could not be completed.')
	})
})

describe('PosTransactionForm checkout', () => {
	const split = { points: 0, pointsValue: 0, giftCards: [], rest: 10 }

	it('pays the parts, issues sold gift cards, closes the dialog and opens the sale', async () => {
		const ctx = context({ persistSale: vi.fn().mockResolvedValue('tx1'), payParts: vi.fn().mockResolvedValue(), issueSoldGiftCards: vi.fn().mockResolvedValue() })

		await ctx.checkout(split)

		expect(ctx.persistSale).toHaveBeenCalledWith(true)
		expect(ctx.payParts).toHaveBeenCalledWith('tx1', split)
		expect(ctx.issueSoldGiftCards).toHaveBeenCalledWith('tx1')
		expect(ctx.showPayment).toBe(false)
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Transaction saved.')
		expect(ctx.$router.push).toHaveBeenCalledWith({ name: 'PosTransactionDetail', params: { id: 'tx1' } })
		expect(ctx.saving).toBe(false)
	})

	it('pays nothing and keeps the dialog open when the sale was not stored', async () => {
		const ctx = context({ persistSale: vi.fn().mockResolvedValue(null), payParts: vi.fn() })

		await ctx.checkout(split)

		expect(ctx.payParts).not.toHaveBeenCalled()
		expect(ctx.showPayment).toBe(true)
		expect(ctx.$router.push).not.toHaveBeenCalled()
		expect(ctx.saving).toBe(false)
	})

	it('reports a failed checkout and stays', async () => {
		const ctx = context({ persistSale: vi.fn().mockResolvedValue('tx1'), payParts: vi.fn().mockRejectedValue(new Error('x')) })

		await ctx.checkout(split)

		expect(dialogs.showError).toHaveBeenCalledWith('Failed to save transaction.')
		expect(ctx.showPayment).toBe(true)
		expect(ctx.$router.push).not.toHaveBeenCalled()
		expect(ctx.saving).toBe(false)
	})
})

describe('PosTransactionForm payParts', () => {
	it('spends the points, redeems each gift card and books every part with the rest', async () => {
		const bookTenders = vi.fn().mockResolvedValue()
		const ctx = context({ loyalty: { accountId: 'acc 1' }, transaction: { tenderType: 'card', reference: 'R-9' }, bookTenders })
		http.post.mockResolvedValue({})

		await ctx.payParts('tx1', { points: 200, pointsValue: 10, giftCards: [{ giftCardId: 'g1', pin: '1234', amount: 25, serial: 'GC-1' }], rest: 5 })

		expect(http.post.mock.calls).toEqual([
			['/apps/pipelinq/api/loyalty/accounts/acc%201/pay', { points: 200, posTransactionId: 'tx1' }],
			['/apps/pipelinq/api/loyalty/gift-card/redeem', { giftCardId: 'g1', pin: '1234', amount: 25, posTransactionId: 'tx1' }],
		])
		expect(bookTenders).toHaveBeenCalledWith('tx1', [
			{ kind: 'points', amount: 10, reference: '200 points' },
			{ kind: 'giftCard', amount: 25, reference: 'GC-1' },
			{ kind: 'card', amount: 5, reference: 'R-9' },
		])
	})

	it('spends no points without a loyalty account and books the rest as cash under the sale', async () => {
		const bookTenders = vi.fn().mockResolvedValue()
		const ctx = context({ transaction: {}, bookTenders })

		await ctx.payParts('tx1', { points: 200, pointsValue: 10, giftCards: [], rest: 7 })

		expect(http.post).not.toHaveBeenCalled()
		expect(bookTenders).toHaveBeenCalledWith('tx1', [{ kind: 'cash', amount: 7, reference: 'tx1' }])
	})

	it('drops a part that could not be spent and says which', async () => {
		const bookTenders = vi.fn().mockResolvedValue()
		const ctx = context({ loyalty: { accountId: 'a' }, bookTenders })
		http.post.mockRejectedValue(new Error('x'))

		await ctx.payParts('tx1', { points: 50, pointsValue: 2, giftCards: [{ giftCardId: 'g1', pin: '0', amount: 3, serial: 'GC-2' }], rest: 0 })

		expect(dialogs.showError).toHaveBeenCalledWith('The loyalty points could not be spent.')
		expect(dialogs.showError).toHaveBeenCalledWith('Gift card GC-2 could not be redeemed.')
		expect(bookTenders).toHaveBeenCalledWith('tx1', [])
	})
})

describe('PosTransactionForm bookTenders', () => {
	it('books each part under its active tender type and skips a part without one', async () => {
		http.get.mockResolvedValue({ data: { results: [{ id: 't-cash', code: 'cash' }, { '@self': { id: 't-bon' }, code: 'BON' }, { id: 't-card', code: 'PIN', isActive: false }] } })
		http.post.mockResolvedValue({})
		const ctx = context()

		await ctx.bookTenders('tx 1', [
			{ kind: 'cash', amount: 5, reference: 'r1' },
			{ kind: 'card', amount: 6, reference: 'r2' },
			{ kind: 'giftCard', amount: 7, reference: 'r3' },
		])

		expect(http.get).toHaveBeenCalledWith('/apps/pipelinq/api/pos/tender-types', { params: { activeOnly: '1' } })
		expect(http.post.mock.calls).toEqual([
			['/apps/pipelinq/api/pos-transactions/tx%201/tenders', { tenderType: 't-cash', amount: 5, reference: 'r1' }],
			['/apps/pipelinq/api/pos-transactions/tx%201/tenders', { tenderType: 't-bon', amount: 7, reference: 'r3' }],
		])
	})

	it('books nothing when the tender types cannot be read', async () => {
		http.get.mockRejectedValue(new Error('x'))
		const ctx = context()

		await ctx.bookTenders('tx1', [{ kind: 'cash', amount: 5, reference: 'r1' }])

		expect(http.post).not.toHaveBeenCalled()
		expect(dialogs.showError).not.toHaveBeenCalled()
	})

	it('reports a part that could not be booked and books the next', async () => {
		http.get.mockResolvedValue({ data: { results: [{ id: 't-cash', code: 'CONTANT' }] } })
		http.post.mockRejectedValueOnce(new Error('x')).mockResolvedValueOnce({})
		const ctx = context()

		await ctx.bookTenders('tx1', [{ kind: 'cash', amount: 1, reference: 'a' }, { kind: 'cash', amount: 2, reference: 'b' }])

		expect(dialogs.showError).toHaveBeenCalledTimes(1)
		expect(dialogs.showError).toHaveBeenCalledWith('A payment part could not be booked.')
		expect(http.post).toHaveBeenCalledTimes(2)
	})

	it('treats a response without results as no tender types', async () => {
		http.get.mockResolvedValue({ data: {} })
		const ctx = context()

		await ctx.bookTenders('tx1', [{ kind: 'cash', amount: 1, reference: 'a' }])

		expect(http.post).not.toHaveBeenCalled()
	})
})

describe('PosTransactionForm issueSoldGiftCards', () => {
	it('issues and activates a card for each gift-card line only', async () => {
		http.post.mockImplementation(async (url) => (url.endsWith('/issue') ? { data: { card: { id: 'c 1', serial: 'GC-77' } } } : {}))
		const ctx = context({ lines: [{ _giftCard: true, lineTotal: '25.50' }, { lineTotal: '9.99' }] })

		await ctx.issueSoldGiftCards('tx1')

		expect(http.post.mock.calls).toEqual([
			['/apps/pipelinq/api/loyalty/gift-card/issue', { initialBalance: 25.5, channel: 'purchased', issuedIn: 'tx1' }],
			['/apps/pipelinq/api/loyalty/gift-card/activate/c%201', { posTransactionId: 'tx1' }],
		])
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Gift card GC-77 sold.')
	})

	it('skips the activation when the issued card has no id, and issues a zero balance for a bad total', async () => {
		http.post.mockResolvedValue({ data: {} })
		const ctx = context({ lines: [{ _giftCard: true, lineTotal: 'abc' }] })

		await ctx.issueSoldGiftCards('tx1')

		expect(http.post).toHaveBeenCalledTimes(1)
		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/loyalty/gift-card/issue', { initialBalance: 0, channel: 'purchased', issuedIn: 'tx1' })
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Gift card  sold.')
	})

	it('reports a card that could not be issued and goes on with the next', async () => {
		http.post.mockRejectedValueOnce(new Error('x')).mockResolvedValue({ data: { card: { uuid: 'c2', serial: 'GC-2' } } })
		const ctx = context({ lines: [{ _giftCard: true, lineTotal: 5 }, { _giftCard: true, lineTotal: 6 }] })

		await ctx.issueSoldGiftCards('tx1')

		expect(dialogs.showError).toHaveBeenCalledWith('The gift card could not be issued.')
		expect(http.post).toHaveBeenLastCalledWith('/apps/pipelinq/api/loyalty/gift-card/activate/c2', { posTransactionId: 'tx1' })
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Gift card GC-2 sold.')
	})
})
