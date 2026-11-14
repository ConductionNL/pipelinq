// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bookable service detail page's own logic, run against a plain component
 * context: labels, durations, product names of the steps, editing the steps,
 * the edit form, saving a new service and deleting.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

const stub = (name) => ({ default: { name, render: () => null } })
const toasts = []
vi.mock('@conduction/nextcloud-vue', async (orig) => ({ ...(await orig()), useObjectSubscription: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('../../src/components/bookings/ServiceStepsEditor.vue', () => stub('A'))
vi.mock('../../src/dialogs/DeleteServiceDialog.vue', () => stub('B'))
vi.mock('../../src/views/bookings/ServiceForm.vue', () => stub('C'))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

const { default: ServiceDetail } = await import('../../src/views/bookings/ServiceDetail.vue')

/**
 * A service page context over a store double.
 *
 * @param {object} props The props.
 * @param {object} [service] The stored service.
 * @return {object} The context, store, dialog and router.
 */
function page(props, service = {}) {
	const store = {
		loading: { service: true },
		objectTypeRegistry: { service: { register: 'r', schema: 's' } },
		getObject: vi.fn(() => service),
		getSchema: vi.fn(() => null),
		fetchObject: vi.fn(async () => null),
		fetchSchema: vi.fn(),
		saveObject: vi.fn(),
		deleteObject: vi.fn(),
		getError: vi.fn(() => null),
	}
	const dialog = { setResult: vi.fn() }
	const router = { push: vi.fn() }
	const ctx = { ...ServiceDetail.data(), ...props, $refs: { editDialog: dialog }, $router: router }
	for (const [name, def] of Object.entries(ServiceDetail.computed)) {
		Object.defineProperty(ctx, name, { get: name === 'objectStore' ? () => store : () => def.call(ctx), configurable: true })
	}
	for (const [name, fn] of Object.entries(ServiceDetail.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return { ctx, store, dialog, router }
}

beforeEach(() => {
	toasts.length = 0
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('labels', () => {
	it('reads the steps and skills of a stored service', () => {
		const { ctx } = page({ id: 's1' }, { multiStep: [{ productId: 'p1' }], requiredSkills: ['a', 'b'], cancellationPolicy: 'strict' })
		expect([ctx.isNew, ctx.steps, ctx.requiredSkillsLabel, ctx.loading]).toEqual([false, [{ productId: 'p1' }], 'a, b', true])
		expect(typeof ctx.cancellationPolicyLabel).toBe('string')
		const fresh = page({ id: 'new' }).ctx
		expect([fresh.isNew, fresh.serviceData, fresh.steps, fresh.requiredSkillsLabel]).toEqual([true, {}, [], '-'])
	})

	it('formats durations in minutes and hours', () => {
		const { ctx } = page({ id: 's1' })
		expect([ctx.formatDuration(45), ctx.formatDuration(60), ctx.formatDuration(135), ctx.formatDuration('x')]).toEqual(['45 min', '1h', '2h 15min', '0 min'])
		expect(ctx.statusLabel('')).toBe('-')
		expect(ctx.sidebarProps).toEqual({ title: 'Service', register: 'r', schema: 's', hiddenTabs: ['tasks'] })
		expect(ctx.editFieldOverrides.depositAmount.visibleWhen).toEqual({ field: 'requiresDeposit', truthy: true })
		expect(ctx.headerActions[0].id).toBe('delete-service')
		ctx.headerActions[0].onSelect()
		expect(ctx.showDelete).toBe(true)
	})
})

describe('product names', () => {
	it('loads each step product once and remembers the names', async () => {
		const { ctx, store } = page({ id: 's1' })
		store.fetchObject.mockImplementation(async (type, id) => (id === 'p1' ? { name: 'Haircut' } : id === 'p2' ? { title: 'Wash' } : null))
		await ctx.loadProductNames([{ productId: 'p1' }, { productId: 'p1' }, { productId: 'p2' }, { productId: 'p3' }, {}, null])
		expect(store.fetchObject.mock.calls.map((c) => c[1])).toEqual(['p1', 'p2', 'p3'])
		expect(ctx.productNames).toEqual({ p1: 'Haircut', p2: 'Wash' })
		await ctx.loadProductNames([{ productId: 'p1' }])
		expect(store.fetchObject).toHaveBeenCalledTimes(3)
		expect([ctx.productName('p1'), ctx.productName('p3'), ctx.productName('')]).toEqual(['Haircut', 'p3', '-'])
		ctx.rememberProductNames([{ id: 'p4', title: 'Dye' }, { id: 'p5' }, null])
		expect(ctx.productNames.p4).toBe('Dye')
		expect('p5' in ctx.productNames).toBe(false)
	})
})

describe('steps', () => {
	it('edits a copy of the steps and saves them on the service', async () => {
		const service = { id: 's1', name: 'Cut', multiStep: [{ productId: 'p1' }] }
		const { ctx, store } = page({ id: 's1' }, service)
		ctx.startEditSteps()
		expect([ctx.editingSteps, ctx.draftSteps]).toEqual([true, [{ productId: 'p1' }]])
		expect(ctx.draftSteps[0]).not.toBe(service.multiStep[0])
		ctx.draftSteps.push({ productId: 'p2' })
		store.saveObject.mockResolvedValueOnce({ id: 's1' })
		await ctx.saveSteps()
		expect(store.saveObject).toHaveBeenCalledWith('appointmentService', { id: 's1', name: 'Cut', multiStep: [{ productId: 'p1' }, { productId: 'p2' }] })
		expect([ctx.editingSteps, ctx.savingSteps, toasts]).toEqual([false, false, [['success', 'Steps saved.']]])
		ctx.startEditSteps()
		ctx.cancelEditSteps()
		expect([ctx.editingSteps, ctx.draftSteps]).toEqual([false, []])
	})

	it('keeps the editor open when the steps cannot be saved', async () => {
		const { ctx, store } = page({ id: 's1' })
		ctx.startEditSteps()
		store.saveObject.mockResolvedValueOnce(null)
		store.getError.mockReturnValueOnce({ message: 'Step without product' })
		await ctx.saveSteps()
		expect([ctx.editingSteps, toasts]).toEqual([true, [['error', 'Step without product']]])
	})
})

describe('forms and delete', () => {
	it('opens the edit form only with its schema, and saves it', async () => {
		const { ctx, store, dialog } = page({ id: 's1' })
		await ctx.openEditDialog()
		expect([ctx.showEditDialog, toasts]).toEqual([false, [['error', 'Could not load the service form.']]])
		store.getSchema.mockReturnValue({})
		await ctx.openEditDialog()
		expect(ctx.showEditDialog).toBe(true)
		store.saveObject.mockResolvedValueOnce({ id: 's1' })
		await ctx.onEditConfirm({ name: 'Cut' })
		expect(store.saveObject).toHaveBeenCalledWith('appointmentService', { name: 'Cut', id: 's1' })
		expect(dialog.setResult).toHaveBeenCalledWith({ success: true })
		store.saveObject.mockResolvedValueOnce(null)
		await ctx.onEditConfirm({})
		expect(dialog.setResult).toHaveBeenLastCalledWith({ error: 'Failed to save service.' })
		await ctx.refresh()
		expect(store.fetchObject).toHaveBeenCalledWith('appointmentService', 's1')
	})

	it('saves a new service and opens it, and returns to the list on cancel', async () => {
		const { ctx, store, router } = page({ id: 'new' })
		store.saveObject.mockResolvedValueOnce({ id: 's9' })
		await ctx.onFormSave({ name: 'New' })
		expect(router.push).toHaveBeenCalledWith({ name: 'ServiceDetail', params: { id: 's9' } })
		store.saveObject.mockResolvedValueOnce(null)
		await ctx.onFormSave({})
		ctx.onFormCancel()
		expect(router.push).toHaveBeenLastCalledWith({ name: 'Services' })
		expect(toasts).toEqual([['success', 'Service saved.'], ['error', 'Failed to save service.']])
	})

	it('deletes the service, or shows why not', async () => {
		const { ctx, store, router } = page({ id: 's1' })
		store.deleteObject.mockResolvedValueOnce(true)
		await ctx.confirmDelete()
		expect(router.push).toHaveBeenCalledWith({ name: 'Services' })
		store.deleteObject.mockResolvedValueOnce(false)
		await ctx.confirmDelete()
		expect(toasts).toEqual([['error', 'Failed to delete service.']])
	})
})
