// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pipeline card, mounted with the stores and user lookups doubled: the
 * chance it shows, when a lead or request is overdue or stale, the owner, and
 * moving, assigning and prioritising the card.
 *
 * @spec openspec/specs/pipeline/spec.md
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	globalThis.n = (app, one, many, count, vars = {}) => globalThis.t(app, count === 1 ? one : many, vars).replace('%n', String(count))
})

const stub = (name) => ({ name, render: () => null })
const toasts = []
vi.mock('@nextcloud/vue', () => ({ NcActionButton: stub('NcActionButton'), NcActions: stub('NcActions') }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('../../src/components/leadScore/LeadScoreBadge.vue', () => ({ default: stub('LeadScoreBadge') }))
vi.mock('../../src/dialogs/AssigneePickerDialog.vue', () => ({ default: stub('AssigneePickerDialog') }))
vi.mock('../../src/dialogs/PriorityPickerDialog.vue', () => ({ default: stub('PriorityPickerDialog') }))
vi.mock('../../src/dialogs/StagePickerDialog.vue', () => ({ default: stub('StagePickerDialog') }))
vi.mock('../../src/services/userDisplayName.js', () => ({
	cachedUserDisplayName: vi.fn(async (uid) => `Name of ${uid}`),
	initialsOf: (name) => name.split(' ').map((p) => p[0]).join('').slice(0, 2).toUpperCase(),
	listAssignableUserIds: vi.fn(async () => ['ann', 'bob']),
}))
const store = { saveObject: vi.fn() }
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => ({ config: {} }) }))

const { default: PipelineCard } = await import('../../src/views/pipeline/PipelineCard.vue')

const stages = [
	{ name: 'New', order: 0, probability: 10, color: '#00f' },
	{ name: 'Offer', order: 1, probability: '60.4' },
	{ name: 'Won', order: 2, isClosed: true },
]

/**
 * Mount a card.
 *
 * @param {object} item The item on the card.
 * @param {object} [props] Other props.
 * @return {Promise<object>} The wrapper.
 */
async function mountCard(item, props = {}) {
	const w = shallowMount(PipelineCard, { props: { item, stages, ...props }, global: { mocks: { t: globalThis.t, n: globalThis.n } } })
	await flushPromises()
	return w
}

beforeEach(() => {
	toasts.length = 0
	vi.clearAllMocks()
	vi.useFakeTimers({ now: new Date('2026-05-15T12:00:00Z'), toFake: ['Date'] })
})

afterEach(() => {
	vi.useRealTimers()
})

describe('what the card shows', () => {
	it('shows the lead chance, or the stage chance when the lead has none', async () => {
		const own = await mountCard({ id: 'l1', stage: 'New', probability: '35.6' })
		expect([own.vm.chance, own.vm.stageColor, own.vm.cardStage.name]).toEqual([36, '#00f', 'New'])
		const staged = await mountCard({ id: 'l2', stage: 'Offer', probability: '' })
		expect([staged.vm.chance, staged.vm.stageColor]).toEqual([60, ''])
		const none = await mountCard({ id: 'l3', stage: 'Nowhere' })
		expect([none.vm.chance, none.vm.cardStage]).toEqual([null, null])
		const request = await mountCard({ id: 'r1', status: 'New' }, { entityType: 'request', columnProperty: 'status' })
		expect([request.vm.chance, request.vm.currentColumnValue, request.vm.selectedStage]).toEqual([null, 'New', 'New'])
		expect(own.vm.stageOptions).toEqual(['New', 'Offer', 'Won'])
	})

	it('calls an open lead overdue after its close date, never a settled one', async () => {
		expect((await mountCard({ stage: 'New', expectedCloseDate: '2026-05-01' })).vm.isOverdue).toBe(true)
		expect((await mountCard({ stage: 'New', expectedCloseDate: '2026-06-01' })).vm.isOverdue).toBe(false)
		expect((await mountCard({ stage: 'Won', expectedCloseDate: '2026-05-01' })).vm.isOverdue).toBe(false)
		expect((await mountCard({ status: 'lost', expectedCloseDate: '2026-05-01' })).vm.isOverdue).toBe(false)
		expect((await mountCard({ stage: 'New' })).vm.isOverdue).toBe(false)
	})

	it('calls a request overdue after thirty days', async () => {
		expect((await mountCard({ occurredAt: '2026-04-01' }, { entityType: 'request' })).vm.isOverdue).toBe(true)
		expect((await mountCard({ occurredAt: '2026-05-01' }, { entityType: 'request' })).vm.isOverdue).toBe(false)
		expect((await mountCard({}, { entityType: 'request' })).vm.isOverdue).toBe(false)
		expect((await mountCard({ occurredAt: '2026-01-01' }, { entityType: 'other' })).vm.isOverdue).toBe(false)
	})

	it('flags a stale lead and shows meta only when there is something to show', async () => {
		const old = await mountCard({ stage: 'New', _dateModified: '2026-01-01' })
		expect(old.vm.daysAge).toBeGreaterThan(14)
		expect([old.vm.isStaleItem, old.vm.hasMeta]).toEqual([true, true])
		const request = await mountCard({ _dateModified: '2026-01-01' }, { entityType: 'request' })
		expect(request.vm.isStaleItem).toBe(false)
		const bare = await mountCard({ stage: 'New', _dateModified: '2026-05-15T12:00:00Z' })
		expect(bare.vm.hasMeta).toBe(false)
		expect(bare.vm.priorityOptions.map((o) => o.value)).toEqual(['low', 'normal', 'high', 'urgent'])
	})

	it('names the owner on the board look only', async () => {
		const plain = await mountCard({ assignee: 'ann' })
		expect([plain.vm.ownerName, plain.vm.ownerInitials, plain.vm.selectedAssignee]).toEqual(['', 'A', 'ann'])
		const board = await mountCard({ assignee: 'ann' }, { boardLook: true })
		expect([board.vm.ownerName, board.vm.ownerInitials]).toEqual(['Name of ann', 'NO'])
		await plain.setProps({ boardLook: true })
		await flushPromises()
		expect(plain.vm.ownerName).toBe('Name of ann')
	})
})

describe('changing the card', () => {
	it('moves a lead to another stage with the stage order, without the board fields', async () => {
		const w = await mountCard({ id: 'l1', stage: 'New', _entityType: 'lead', _schemaSlug: 'lead' })
		await w.vm.onPickStage({ name: 'Offer' })
		expect(store.saveObject).toHaveBeenCalledWith('lead', { id: 'l1', stage: 'Offer', stageOrder: 1 })
		expect([w.vm.moveMenuOpen, toasts, w.emitted('refresh')]).toEqual([false, [['success', 'Lead moved to Offer']], [[]]])
		await w.vm.onStageChange('New')
		await w.vm.onStageChange('')
		expect(store.saveObject).toHaveBeenCalledTimes(1)
	})

	it('moves a request by its own column, adding the ticket type', async () => {
		const w = await mountCard({ id: 'r1', status: 'New' }, { entityType: 'request', columnProperty: 'status' })
		await w.vm.onStageChange('Offer')
		expect(store.saveObject).toHaveBeenCalledWith('ticket', { id: 'r1', status: 'Offer', ticketType: 'request' })
	})

	it('puts the stage back when the move fails', async () => {
		store.saveObject.mockRejectedValueOnce(new Error('x'))
		const w = await mountCard({ id: 'l1', stage: 'New' })
		w.vm.selectedStage = 'Offer'
		await w.vm.onStageChange('Offer')
		expect([w.vm.selectedStage, toasts]).toEqual(['New', [['error', 'Failed to move lead. Please try again.']]])
	})

	it('assigns the card, or puts the assignee back on failure', async () => {
		const w = await mountCard({ id: 'l1', stage: 'New', assignee: 'ann' })
		w.vm.openAssignMenu()
		await flushPromises()
		expect([w.vm.assignMenuOpen, w.vm.pickedAssignee, w.vm.users]).toEqual([true, 'ann', ['ann', 'bob']])
		await w.vm.onPickAssignee('ann')
		expect(store.saveObject).not.toHaveBeenCalled()
		await w.vm.onPickAssignee('bob')
		expect(store.saveObject.mock.calls[0][1].assignee).toBe('bob')
		await w.vm.onAssignChange(null)
		expect(store.saveObject.mock.calls[1][1].assignee).toBe('')
		store.saveObject.mockRejectedValueOnce(new Error('x'))
		w.vm.selectedAssignee = 'eve'
		await w.vm.onAssignChange('eve')
		expect([w.vm.selectedAssignee, toasts.at(-1)]).toEqual(['ann', ['error', 'Failed to update assignee.']])
	})

	it('sets the priority only when it changes', async () => {
		const w = await mountCard({ id: 'l1', stage: 'New', priority: 'normal' })
		w.vm.openPriorityMenu()
		expect(w.vm.priorityMenuOpen).toBe(true)
		await w.vm.onPickPriority('normal')
		expect(store.saveObject).not.toHaveBeenCalled()
		await w.vm.onPickPriority('urgent')
		expect(store.saveObject.mock.calls[0][1].priority).toBe('urgent')
		expect([w.vm.priorityMenuOpen, toasts.at(-1)]).toEqual([false, ['success', 'Priority updated']])
	})

	it('opens the card on space without scrolling the page', async () => {
		const w = await mountCard({ id: 'l1' })
		const event = { preventDefault: vi.fn() }
		w.vm.openOnSpace(event)
		expect(w.emitted('open')).toEqual([[{ id: 'l1' }, event]])
		expect(event.preventDefault).toHaveBeenCalled()
		w.vm.openMoveMenu()
		expect(w.vm.moveMenuOpen).toBe(true)
	})
})
