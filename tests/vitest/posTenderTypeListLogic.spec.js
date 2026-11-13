/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The tender type list: how types are read and sorted, shaped into rows, deleted after
 * confirmation, and what each failure says.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), delete: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, vars = {}) => path.replace(/\{(\w+)\}/g, (m, k) => String(vars[k] ?? m)) }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {} }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcLoadingIcon: {} }))
vi.mock('../../src/dialogs/ConfirmDialog.vue', () => ({ default: {} }))
vi.mock('../../src/modals/PosTenderTypeFormDialog.vue', () => ({ default: {} }))

let list

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match))
	list = (await import('../../src/views/pos/PosTenderTypeList.vue')).default
}, 60000)

/**
 * A component-like context over the list's data, methods and computed.
 *
 * @param {object} overrides Data to replace.
 * @return {object} The context.
 */
function context(overrides = {}) {
	const ctx = { ...list.data(), ...overrides }
	for (const [name, fn] of Object.entries(list.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	for (const [name, fn] of Object.entries(list.computed)) {
		Object.defineProperty(ctx, name, { get: () => fn.call(ctx), configurable: true })
	}
	return ctx
}

beforeEach(() => {
	http.get.mockReset()
	http.delete.mockReset()
	Object.values(dialogs).forEach((fn) => fn.mockReset())
})

describe('PosTenderTypeList', () => {
	it('reads the tender types sorted by their sort order, unsorted ones first', async () => {
		http.get.mockResolvedValue({ data: { results: [{ code: 'C', sortOrder: 3 }, { code: 'A' }, { code: 'B', sortOrder: 1 }] } })
		const ctx = context()

		await ctx.refresh()

		expect(http.get).toHaveBeenCalledWith('/apps/pipelinq/api/pos/tender-types')
		expect(ctx.tenderTypes.map((type) => type.code)).toEqual(['A', 'B', 'C'])
		expect(ctx.loading).toBe(false)
		expect(ctx.errorMessage).toBe('')
	})

	it('treats an answer without results as none, and shows a refusal or a fallback', async () => {
		http.get.mockResolvedValueOnce({ data: {} })
		const ctx = context()
		await ctx.refresh()
		expect(ctx.tenderTypes).toEqual([])

		http.get.mockImplementationOnce(() => Promise.reject({ response: { data: { error: 'Not an admin' } } }))
		await ctx.refresh()
		expect(ctx.errorMessage).toBe('Not an admin')

		http.get.mockImplementationOnce(() => Promise.reject(new Error('offline')))
		await ctx.refresh()
		expect(ctx.errorMessage).toBe('Failed to load tender types')
		expect(ctx.loading).toBe(false)
	})

	it('shapes rows with an id, placeholders for missing values and the flags', () => {
		const cash = { '@self': { id: 't-1' }, name: 'Contant', code: 'CASH', glAccount: '1000', allowsChange: true, isActive: true }
		const card = { name: 'Pin', code: 'PIN', requiresReference: true, requiresPin: true }
		const ctx = context({ tenderTypes: [cash, card] })

		expect(ctx.tableRows).toEqual([
			{ id: 't-1', name: 'Contant', code: 'CASH', glAccount: '1000', flags: 'Change', isActive: true, type: cash },
			{ id: 'PIN', name: 'Pin', code: 'PIN', glAccount: '—', flags: 'Ref, PIN', isActive: false, type: card },
		])
		expect(ctx.flagsOf({})).toBe('')
		expect(ctx.columns.map((c) => c.key)).toEqual(['name', 'code', 'glAccount', 'flags', 'isActive'])
		expect(Object.keys(ctx.tableSchema.properties)).toEqual(['name', 'code', 'glAccount', 'flags', 'isActive'])
	})

	it('opens the form empty for a new type and with a copy for an edit', () => {
		const ctx = context()
		ctx.createNew()
		expect(ctx.showForm).toBe(true)
		expect(ctx.editingType).toBeNull()

		const type = { code: 'CASH' }
		ctx.editType(type)
		expect(ctx.editingType).toEqual(type)
		expect(ctx.editingType).not.toBe(type)

		ctx.onFormClose()
		expect(ctx.showForm).toBe(false)
		expect(ctx.editingType).toBeNull()
	})

	it('reads again after a save', async () => {
		http.get.mockResolvedValue({ data: { results: [] } })
		const ctx = context({ showForm: true, editingType: { code: 'X' } })
		await ctx.onFormSaved()
		expect(ctx.showForm).toBe(false)
		expect(ctx.editingType).toBeNull()
		expect(http.get).toHaveBeenCalledTimes(1)
	})

	it('asks before deleting, naming the type', () => {
		const ctx = context()
		expect(ctx.deleteTypeMessage).toBe('')
		ctx.deleteType({ name: 'Contant' })
		expect(ctx.deleteTypeMessage).toBe('Delete tender type "Contant"? Active tenders referencing this type block deletion.')
	})

	it('deletes the confirmed type by id, confirms and reads again', async () => {
		http.delete.mockResolvedValue({})
		http.get.mockResolvedValue({ data: { results: [] } })
		const ctx = context()
		ctx.deleteType({ id: 't-9', name: 'Bon' })

		await ctx.performDeleteType()

		expect(ctx.pendingDeleteType).toBeNull()
		expect(http.delete).toHaveBeenCalledWith('/apps/pipelinq/api/pos/tender-types/t-9')
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Tender type deleted')
		expect(http.get).toHaveBeenCalledTimes(1)
	})

	it('does nothing without a pending type, and reports a refused delete', async () => {
		const ctx = context()
		await ctx.performDeleteType()
		expect(http.delete).not.toHaveBeenCalled()

		ctx.deleteType({ id: 't-9', name: 'Bon' })
		http.delete.mockImplementationOnce(() => Promise.reject({ response: { data: { error: 'Type is in use' } } }))
		await ctx.performDeleteType()
		expect(dialogs.showError).toHaveBeenLastCalledWith('Type is in use')

		ctx.deleteType({ id: 't-9', name: 'Bon' })
		http.delete.mockImplementationOnce(() => Promise.reject(new Error('offline')))
		await ctx.performDeleteType()
		expect(dialogs.showError).toHaveBeenLastCalledWith('Failed to delete tender type')
		expect(dialogs.showSuccess).not.toHaveBeenCalled()
	})
})
