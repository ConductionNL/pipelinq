/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The till's lookups and what they fall back to when one fails: the contact and
 * the client as customer with its history, the open shift, the loyalty account,
 * and linking the customer. Each runs against a plain context so the requests
 * and the state left are read directly.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn(), showWarning: vi.fn() }))
const customers = vi.hoisted(() => ({ attachCustomer: vi.fn(), detachCustomer: vi.fn(), getCustomerHistory: vi.fn() }))

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
vi.mock('../../src/services/posCustomerApi.js', () => customers)
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
		transaction: { client: null, customer: null, marketingConsent: true, tenderType: 'cash', priceMode: 'excl' },
		lines: [],
		history: [],
		...state,
	}
	for (const [name, fn] of Object.entries(form.methods)) {
		if (!(name in ctx)) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

/**
 * An object store whose collections answer from a map, and which fails for the
 * names given.
 *
 * @param {object} collections Rows per collection name.
 * @param {object} objects Objects per `type/id`.
 * @param {Array<string>} failing Collection or object types that throw.
 * @return {object} The store.
 */
function store(collections = {}, objects = {}, failing = []) {
	return {
		fetchCollection: vi.fn(async (name) => {
			if (failing.includes(name)) {
				throw new Error('offline')
			}
		}),
		getCollection: vi.fn((name) => ({ results: collections[name] || [] })),
		fetchObject: vi.fn(async (type, id) => {
			if (failing.includes(type)) {
				throw new Error('offline')
			}
			return objects[`${type}/${id}`] || null
		}),
	}
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	Object.values(dialogs).forEach((fn) => fn.mockReset())
	Object.values(customers).forEach((fn) => fn.mockReset())
})

describe('PosTransactionForm customer lookups', () => {
	it('reads a contact name, and falls back to empty', async () => {
		const ctx = context({ objectStore: store({}, { 'contact/c-1': { name: 'Anja' } }) })
		expect(await ctx.contactName('c-1')).toBe('Anja')
		expect(await ctx.contactName('c-2')).toBe('')

		const failing = context({ objectStore: store({}, {}, ['contact']) })
		expect(await failing.contactName('c-1')).toBe('')
	})

	it('shows an unreadable client by its id with no history', async () => {
		const loadLoyalty = vi.fn().mockResolvedValue(null)
		const ctx = context({ objectStore: store({}, {}, ['client', 'posTransaction']), loadLoyalty, history: [{ id: 'old' }] })

		await ctx.loadClientAsCustomer('cl-1')

		expect(ctx.selectedCustomer).toEqual({ id: 'cl-1', name: 'cl-1', email: '', doNotContact: false, isClient: true })
		expect(ctx.history).toEqual([])
		expect(loadLoyalty).toHaveBeenCalledWith('cl-1')
		expect(ctx.loyalty).toBeNull()
	})

	it("lists the client's other finished sales, newest first, with their line counts", async () => {
		const ctx = context({
			transactionId: 'tx-now',
			loadLoyalty: vi.fn().mockResolvedValue(null),
			objectStore: store(
				{
					posTransaction: [
						{ id: 'tx-a', client: 'cl-1', status: 'confirmed', confirmedAt: '2026-01-01', reference: 'A', total: 10, tenderType: 'cash' },
						{ id: 'tx-b', client: 'cl-1', status: 'settled', settledAt: '2026-03-01', reference: 'B', total: 20, tenderType: 'card' },
						{ id: 'tx-now', client: 'cl-1', status: 'confirmed', confirmedAt: '2026-04-01' },
						{ id: 'tx-c', client: 'cl-1', status: 'parked' },
						{ id: 'tx-d', client: 'cl-2', status: 'confirmed' },
					],
					posTransactionLine: [{ transaction: 'tx-a' }, { transaction: 'tx-a' }, { transaction: 'tx-b' }],
				},
				{ 'client/cl-1': { name: 'Bakkerij Jansen', email: 'info@jansen.example' } },
			),
		})

		await ctx.loadClientAsCustomer('cl-1')

		expect(ctx.selectedCustomer).toEqual({ id: 'cl-1', name: 'Bakkerij Jansen', email: 'info@jansen.example', doNotContact: false, isClient: true })
		expect(ctx.history).toEqual([
			{ id: 'tx-b', createdAt: '2026-03-01', reference: 'B', total: 20, tenderType: 'card', itemCount: 1 },
			{ id: 'tx-a', createdAt: '2026-01-01', reference: 'A', total: 10, tenderType: 'cash', itemCount: 2 },
		])
	})

	it('counts zero lines for a sale whose lines cannot be read', async () => {
		const ctx = context({ history: [{ id: 'tx-a' }], objectStore: store({}, {}, ['posTransactionLine']) })

		await ctx.countHistoryLines()

		expect(ctx.history).toEqual([{ id: 'tx-a', itemCount: 0 }])
	})
})

describe('PosTransactionForm loadShift', () => {
	it('asks nothing for a sale without a register', async () => {
		const objectStore = store()
		const ctx = context({ objectStore, shiftSince: 'kept' })

		await ctx.loadShift()

		expect(objectStore.fetchCollection).not.toHaveBeenCalled()
		expect(ctx.shiftSince).toBe('kept')
	})

	it('shows nothing without an open shift at this register, or when shifts cannot be read', async () => {
		const ctx = context({
			transaction: { terminalId: 'drawer-1' },
			shiftSince: 'old',
			objectStore: store({ cashShift: [{ status: 'open', drawer: 'drawer-2', floatAt: '2026-11-13T08:00:00Z' }, { status: 'closed', drawer: 'drawer-1', floatAt: '2026-11-13T07:00:00Z' }] }),
		})
		await ctx.loadShift()
		expect(ctx.shiftSince).toBe('')

		const failing = context({ transaction: { terminalId: 'drawer-1' }, shiftSince: 'old', objectStore: store({}, {}, ['cashShift']) })
		await failing.loadShift()
		expect(failing.shiftSince).toBe('')
	})

	it('shows when the open shift at this register started', async () => {
		const ctx = context({
			transaction: { terminalId: 'drawer-1' },
			objectStore: store({ cashShift: [{ status: 'open', drawer: 'drawer-1', floatAt: '2026-11-13T08:00:00Z' }] }),
		})

		await ctx.loadShift()

		expect(ctx.shiftSince).not.toBe('')
		expect(ctx.shiftSince).toMatch(/\d/)
	})
})

describe('PosTransactionForm loadLoyalty', () => {
	it('answers the account with what a point is worth', async () => {
		http.get.mockImplementation(async (url) => {
			if (url.endsWith('/customerLoyaltyAccount')) {
				return { data: { results: [{ id: 'la-1', programmeId: 'prog 1', currentBalance: '120', status: 'active' }] } }
			}
			return { data: { pointValue: '0.05' } }
		})

		expect(await context().loadLoyalty('c-1')).toEqual({ accountId: 'la-1', balance: 120, pointValue: 0.05 })
		expect(http.get).toHaveBeenCalledWith('/apps/openregister/api/objects/pipelinq/customerLoyaltyAccount', { params: { customerId: 'c-1', _limit: 1 } })
		expect(http.get).toHaveBeenCalledWith('/apps/openregister/api/objects/pipelinq/loyaltyProgramme/prog%201')
	})

	it('answers null without an account, for a disabled one, a worthless point, or a failed read', async () => {
		http.get.mockResolvedValueOnce({ data: { results: [] } })
		expect(await context().loadLoyalty('c-1')).toBeNull()

		http.get.mockResolvedValueOnce({ data: { results: [{ id: 'la-1', status: 'disabled' }] } })
		expect(await context().loadLoyalty('c-1')).toBeNull()

		http.get.mockResolvedValueOnce({ data: { results: [{ id: 'la-1', programmeId: 'p' }] } }).mockResolvedValueOnce({ data: { pointValue: 0 } })
		expect(await context().loadLoyalty('c-1')).toBeNull()

		http.get.mockRejectedValueOnce(new Error('offline'))
		expect(await context().loadLoyalty('c-1')).toBeNull()
	})
})

describe('PosTransactionForm syncCustomerAttachment', () => {
	it('links a chosen contact with the consent given, and not a client or nobody', async () => {
		customers.attachCustomer.mockResolvedValue({})

		await context({ selectedCustomer: { id: 'c-1' }, transaction: { marketingConsent: 0 } }).syncCustomerAttachment('tx-1')
		await context({ selectedCustomer: { id: 'cl-1', isClient: true } }).syncCustomerAttachment('tx-1')
		await context({ selectedCustomer: null }).syncCustomerAttachment('tx-1')
		await context({ selectedCustomer: { id: 'c-1' } }).syncCustomerAttachment('')

		expect(customers.attachCustomer.mock.calls).toEqual([['tx-1', 'c-1', false]])
	})

	it('reports a link that fails', async () => {
		customers.attachCustomer.mockRejectedValue(new Error('refused'))

		await context({ selectedCustomer: { id: 'c-1' } }).syncCustomerAttachment('tx-1')

		expect(dialogs.showError).toHaveBeenCalledWith('Could not link customer to the transaction.')
	})
})
