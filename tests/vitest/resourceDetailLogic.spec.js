// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bookable resource detail page's own logic, run against a plain component
 * context: labels and header line, loading the week and the staff profile,
 * validating and saving the edit form, saving a new resource and deleting.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	globalThis.n = (app, one, many, count, vars = {}) => globalThis.t(app, count === 1 ? one : many, vars).replace('%n', String(count))
})

const stub = (name) => ({ default: { name, render: () => null } })
const toasts = []
vi.mock('@conduction/nextcloud-vue', async (orig) => ({ ...(await orig()), useObjectSubscription: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('../../src/components/bookings/ResourceHoursEditor.vue', () => stub('A'))
vi.mock('../../src/components/bookings/ResourceVacationsEditor.vue', () => stub('B'))
vi.mock('../../src/components/KpiFigure.vue', () => stub('C'))
vi.mock('../../src/dialogs/DeleteResourceDialog.vue', () => stub('D'))
vi.mock('../../src/views/bookings/ResourceForm.vue', () => stub('E'))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

const { default: ResourceDetail } = await import('../../src/views/bookings/ResourceDetail.vue')

globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * A resource page context over a store double.
 *
 * @param {object} props The props.
 * @param {object} [resource] The stored resource.
 * @return {object} The context.
 */
function page(props, resource = {}) {
	const store = {
		loading: { resource: false },
		objectTypeRegistry: { resource: { register: 'r', schema: 's' } },
		getObject: vi.fn(() => resource),
		getSchema: vi.fn(() => null),
		fetchObject: vi.fn(),
		fetchSchema: vi.fn(),
		fetchCollection: vi.fn(async () => []),
		saveObject: vi.fn(),
		deleteObject: vi.fn(),
		getError: vi.fn(() => null),
	}
	const dialog = { setValidationErrors: vi.fn(), setResult: vi.fn() }
	const router = { push: vi.fn() }
	const ctx = { ...ResourceDetail.data(), ...props, $refs: { editDialog: dialog }, $router: router }
	for (const [name, def] of Object.entries(ResourceDetail.computed)) {
		Object.defineProperty(ctx, name, { get: name === 'objectStore' ? () => store : () => def.call(ctx), configurable: true })
	}
	for (const [name, fn] of Object.entries(ResourceDetail.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return { ctx, store, dialog, router }
}

beforeEach(() => {
	toasts.length = 0
	axios.get = vi.fn()
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('labels', () => {
	it('names the type, status and skills of a resource, with a dash when unknown', () => {
		const { ctx } = page({ id: 'r1' }, { type: 'room', status: 'archived', skills: ['A', 'B'], workingHours: 'x', vacations: [{ startDate: '2026-01-01' }] })
		expect([ctx.typeLabel, ctx.statusLabel, ctx.skillsLabel, ctx.workingHours, ctx.vacations]).toEqual(['Room', 'Archived', 'A, B', [], [{ startDate: '2026-01-01' }]])
		const odd = page({ id: 'r2' }, { type: 'drone', status: 'paused' }).ctx
		expect([odd.typeLabel, odd.statusLabel, odd.skillsLabel]).toEqual(['drone', 'paused', '-'])
		const none = page({ id: 'r3' }, {}).ctx
		expect([none.typeLabel, none.statusLabel]).toEqual(['-', '-'])
	})

	it('treats no id or "new" as a new resource with no data', () => {
		const { ctx, store } = page({ id: 'new' })
		expect([ctx.isNew, ctx.resourceData]).toEqual([true, {}])
		expect(store.getObject).not.toHaveBeenCalled()
		expect(page({}).ctx.isNew).toBe(true)
	})

	it('builds the header line from the staff profile and the side column', () => {
		const { ctx } = page({ id: 'r1' })
		ctx.staffLine = { function: 'Advisor', department: ' ' }
		expect(ctx.headerMeta).toBe('Advisor')
		ctx.staffLine = { function: 'Advisor', department: 'Permits' }
		expect(ctx.headerMeta).toBe('Advisor · Permits')
		expect(ctx.sideColumn).toEqual([{ id: 'resource-week', type: 'custom', title: 'This week' }])
		expect(ctx.sidebarProps).toEqual({ title: 'Resource', register: 'r', schema: 's', hiddenTabs: ['tasks'] })
		expect(ctx.typePill.labels.staff).toBe('Staff')
		expect(ctx.statusPill.colorMap.active).toBe('success')
		expect(ctx.loading).toBe(false)
	})

	it('describes the load of the week, with or without bookable time', () => {
		const { ctx } = page({ id: 'r1' }, { workingHours: [] })
		expect(ctx.weekCaption).toBe('bookings, no bookable time this week')
		Object.defineProperty(ctx, 'weekLoad', { get: () => ({ percent: 40 }), configurable: true })
		expect(ctx.weekCaption).toBe('bookings, 40% of the bookable time')
	})

	it('labels days and dates, with a dash for nothing', () => {
		const { ctx } = page({ id: 'r1' })
		expect([ctx.dayLabel('MONDAY'), ctx.dayLabel('')]).toEqual(['Monday', '-'])
		expect([ctx.shortDate(''), ctx.shortDate('nope')]).toEqual(['-', 'nope'])
		expect(ctx.shortDate('2026-03-04')).toBe(new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date('2026-03-04')))
		expect(Object.values(ctx.editFieldOverrides).every((o) => o.description === '' && o.descriptionLong === '')).toBe(true)
		expect(ctx.editFieldOverrides.workingHours.widget).toBe('json')
	})
})

describe('loading', () => {
	it('reads the staff profile of a staff resource, and nothing otherwise', async () => {
		axios.get.mockResolvedValueOnce({ data: { function: 'Advisor' } })
		const { ctx } = page({ id: 'r1' }, { userId: 'ann' })
		await ctx.fetchStaffLine()
		expect(axios.get.mock.calls[0][0]).toContain('/apps/pipelinq/api/staff/ann/profile')
		expect(ctx.staffLine).toEqual({ function: 'Advisor', department: '' })
		axios.get.mockRejectedValueOnce(new Error('x'))
		await ctx.fetchStaffLine()
		expect(ctx.staffLine).toEqual({ function: '', department: '' })
		await page({ id: 'r2' }, {}).ctx.fetchStaffLine()
		expect(axios.get).toHaveBeenCalledTimes(2)
	})

	it('reads this week\'s figures from the server in one request, none when the read fails', async () => {
		const { ctx, store } = page({ id: 'r1' })
		axios.get.mockResolvedValueOnce({ data: { bookingsThisWeek: 22, bookedShare: 74 } })
		await ctx.fetchWeekFigures()
		expect(axios.get.mock.calls[0][0]).toContain('/apps/pipelinq/api/appointment-resources/r1/figures')
		expect(store.fetchCollection).not.toHaveBeenCalled()
		expect(ctx.weekLoad).toEqual({ count: 22, percent: 74 })
		axios.get.mockResolvedValueOnce({ data: 'x' })
		await ctx.fetchWeekFigures()
		expect(ctx.weekLoad).toEqual({ count: 0, percent: null })
		axios.get.mockRejectedValueOnce(new Error('x'))
		await ctx.fetchWeekFigures()
		expect(ctx.weekLoad).toEqual({ count: 0, percent: null })
	})
})

describe('editing', () => {
	it('opens the edit form only when its schema loads', async () => {
		const { ctx, store } = page({ id: 'r1' })
		await ctx.openEditDialog()
		expect([ctx.showEditDialog, toasts]).toEqual([false, [['error', 'Could not load the resource form.']]])
		store.getSchema.mockReturnValue({ properties: {} })
		await ctx.openEditDialog()
		expect(ctx.showEditDialog).toBe(true)
	})

	it('refuses opening hours or vacations that run backwards', async () => {
		const { ctx, store, dialog } = page({ id: 'r1' })
		await ctx.onEditConfirm({ workingHours: [{ openTime: '17:00', closeTime: '09:00' }], vacations: [{ startDate: '2026-02-02', endDate: '2026-02-01' }] })
		expect(dialog.setValidationErrors).toHaveBeenCalledWith({
			workingHours: 'Open time must be before close time.',
			vacations: 'Vacation start date must be on or before the end date.',
		})
		expect(store.saveObject).not.toHaveBeenCalled()
	})

	it('saves only the known fields of each row and reloads', async () => {
		const { ctx, store, dialog } = page({ id: 'r1' })
		store.saveObject.mockResolvedValueOnce({ id: 'r1' })
		await ctx.onEditConfirm({ name: 'Room 1', workingHours: [{ day: 'monday', openTime: '09:00', closeTime: '17:00', extra: 1 }], vacations: [{ startDate: '2026-02-01', endDate: '2026-02-02' }] })
		expect(store.saveObject).toHaveBeenCalledWith('appointmentResource', {
			name: 'Room 1', id: 'r1',
			workingHours: [{ day: 'monday', openTime: '09:00', closeTime: '17:00' }],
			vacations: [{ startDate: '2026-02-01', endDate: '2026-02-02', label: '' }],
		})
		expect(dialog.setResult).toHaveBeenCalledWith({ success: true })
		expect(store.fetchObject).toHaveBeenCalledWith('appointmentResource', 'r1')
	})

	it('shows the store error of a refused save in the dialog', async () => {
		const { ctx, store, dialog } = page({ id: 'r1' })
		store.saveObject.mockResolvedValueOnce(null)
		store.getError.mockReturnValueOnce({ message: 'Name taken' })
		await ctx.onEditConfirm({})
		store.saveObject.mockResolvedValueOnce(null)
		await ctx.onEditConfirm({})
		expect(dialog.setResult.mock.calls).toEqual([[{ error: 'Name taken' }], [{ error: 'Failed to save resource.' }]])
	})
})

describe('new resource and delete', () => {
	it('saves a new resource and opens it, or shows why not', async () => {
		const { ctx, store, router } = page({ id: 'new' })
		store.saveObject.mockResolvedValueOnce({ id: 'r9' })
		await ctx.onFormSave({ name: 'Room' })
		expect(router.push).toHaveBeenCalledWith({ name: 'ResourceDetail', params: { id: 'r9' } })
		store.saveObject.mockResolvedValueOnce(null)
		await ctx.onFormSave({})
		expect(toasts).toEqual([['success', 'Resource saved.'], ['error', 'Failed to save resource.']])
		ctx.onFormCancel()
		expect(router.push).toHaveBeenLastCalledWith({ name: 'Resources' })
	})

	it('deletes the resource and returns to the list, or shows why not', async () => {
		const { ctx, store, router } = page({ id: 'r1' })
		ctx.headerActions[0].onSelect()
		expect(ctx.showDelete).toBe(true)
		store.deleteObject.mockResolvedValueOnce(true)
		await ctx.confirmDelete()
		expect([ctx.showDelete, router.push.mock.calls[0][0]]).toEqual([false, { name: 'Resources' }])
		store.deleteObject.mockResolvedValueOnce(false)
		store.getError.mockReturnValueOnce({ message: 'Has bookings' })
		await ctx.confirmDelete()
		expect(toasts).toEqual([['error', 'Has bookings']])
	})
})
