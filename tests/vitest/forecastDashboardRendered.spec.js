// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The forecast dashboard as it renders: the tiles, the quarter table's cells
 * (amounts, the "was" line and the override badge or button), the notes for
 * overridden commits and exchange rates, the at-risk warning, the empty state
 * and the override modal's round trip.
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	globalThis.n = (app, one, many, count, vars = {}) => globalThis.t(app, count === 1 ? one : many, vars).replace('%n', String(count))
})

vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent: define, h } = await import('vue')
	const slotted = (name, tag = 'div') => define({
		name,
		props: ['title', 'description'],
		setup(props, { slots, attrs }) {
			return () => h(tag, { class: attrs.class, 'data-title': props.title }, [
				slots.extra?.(),
				slots.actions?.(),
				slots.default?.(),
			])
		},
	})
	return {
		CnBuildiqEditButton: define({ name: 'CnBuildiqEditButton', render: () => null }),
		CnDetailCard: slotted('CnDetailCard', 'section'),
		CnPageHeader: slotted('CnPageHeader', 'header'),
		CnSegmentedControl: define({ name: 'CnSegmentedControl', props: ['modelValue', 'options'], emits: ['update:modelValue'], render: () => null }),
		CnDataTable: define({
			name: 'CnDataTable',
			props: ['columns', 'rows', 'rowKey'],
			setup(props, { slots }) {
				return () => h('table', props.rows.map((row) => h(
					'tr',
					{ key: row[props.rowKey], 'data-row': row[props.rowKey] },
					props.columns.map((column) => h('td', { 'data-column': column.key }, slots['column-' + column.key]?.({ row }))),
				)))
			},
		}),
	}
})
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent: define, h } = await import('vue')
	return {
		NcButton: define({
			name: 'NcButton',
			emits: ['click'],
			setup(_props, { emit, slots, attrs }) {
				return () => h('button', { 'data-testid': attrs['data-testid'], onClick: () => emit('click') }, [slots.icon?.(), slots.default?.()])
			},
		}),
		NcEmptyContent: define({
			name: 'NcEmptyContent',
			props: ['name', 'description'],
			setup(props) {
				return () => h('div', { class: 'empty' }, `${props.name} ${props.description}`)
			},
		}),
		NcLoadingIcon: define({ name: 'NcLoadingIcon', render: () => null }),
		NcNoteCard: define({
			name: 'NcNoteCard',
			setup(_props, { slots }) {
				return () => h('div', { class: 'note-card' }, slots.default?.())
			},
		}),
	}
})
vi.mock('vue-material-design-icons/Download.vue', () => ({ default: { name: 'Download', render: () => null } }))
vi.mock('vue-material-design-icons/Pencil.vue', () => ({ default: { name: 'Pencil', render: () => null } }))
vi.mock('../../src/modals/ForecastOverrideModal.vue', async () => {
	const { defineComponent: define, h } = await import('vue')
	return {
		default: define({
			name: 'ForecastOverrideModal',
			props: ['periodId', 'ownerId', 'level', 'category', 'currentAmount'],
			emits: ['close', 'saved'],
			setup: () => () => h('div', { class: 'override-modal' }),
		}),
	}
})
vi.mock('../../src/views/forecast/ForecastTrend.vue', () => ({ default: { name: 'ForecastTrend', render: () => null } }))
vi.mock('../../src/services/forecastApi.js', () => ({ csvExportUrl: () => '/export', fetchSnapshots: vi.fn() }))
vi.mock('../../src/services/userDisplayName.js', () => ({ fetchUserDisplayName: vi.fn() }))

const forecastApi = await import('../../src/services/forecastApi.js')
const users = await import('../../src/services/userDisplayName.js')
const { formatEur } = await import('../../src/services/commercialFormat.js')
const { default: ForecastDashboard } = await import('../../src/views/forecast/ForecastDashboard.vue')

const snapshots = [
	{ owner_id: 'ann', as_of_date: '2026-05-04', commit: 400, best_case: 200, pipeline: 900, closed_won: 100, quota: null, currency: 'EUR', exchange_rates: { USD: { rate: 0.9, source: 'live', rateDate: null, stale: true } } },
	{ owner_id: 'bob', as_of_date: '2026-05-05', commit: 100, best_case: 0, pipeline: 100, closed_won: 0, quota: 1000, commit_override_reason: 'Deal slipped', original_commit: 300, currency: 'EUR' },
]

/**
 * Mount the dashboard and let it load.
 *
 * @return {Promise<object>} The wrapper.
 */
async function render() {
	const wrapper = mount(ForecastDashboard, { global: { mocks: { t: globalThis.t, n: globalThis.n } } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	vi.useFakeTimers({ now: new Date(2026, 4, 10, 12), toFake: ['Date'] })
	vi.clearAllMocks()
	forecastApi.fetchSnapshots.mockResolvedValue({ snapshots })
	users.fetchUserDisplayName.mockImplementation(async (id) => (id === 'ann' ? 'Ann de Vries' : id))
})

afterEach(() => {
	vi.useRealTimers()
})

describe('ForecastDashboard rendered', () => {
	it('draws the four tiles with their labels, values and the gap caption', async () => {
		const wrapper = await render()

		const labels = wrapper.findAll('.forecast-tile__label').map((node) => node.text())
		expect(labels).toHaveLength(4)
		const values = wrapper.findAll('.forecast-tile__value')
		expect(values.map((node) => node.text())).toEqual(wrapper.vm.tiles.map((tile) => tile.value))
		expect(wrapper.find('.forecast-tile__value--gap').exists()).toBe(true)
		expect(wrapper.find('.forecast-tile__caption').text()).toBe(wrapper.vm.tiles.find((tile) => tile.caption).caption)
		expect(wrapper.find('.forecast-progress__label').text()).toBe(wrapper.vm.progressLabel)
		expect(wrapper.find('[data-testid="forecast-download"]').text()).toBe('Download')
		expect(wrapper.find('[data-testid="forecast-export-csv"]').text()).toBe('Export CSV')
	})

	it('fills each quarter cell, with a dash for a missing quota', async () => {
		const wrapper = await render()

		const ann = wrapper.find('[data-row="ann"]')
		expect(ann.find('[data-testid="forecast-owner"]').text()).toBe('Ann de Vries')
		expect(ann.find('[data-column="commit"]').text()).toBe(formatEur(400))
		expect(ann.find('[data-column="best_case"]').text()).toBe(formatEur(200))
		expect(ann.find('[data-column="pipeline"]').text()).toBe(formatEur(900))
		expect(ann.find('[data-column="closed_won"]').text()).toBe(formatEur(100))
		expect(ann.find('[data-column="quota"]').text()).toBe('—')
		expect(ann.find('[data-column="override"] button').text()).toBe('Override')
		expect(ann.find('[data-testid="forecast-was"]').exists()).toBe(false)

		const bob = wrapper.find('[data-row="bob"]')
		expect(bob.find('[data-column="quota"]').text()).toBe(formatEur(1000))
		expect(bob.find('[data-testid="forecast-was"]').text()).toBe(`was ${formatEur(300)}`)
		const badge = bob.find('[data-testid="forecast-overridden"]')
		expect(badge.text()).toBe('Overridden')
		expect(badge.attributes('title')).toBe(`Rep submitted: ${formatEur(300)} — Deal slipped`)
		expect(bob.find('[data-column="override"] button').exists()).toBe(false)
	})

	it('notes the overridden commit and each exchange rate, flagging a stale one', async () => {
		const wrapper = await render()

		const notes = wrapper.findAll('[data-testid="forecast-override-note"]')
		expect(notes.map((node) => node.text())).toEqual([`bob: the seller gave ${formatEur(300)}, Deal slipped`])
		const rates = wrapper.findAll('[data-testid="forecast-rate-line"]')
		expect(rates).toHaveLength(1)
		expect(rates[0].text()).toMatch(/1 USD = 0[.,]9 EUR, date unknown/)
		expect(rates[0].find('.forecast-table__stale').text()).toBe('Rate is out of date')
	})

	it('shows the at-risk warning once the quarter is nearly over', async () => {
		const wrapper = await render()
		expect(wrapper.find('.note-card').exists()).toBe(false)

		wrapper.vm.daysRemaining = 5
		await flushPromises()

		expect(wrapper.find('.note-card').text()).toBe(wrapper.vm.atRiskMessage)
	})

	it('opens the override for the row, closes it, and has no snapshot date for an unreadable one', async () => {
		const wrapper = await render()

		await wrapper.find('[data-row="ann"] [data-column="override"] button').trigger('click')
		const modal = wrapper.findComponent({ name: 'ForecastOverrideModal' })
		expect(modal.props()).toMatchObject({ periodId: 'Q2-2026', ownerId: 'ann', level: 'rep', category: 'commit', currentAmount: 400 })
		modal.vm.$emit('close')
		await flushPromises()
		expect(wrapper.find('.override-modal').exists()).toBe(false)

		forecastApi.fetchSnapshots.mockResolvedValueOnce({ snapshots: [{ owner_id: 'x', as_of_date: 'not-a-date', commit: 1 }] })
		await wrapper.vm.loadSnapshots()
		expect(wrapper.vm.snapshotDate).toBe('')
	})

	it('shows the empty state without snapshots', async () => {
		forecastApi.fetchSnapshots.mockResolvedValue({ snapshots: [] })
		const wrapper = await render()

		expect(wrapper.find('.empty').text()).toContain('No snapshots yet')
		expect(wrapper.find('table').exists()).toBe(false)
	})
})
