/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The tag manager refuses a name that is already taken when a tag is added
 * or renamed, and the till role and till staff sections open, empty and
 * close their form dialog the same way.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-92
 * @spec openspec/changes/nav-ia-cleanup/specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page
 */
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnSettingsSection: {} }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcLoadingIcon: {}, NcNoteCard: {} }))
vi.mock('../../src/dialogs/PosRoleFormDialog.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/PosStaffFormDialog.vue', () => ({ default: {} }))
vi.mock('../../src/views/pos/PosRoleList.vue', () => ({ default: {} }))
vi.mock('../../src/views/pos/PosStaffList.vue', () => ({ default: {} }))

let tagManager
let managers

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match))
	tagManager = (await import('../../src/views/settings/TagManager.vue')).default
	managers = [
		(await import('../../src/views/settings/PosRoleManager.vue')).default,
		(await import('../../src/views/settings/PosStaffManager.vue')).default,
	]
}, 60000)

/**
 * @param {object} state The manager's state.
 * @return {object} A tag manager context.
 */
function tagCtx(state) {
	return {
		...tagManager.methods,
		tags: [{ id: 1, name: 'Beurs' }, { id: 2, name: 'Web' }],
		error: 'old',
		adding: true,
		newName: '',
		editingId: null,
		editName: '',
		$attrs: { onAdd: vi.fn(), onRename: vi.fn() },
		...state,
	}
}

describe('TagManager.saveNew', () => {
	it('adds a free name, trimmed', async () => {
		const ctx = tagCtx({ newName: ' Fair ' })
		await tagManager.methods.saveNew.call(ctx)
		expect(ctx.$attrs.onAdd).toHaveBeenCalledWith('Fair')
		expect(ctx.error).toBeNull()
		expect(ctx.adding).toBe(false)
		expect(ctx.newName).toBe('')
	})

	it('refuses a taken name in any case, and an empty one silently', async () => {
		const ctx = tagCtx({ newName: 'beurs' })
		await tagManager.methods.saveNew.call(ctx)
		expect(ctx.error).toBe('An item with the name "beurs" already exists.')
		expect(ctx.$attrs.onAdd).not.toHaveBeenCalled()
		expect(ctx.adding).toBe(true)
		const empty = tagCtx({ newName: '  ' })
		await tagManager.methods.saveNew.call(empty)
		expect(empty.error).toBe('old')
	})

	it('keeps the form open with the failure', async () => {
		const ctx = tagCtx({ newName: 'Fair', $attrs: { onAdd: vi.fn(async () => { throw new Error('No') }) } })
		await tagManager.methods.saveNew.call(ctx)
		expect(ctx.error).toBe('No')
		expect(ctx.adding).toBe(true)
		expect(ctx.newName).toBe('Fair')
	})
})

describe('TagManager.saveRename', () => {
	it('renames to a free name, or to its own name in another case', async () => {
		const ctx = tagCtx({ editingId: 1, editName: ' BEURS ' })
		await tagManager.methods.saveRename.call(ctx, 1)
		expect(ctx.$attrs.onRename).toHaveBeenCalledWith(1, 'BEURS')
		expect(ctx.error).toBeNull()
		expect(ctx.editingId).toBeNull()
		expect(ctx.editName).toBe('')
	})

	it('refuses the name of another tag, and an empty one silently', async () => {
		const ctx = tagCtx({ editingId: 1, editName: 'web' })
		await tagManager.methods.saveRename.call(ctx, 1)
		expect(ctx.error).toBe('An item with the name "web" already exists.')
		expect(ctx.$attrs.onRename).not.toHaveBeenCalled()
		expect(ctx.editingId).toBe(1)
		const empty = tagCtx({ editingId: 1, editName: '' })
		await tagManager.methods.saveRename.call(empty, 1)
		expect(empty.error).toBe('old')
	})

	it('keeps editing with the failure', async () => {
		const ctx = tagCtx({ editingId: 2, editName: 'Site', $attrs: { onRename: vi.fn(async () => { throw new Error('Gone') }) } })
		await tagManager.methods.saveRename.call(ctx, 2)
		expect(ctx.error).toBe('Gone')
		expect(ctx.editingId).toBe(2)
	})
})

/**
 * @param {object} component A component, with the mixins it uses.
 * @return {object} Its state and methods, as one context.
 */
function componentCtx(component) {
	const parts = [...(component.mixins || []), component]
	const ctx = {}
	for (const part of parts) {
		Object.assign(ctx, part.methods || {}, part.data ? part.data.call({}) : {})
	}
	return ctx
}

describe('the till role and staff sections', () => {
	it('start closed, open a record or an empty form, and reload the list on close', () => {
		for (const manager of managers) {
			const ctx = componentCtx(manager)
			expect(ctx).toMatchObject({ dialogOpen: false, editingId: '', reloadKey: 0 })
			const openOne = Object.keys(ctx).find((name) => typeof ctx[name] === 'function' && !['openNew', 'closeDialog'].includes(name))
			ctx[openOne]('u1')
			expect(ctx).toMatchObject({ dialogOpen: true, editingId: 'u1' })
			ctx.closeDialog()
			expect(ctx).toMatchObject({ dialogOpen: false, editingId: '', reloadKey: 1 })
			ctx.openNew()
			expect(ctx).toMatchObject({ dialogOpen: true, editingId: '' })
		}
	})
})
