// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pipeline card as a person works it: opening it by click, middle-click,
 * Enter or Space; the card menu opening its three pickers without opening
 * the card; each picker closing; and a failed priority change.
 *
 * Only the stores, Nextcloud components/dialogs and the user lookup are
 * doubled.
 *
 * @spec openspec/specs/pipeline/spec.md
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	globalThis.n = (app, one, many, count, vars = {}) => globalThis.t(app, count === 1 ? one : many, vars).replace('%n', String(count))
})

const stub = (name) => ({ name, render: () => null })
const toasts = []
vi.mock('@nextcloud/vue', () => ({ NcActionButton: stub('NcActionButton'), NcActions: { name: 'NcActions', template: '<div><slot /></div>' } }))
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

const item = { id: 'l1', title: 'Fleet renewal', stage: 'New', priority: 'normal', _entityType: 'lead', _schemaSlug: 'lead' }

/**
 * Mount a card without stages, so the stages default applies.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountCard() {
	const w = shallowMount(PipelineCard, {
		props: { item },
		global: {
			mocks: { t: globalThis.t, n: globalThis.n },
			stubs: { NcActions: false },
		},
	})
	await flushPromises()
	return w
}

/**
 * The menu buttons, in order: move, assign, priority.
 *
 * @param {object} w The wrapper.
 * @return {Array<object>}
 */
function menuButtons(w) {
	return w.findAllComponents({ name: 'NcActionButton' })
}

beforeEach(() => {
	toasts.length = 0
	vi.clearAllMocks()
})

describe('opening the card', () => {
	it('opens on click, middle-click and Enter, passing the item and the event', async () => {
		const w = await mountCard()

		await w.trigger('click')
		await w.trigger('auxclick')
		await w.trigger('keydown', { key: 'Enter' })

		const opened = w.emitted('open')
		expect(opened).toHaveLength(3)
		expect(opened.map(([emitted]) => emitted)).toEqual([item, item, item])
		expect(opened.map(([, event]) => event.type)).toEqual(['click', 'auxclick', 'keydown'])
	})

	it('opens on Space without scrolling the page', async () => {
		const w = await mountCard()
		const event = new KeyboardEvent('keydown', { key: ' ', cancelable: true })

		w.element.dispatchEvent(event)

		expect(w.emitted('open')).toEqual([[item, event]])
		expect(event.defaultPrevented).toBe(true)
	})

	it('does not open when the card menu is used', async () => {
		const w = await mountCard()
		const menu = w.find('.pipeline-card__menu')

		await menu.trigger('click')
		await menu.trigger('auxclick')
		await menu.trigger('keydown', { key: 'Enter' })

		expect(w.emitted('open')).toBeUndefined()
		expect(w.vm.stages).toEqual([])
	})
})

describe('the card menu', () => {
	it('opens the stage picker and closes it again', async () => {
		const w = await mountCard()

		menuButtons(w)[0].vm.$emit('click')
		await w.vm.$nextTick()
		const picker = w.findComponent({ name: 'StagePickerDialog' })
		expect(picker.exists()).toBe(true)

		picker.vm.$emit('close')
		await w.vm.$nextTick()
		expect(w.vm.moveMenuOpen).toBe(false)
		expect(w.findComponent({ name: 'StagePickerDialog' }).exists()).toBe(false)
	})

	it('opens the assignee picker with the current assignee and closes it again', async () => {
		const w = await mountCard()

		menuButtons(w)[1].vm.$emit('click')
		await flushPromises()
		const picker = w.findComponent({ name: 'AssigneePickerDialog' })
		expect(picker.exists()).toBe(true)
		expect(w.vm.pickedAssignee).toBeNull()

		picker.vm.$emit('close')
		await w.vm.$nextTick()
		expect(w.vm.assignMenuOpen).toBe(false)
	})

	it('opens the priority picker and closes it again', async () => {
		const w = await mountCard()

		menuButtons(w)[2].vm.$emit('click')
		await w.vm.$nextTick()
		const picker = w.findComponent({ name: 'PriorityPickerDialog' })
		expect(picker.exists()).toBe(true)

		picker.vm.$emit('close')
		await w.vm.$nextTick()
		expect(w.vm.priorityMenuOpen).toBe(false)
	})

	it('reports a failed priority change and does not refresh', async () => {
		store.saveObject.mockRejectedValueOnce(new Error('denied'))
		const w = await mountCard()

		await w.vm.onPickPriority('urgent')

		expect(store.saveObject).toHaveBeenCalledWith('lead', expect.objectContaining({ id: 'l1', priority: 'urgent' }))
		expect(toasts).toEqual([['error', 'Failed to update priority.']])
		expect(w.emitted('refresh')).toBeUndefined()
		expect(w.vm.priorityMenuOpen).toBe(false)
	})
})
