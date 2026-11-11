// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Saving a relationship on a contact or client: the duplicate check, the
 * pair of objects a new relationship writes (it and its inverse), the pair
 * an edit writes, and the names of the linked entities on both relationship
 * cards.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-15
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcEmptyContent: {}, NcLoadingIcon: {}, NcSelect: {} }))
vi.mock('@conduction/nextcloud-vue', () => ({ openRowTarget: vi.fn() }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/dialogs/RemoveRelationshipDialog.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/AddContactRoleDialog.vue', () => ({ default: {} }))

let relationships
let contactRoles

beforeAll(async () => {
	globalThis.t = (app, text) => text
	relationships = (await import('../../src/components/ContactRelationships.vue')).default
	contactRoles = (await import('../../src/components/LeadContactRoles.vue')).default
}, 60000)

beforeEach(() => {
	dialogs.showError.mockReset()
	dialogs.showSuccess.mockReset()
})

/**
 * A component context: every method bound to it, plus the given state.
 *
 * @param {object} component The component options.
 * @param {object} state The state.
 * @return {object} The context.
 */
function bind(component, state) {
	const ctx = { ...state }
	for (const [name, fn] of Object.entries(component.methods)) {
		if (!(name in ctx)) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

function card(state = {}) {
	return bind(relationships, {
		entityId: 'me',
		entityType: 'client',
		relationships: [{ toContact: 'c1', type: 'partner' }],
		entityOptions: [{ id: 'c2', entityType: 'client' }],
		editingRelationship: null,
		addForm: { toContact: 'c2', type: 'ouder', notes: 'n', startDate: '', endDate: '2026-01-01', strength: 'strong' },
		objectStore: { saveObject: vi.fn(async () => ({})) },
		closeDialog: vi.fn(),
		fetchRelationships: vi.fn(),
		...state,
	})
}

describe('saveRelationship', () => {
	it('does nothing without a contact or a type', async () => {
		for (const addForm of [{ toContact: null, type: 'ouder' }, { toContact: 'c2', type: null }]) {
			const ctx = card({ addForm })
			await ctx.saveRelationship()
			expect(ctx.objectStore.saveObject).not.toHaveBeenCalled()
			expect(dialogs.showError).not.toHaveBeenCalled()
		}
	})

	it('refuses a new relationship that already exists', async () => {
		const ctx = card({ addForm: { toContact: 'c1', type: 'partner' } })
		await ctx.saveRelationship()
		expect(dialogs.showError).toHaveBeenCalledWith('This relationship already exists')
		expect(ctx.objectStore.saveObject).not.toHaveBeenCalled()
	})

	it('writes a new relationship and its inverse, with the inverse type and category', async () => {
		const ctx = card()
		await ctx.saveRelationship()
		expect(ctx.objectStore.saveObject.mock.calls).toEqual([
			['relationship', { fromContact: 'me', toContact: 'c2', fromType: 'client', toType: 'client', type: 'ouder', inverseType: 'kind', category: 'Familie', notes: 'n', startDate: null, endDate: '2026-01-01', strength: 'strong' }],
			['relationship', { fromContact: 'c2', toContact: 'me', fromType: 'client', toType: 'client', type: 'kind', inverseType: 'ouder', category: 'Familie', notes: 'n', startDate: null, endDate: '2026-01-01', strength: 'strong' }],
		])
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Relationship saved')
		expect(ctx.closeDialog).toHaveBeenCalled()
		expect(ctx.fetchRelationships).toHaveBeenCalled()
	})

	it('reads an unknown type as its own inverse without a category, and an unlisted entity as a contact', async () => {
		const ctx = card({ entityOptions: [], addForm: { toContact: 'c9', type: 'buur', notes: '', startDate: '2025-01-01', endDate: '', strength: 'weak' } })
		await ctx.saveRelationship()
		expect(ctx.objectStore.saveObject.mock.calls).toEqual([
			['relationship', { fromContact: 'me', toContact: 'c9', fromType: 'client', toType: 'contact', type: 'buur', inverseType: 'buur', category: '', notes: '', startDate: '2025-01-01', endDate: null, strength: 'weak' }],
			['relationship', { fromContact: 'c9', toContact: 'me', fromType: 'contact', toType: 'client', type: 'buur', inverseType: 'buur', category: '', notes: '', startDate: '2025-01-01', endDate: null, strength: 'weak' }],
		])
	})

	it('updates an edited relationship, and its inverse when it has one, even when it matches an existing one', async () => {
		const addForm = { toContact: 'c1', type: 'partner', notes: 'x', startDate: '', endDate: '', strength: 'medium' }
		const ctx = card({ addForm, editingRelationship: { id: 'r1', _inverseId: 'r2' } })
		await ctx.saveRelationship()
		expect(dialogs.showError).not.toHaveBeenCalled()
		expect(ctx.objectStore.saveObject.mock.calls).toEqual([
			['relationship', { id: 'r1', type: 'partner', inverseType: 'partner', category: 'Familie', notes: 'x', startDate: null, endDate: null, strength: 'medium' }],
			['relationship', { id: 'r2', type: 'partner', inverseType: 'partner', category: 'Familie', notes: 'x', startDate: null, endDate: null, strength: 'medium' }],
		])
		const alone = card({ addForm: { ...addForm, type: 'werkgever' }, editingRelationship: { id: 'r1' } })
		await alone.saveRelationship()
		expect(alone.objectStore.saveObject.mock.calls).toEqual([
			['relationship', { id: 'r1', type: 'werkgever', inverseType: 'werknemer', category: 'Professioneel', notes: 'x', startDate: null, endDate: null, strength: 'medium' }],
		])
	})

	it('says why a save failed, and stops at the first failure', async () => {
		const ctx = card({ objectStore: { saveObject: vi.fn().mockRejectedValueOnce(new Error('Nope')) } })
		await ctx.saveRelationship()
		expect(ctx.objectStore.saveObject).toHaveBeenCalledTimes(1)
		expect(dialogs.showError).toHaveBeenCalledWith('Nope')
		expect(ctx.closeDialog).not.toHaveBeenCalled()
		const bare = card({ objectStore: { saveObject: vi.fn().mockRejectedValue({}) } })
		await bare.saveRelationship()
		expect(dialogs.showError).toHaveBeenLastCalledWith('Failed to save relationship')
	})
})

describe('the linked entity names', () => {
	it('reads a client or a contact by its type, marks a missing one deleted and keeps the id on an error', async () => {
		const fetchObject = vi.fn(async (type, id) => ({ a: { name: 'Anna' }, b: {}, c: null }[id]))
		const ctx = bind(relationships, { entityNameCache: {}, objectStore: { fetchObject } })
		await ctx.loadEntityName('a', 'client')
		await ctx.loadEntityName('b', 'organisation')
		await ctx.loadEntityName('c')
		fetchObject.mockRejectedValueOnce(new Error('x'))
		await ctx.loadEntityName('d', 'contact')
		expect(fetchObject.mock.calls.map((c) => c[0])).toEqual(['client', 'contact', 'contact', 'contact'])
		expect(ctx.entityNameCache).toEqual({ a: 'Anna', b: 'b', c: '[Deleted]', d: 'd' })
		expect([ctx.getEntityName('a'), ctx.getEntityName('z'), ctx.getEntityName('')]).toEqual(['Anna', 'z', '-'])
	})

	it('reads contacts only on the lead contact roles', async () => {
		const fetchObject = vi.fn(async (type, id) => ({ a: { name: 'Anna' }, c: null }[id]))
		const ctx = bind(contactRoles, { entityNameCache: {}, objectStore: { fetchObject } })
		await ctx.loadEntityName('a')
		await ctx.loadEntityName('c')
		fetchObject.mockRejectedValueOnce(new Error('x'))
		await ctx.loadEntityName('d')
		expect(fetchObject.mock.calls).toEqual([['contact', 'a'], ['contact', 'c'], ['contact', 'd']])
		expect(ctx.entityNameCache).toEqual({ a: 'Anna', c: '[Deleted]', d: 'd' })
		expect([ctx.getEntityName('a'), ctx.getEntityName(undefined)]).toEqual(['Anna', '-'])
	})
})
