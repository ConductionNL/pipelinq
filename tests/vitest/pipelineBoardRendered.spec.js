// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pipeline board as it renders: mounted against a store double and a
 * stubbed fetch, so the columns, the stage totals and their breakdown, the
 * closed stages, the leads on no board, the list view and the live
 * subscriptions are checked through the markup the board draws.
 *
 * @spec openspec/specs/pipeline/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	globalThis.n = (app, one, many, count, vars = {}) => globalThis.t(app, count === 1 ? one : many, vars).replace('%n', String(count))
})

/**
 * A stub that draws every slot it is given inside one element.
 *
 * @param {string} name The component name.
 * @param {string} [tag] The element it renders.
 * @return {object} The stub.
 */
function slotted (name, tag = 'div') {
  return defineComponent({
	name,
	setup(props, { slots }) {
		return () => h(tag, {}, Object.values(slots).map((slot) => slot()))
	},
})
}

const look = { isBoard: true }

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnActionsBar: slotted('CnActionsBar'),
	CnBuildiqEditButton: slotted('CnBuildiqEditButton'),
	CnPageHeader: slotted('CnPageHeader'),
	CnQuickFilterBar: slotted('CnQuickFilterBar'),
	openRowTarget: vi.fn(),
	useLook: () => ({ isBoard: look.isBoard, lookClass: look.isBoard ? 'cn-look-board' : '' }),
}))
vi.mock('@nextcloud/vue', () => ({
	NcActionButton: slotted('NcActionButton'),
	NcActionCaption: slotted('NcActionCaption'),
	NcActionCheckbox: slotted('NcActionCheckbox'),
	NcActions: slotted('NcActions'),
	NcButton: slotted('NcButton', 'button'),
	NcCheckboxRadioSwitch: slotted('NcCheckboxRadioSwitch'),
	NcLoadingIcon: slotted('NcLoadingIcon', 'span'),
	NcNoteCard: slotted('NcNoteCard', 'section'),
	NcSelect: slotted('NcSelect'),
	NcTextField: slotted('NcTextField'),
}))
vi.mock('vuedraggable', () => ({
	default: defineComponent({
		name: 'Draggable',
		props: { modelValue: { type: Array, default: () => [] } },
		setup(props, { slots }) {
			return () => h('div', {}, props.modelValue.map((element) => slots.item({ element })))
		},
	}),
}))
vi.mock('../../src/views/pipeline/PipelineCard.vue', () => ({
	default: defineComponent({
		name: 'PipelineCard',
		props: { item: { type: Object, required: true } },
		emits: ['refresh', 'open'],
		setup(props, { emit }) {
			return () => h('article', { class: 'card-stub' }, [
				h('span', { class: 'card-title' }, props.item.title),
				h('button', { class: 'card-refresh', onClick: () => emit('refresh') }),
			])
		},
	}),
}))
vi.mock('../../src/components/leadScore/LeadScoreBadge.vue', () => ({ default: slotted('LeadScoreBadge', 'span') }))
vi.mock('../../src/dialogs/LeadCreateDialog.vue', () => ({ default: slotted('LeadCreateDialog') }))
vi.mock('../../src/dialogs/PipelineFormDialog.vue', () => ({ default: slotted('PipelineFormDialog') }))
vi.mock('../../src/services/csvExport.js', () => ({ downloadCsv: vi.fn() }))

let store
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn(async () => undefined) }))

const { default: PipelineBoard } = await import('../../src/views/pipeline/PipelineBoard.vue')

const DAY = 86400000

const pipeline = {
	id: 'p1',
	title: 'Sales',
	totalsLabel: 'EUR',
	stages: [
		{ name: 'Won', order: 9, isClosed: true },
		{ name: 'New', order: 1, maxItems: 2, color: '#123456' },
		{ name: 'Offer', order: 2, probability: 40 },
	],
	propertyMappings: [
		{ schemaSlug: 'lead', columnProperty: 'stage', totalsProperty: 'value' },
	],
}

/**
 * A lead on the Sales board.
 *
 * @param {string} id The id.
 * @param {string} stage The stage.
 * @param {object} [extra] Other fields.
 * @return {object} The lead.
 */
function lead (id, stage, extra = {}) {
  return {
	id,
	title: `Lead ${id}`,
	stage,
	pipeline: 'p1',
	value: 100,
	_dateModified: new Date(Date.now() - 2 * DAY).toISOString(),
	...extra,
}
}

let boardItems
let allLeads
let fetchCalls

/**
 * Answer the board's two reads: every lead, and the items of the pipeline.
 *
 * @param {string} url The requested URL.
 * @return {Promise<object>} The response.
 */
function answer(url) {
	fetchCalls.push(url)
	const results = url.includes('pipeline=') ? boardItems : allLeads
	return Promise.resolve({ ok: true, json: () => Promise.resolve({ results }) })
}

/**
 * Mount the board and let its opening fetches settle.
 *
 * @param {object} [mocks] Extra global mocks.
 * @return {Promise<object>} The wrapper.
 */
async function mountBoard(mocks = {}) {
	const wrapper = mount(PipelineBoard, {
		global: {
			mocks: { t: globalThis.t, n: globalThis.n, ...mocks },
			stubs: {
				'router-link': slotted('RouterLink', 'a'),
				Download: true,
				Plus: true,
				Tune: true,
				Magnify: true,
				Cog: true,
				ViewColumn: true,
				FormatListBulleted: true,
			},
		},
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	look.isBoard = true
	fetchCalls = []
	boardItems = [
		lead('a', 'New', { value: 100 }),
		lead('b', 'New', { value: 250 }),
		lead('c', 'Offer', { value: 40, _dateModified: new Date(Date.now() - 20 * DAY).toISOString() }),
		lead('d', 'Won', { value: 999 }),
	]
	allLeads = [...boardItems]
	store = {
		collections: { pipeline: [pipeline] },
		objectTypeRegistry: { lead: { register: 'r1', schema: 's-lead' } },
		liveLastEventAt: null,
		fetchCollection: vi.fn(async () => []),
		subscribe: vi.fn(async (type) => `handle-${type}`),
		unsubscribe: vi.fn(),
	}
	vi.stubGlobal('fetch', vi.fn(answer))
	vi.stubGlobal('OC', { requestToken: 'token' })
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('the pipeline board as drawn', () => {
	it('opens on the pipeline and draws one column per open stage, in stage order, with its cards', async () => {
		const wrapper = await mountBoard()

		const columns = wrapper.findAll('.kanban-column')
		expect(columns.map((c) => c.find('.column-title').text())).toEqual(['New', 'Offer'])
		expect(columns[0].findAll('.card-title').map((c) => c.text())).toEqual(['Lead a', 'Lead b'])
		expect(columns[1].findAll('.card-title').map((c) => c.text())).toEqual(['Lead c'])
		expect(fetchCalls).toContain('/apps/openregister/api/objects/r1/s-lead?pipeline=p1&_limit=200')
	})

	it('counts a limited stage against its limit and marks it full', async () => {
		const wrapper = await mountBoard()

		const counts = wrapper.findAll('[data-testid="column-count"]')
		expect(counts.map((c) => c.text())).toEqual(['2 / 2', '1'])
		expect(counts[0].classes()).toContain('column-count--full')
		expect(counts[1].classes()).not.toContain('column-count--full')
	})

	it('colours a stage header and dot from the stage colour and shows the stage probability', async () => {
		const wrapper = await mountBoard()

		const [newColumn, offerColumn] = wrapper.findAll('.kanban-column')
		expect(newColumn.find('.kanban-column__header').attributes('style')).toContain('border-top-color')
		expect(newColumn.find('.column-dot').attributes('style')).toContain('background')
		expect(offerColumn.find('.column-probability').text()).toBe('· 40%')
		expect(newColumn.find('.column-probability').exists()).toBe(false)
	})

	it('totals each stage and opens and closes its product breakdown', async () => {
		const wrapper = await mountBoard()

		const total = wrapper.findAll('.column-value--clickable')[0]
		expect(total.text()).toBe(`EUR ${(350).toLocaleString()}`)
		expect(total.attributes('aria-expanded')).toBe('false')

		await total.trigger('click')
		expect(total.attributes('aria-expanded')).toBe('true')
		expect(wrapper.find('.stage-breakdown__title').text()).toBe('Top products in New')
		expect(wrapper.find('.stage-breakdown__empty').text()).toBe('No product breakdown available for this stage')

		await wrapper.find('.stage-breakdown__close').trigger('click')
		expect(wrapper.find('.stage-breakdown').exists()).toBe(false)
	})

	it('lists the products in a stage breakdown with their count and total', async () => {
		store.fetchCollection = vi.fn(async (type) => {
			if (type === 'leadProduct') {
				return [
					{ id: 'lp1', lead: 'a', product: 'prod-1', quantity: 2, total: 80 },
					{ id: 'lp2', lead: 'b', product: 'prod-1', quantity: 1, total: 40 },
				]
			}
			if (type === 'product') {
				return [{ id: 'prod-1', name: 'Widget' }]
			}
			return []
		})
		const wrapper = await mountBoard()

		await wrapper.findAll('.column-value--clickable')[0].trigger('click')
		const rows = wrapper.findAll('.stage-breakdown__row')
		expect(rows).toHaveLength(1)
		expect(rows[0].find('.stage-breakdown__name').text()).toBe('Widget')
		expect(rows[0].find('.stage-breakdown__count').text()).toBe('2×')
		expect(rows[0].find('.stage-breakdown__total').text()).toBe(`EUR ${(120).toLocaleString()}`)
		expect(store.fetchCollection).toHaveBeenCalledWith('leadProduct', { _limit: 500 })
	})

	it('keeps a closed stage collapsed with its count and shows its cards once expanded', async () => {
		const wrapper = await mountBoard()

		const closed = wrapper.find('.kanban-closed-column')
		expect(closed.find('.closed-title').text()).toBe('WON')
		expect(closed.find('.closed-count').text()).toBe('1')
		expect(closed.attributes('aria-expanded')).toBe('false')
		expect(closed.find('.closed-drop-zone').exists()).toBe(true)
		expect(closed.find('.card-title').exists()).toBe(false)

		await closed.trigger('click')
		expect(closed.attributes('aria-expanded')).toBe('true')
		expect(closed.findAll('.card-title').map((c) => c.text())).toEqual(['Lead d'])

		await closed.trigger('keydown', { key: 'Enter' })
		expect(closed.attributes('aria-expanded')).toBe('false')
	})

	it('refetches the board in place when a card asks for a refresh', async () => {
		const wrapper = await mountBoard()
		const before = fetchCalls.filter((u) => u.includes('pipeline=p1')).length

		boardItems = [lead('a', 'Offer')]
		await wrapper.find('.card-refresh').trigger('click')
		await flushPromises()

		expect(fetchCalls.filter((u) => u.includes('pipeline=p1')).length).toBe(before + 1)
		expect(wrapper.find('.kanban-column').findAll('.card-title')).toHaveLength(0)
		expect(wrapper.findAll('.kanban-column')[1].findAll('.card-title').map((c) => c.text())).toEqual(['Lead a'])
	})

	it('names the leads that are on no board and lists them with the reason on request', async () => {
		allLeads = [
			...boardItems,
			lead('x', 'New', { pipeline: 'gone', title: 'Lost pipeline' }),
			lead('y', 'Archived', { title: 'Lost stage' }),
		]
		const wrapper = await mountBoard()

		const note = wrapper.find('[data-testid="pipeline-orphaned-leads"]')
		expect(note.find('p').text()).toBe('2 leads are on no board, because their pipeline or stage no longer exists.')
		expect(note.find('.pipeline-board__orphan-list').exists()).toBe(false)

		const toggle = note.find('button')
		expect(toggle.text()).toBe('Show these leads')
		await toggle.trigger('click')

		expect(toggle.text()).toBe('Hide these leads')
		expect(note.findAll('.pipeline-board__orphan-reason').map((r) => r.text())).toEqual([
			'Its pipeline no longer exists.',
			'Its stage Archived is not a stage of its pipeline.',
		])
		expect(note.findAll('a').map((a) => a.text())).toEqual(['Lost pipeline', 'Lost stage'])
	})

	it('draws no note when every lead is on a board', async () => {
		const wrapper = await mountBoard()
		expect(wrapper.find('[data-testid="pipeline-orphaned-leads"]').exists()).toBe(false)
	})

	it('asks for a pipeline when there is none to show', async () => {
		store.collections.pipeline = []
		const wrapper = await mountBoard()

		expect(wrapper.find('.pipeline-board__empty').text()).toBe('Select a pipeline to view the board')
		expect(wrapper.find('.pipeline-board__columns').exists()).toBe(false)
		expect(fetchCalls).toEqual([])
	})

	it('draws the list view with one row per item, its stage, value and age', async () => {
		const wrapper = await mountBoard()
		wrapper.vm.viewMode = 'list'
		await flushPromises()

		const rows = wrapper.findAll('.list-row')
		expect(rows.map((r) => r.find('.list-title').text())).toEqual(['Lead a', 'Lead b', 'Lead c Stale', 'Lead d'])
		const cells = rows[2].findAll('td')
		expect(cells[1].text()).toBe('LEAD')
		expect(cells[2].text()).toBe('Offer')
		expect(cells[3].text()).toBe('—')
		expect(cells[4].text()).toBe(`EUR ${(40).toLocaleString()}`)
		expect(rows[2].find('.aging-badge').text()).toBe('20d')
		expect(rows[2].find('.aging-badge').classes()).toContain('aging-alert')
		expect(rows[2].find('.stale-badge').text()).toBe('Stale')
		expect(rows[0].find('.aging-badge').text()).toBe('2d')
		expect(rows[0].find('.aging-badge').classes()).not.toContain('aging-alert')
		expect(rows[0].find('.stale-badge').exists()).toBe(false)
		expect(wrapper.find('.list-empty').exists()).toBe(false)
	})

	it('labels an item changed today as Today and one changed a week ago as a warning', async () => {
		boardItems = [
			lead('t', 'New', { _dateModified: new Date().toISOString() }),
			lead('w', 'New', { _dateModified: new Date(Date.now() - 8 * DAY).toISOString() }),
		]
		const wrapper = await mountBoard()
		wrapper.vm.viewMode = 'list'
		await flushPromises()

		const badges = wrapper.findAll('.aging-badge')
		expect(badges.map((b) => b.text())).toEqual(['Today', '8d'])
		expect(badges[1].classes()).toContain('aging-warning')
	})

	it('says the list is empty when the search matches nothing', async () => {
		const wrapper = await mountBoard()
		wrapper.vm.viewMode = 'list'
		await wrapper.find('.cn-actions-bar__search-input').setValue('nothing like this')

		expect(wrapper.findAll('.list-row')).toHaveLength(0)
		expect(wrapper.find('.list-empty').text()).toBe('No items in this pipeline')
	})

	it('sorts the list when a column header is clicked and marks the sorted column', async () => {
		const wrapper = await mountBoard()
		wrapper.vm.viewMode = 'list'
		await flushPromises()

		const titleHeader = wrapper.findAll('th')[0]
		await titleHeader.find('button').trigger('click')
		expect(titleHeader.find('.sort-indicator').exists()).toBe(true)
		const first = wrapper.findAll('.list-row').map((r) => r.find('.list-title').text())
		await titleHeader.find('button').trigger('click')
		const second = wrapper.findAll('.list-row').map((r) => r.find('.list-title').text())
		expect(second).toEqual([...first].reverse())
	})

	it('links the cards view switch to the leads list through the router', async () => {
		const resolve = vi.fn(() => ({ href: '/apps/pipelinq/leads' }))
		const wrapper = await mountBoard({ $router: { resolve } })

		expect(wrapper.vm.viewModeLinks).toEqual({ cards: { href: '/apps/pipelinq/leads', to: { name: 'Leads' } } })
		expect(resolve).toHaveBeenCalledWith({ name: 'Leads' })
	})

	it('offers no view link without a router', async () => {
		const wrapper = await mountBoard()
		expect(wrapper.vm.viewModeLinks).toEqual({})
	})

	it('shares one drag configuration across the columns', async () => {
		const wrapper = await mountBoard()
		expect(wrapper.vm.dragOptions).toMatchObject({ itemKey: 'id', group: 'pipeline-cards' })
	})

	it('subscribes to the live types of the pipeline and releases them when it unmounts', async () => {
		const wrapper = await mountBoard()

		expect(wrapper.vm.liveTypes).toEqual(['lead'])
		expect(store.subscribe).toHaveBeenCalledWith('lead')

		wrapper.unmount()
		expect(store.unsubscribe).toHaveBeenCalledWith('handle-lead')
	})

	it('draws the classic header with the view toggle outside the board look', async () => {
		look.isBoard = false
		const wrapper = await mountBoard()

		expect(wrapper.find('[data-testid="pipeline-toolbar"]').exists()).toBe(false)
		expect(wrapper.find('.pipeline-board__header').exists()).toBe(true)
		expect(wrapper.find('.column-dot').exists()).toBe(false)

		const listToggle = wrapper.findAll('.view-toggle button')[1]
		await listToggle.trigger('click')
		expect(wrapper.find('.pipeline-board__list').exists()).toBe(true)
		expect(wrapper.find('.pipeline-board__columns').exists()).toBe(false)
	})
})
