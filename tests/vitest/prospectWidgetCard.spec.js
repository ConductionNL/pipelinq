// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The prospect discovery widget and its card: what each state shows, and the score bands.
 */
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'

const store = vi.hoisted(() => ({ current: null }))

vi.mock('../../src/store/modules/prospect.js', () => ({ useProspectStore: () => store.current }))
vi.mock('vue-material-design-icons/Refresh.vue', () => ({ default: { name: 'Refresh', render: () => null } }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent: define, h: render } = await import('vue')
	return {
		NcButton: define({ name: 'NcButton', emits: ['click'], render() {
			return render('button', { onClick: (e) => this.$emit('click', e) }, this.$slots.default?.())
		} }),
		NcLoadingIcon: define({ name: 'NcLoadingIcon', render: () => render('span', { class: 'loading' }) }),
	}
})

/**
 * The test translator: the English source with {placeholders} filled.
 *
 * @param {string} app The app.
 * @param {string} text The source.
 * @param {object} vars The placeholders.
 * @return {string} The text.
 */
function t(app, text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match))
}

const mocks = { t }
const { default: ProspectCard } = await import('../../src/components/ProspectCard.vue')
const { default: ProspectWidget } = await import('../../src/components/ProspectWidget.vue')

/**
 * A prospect.
 *
 * @param {object} overrides Fields to replace.
 * @return {object} The prospect.
 */
function prospect(overrides = {}) {
	return { kvkNumber: '12345678', tradeName: 'Bakkerij Jansen', fitScore: 82, source: 'KVK', ...overrides }
}

describe('ProspectCard', () => {
	it('bands the fit score: above 70 high, from 40 medium, else low', () => {
		const band = (fitScore) => mount(ProspectCard, { props: { prospect: prospect({ fitScore }) }, global: { mocks } }).vm.scoreClass
		expect(band(71)).toBe('score--high')
		expect(band(70)).toBe('score--medium')
		expect(band(40)).toBe('score--medium')
		expect(band(39)).toBe('score--low')
		expect(band(undefined)).toBe('score--low')
	})

	it('shows the optional details only when they are known', () => {
		const bare = mount(ProspectCard, { props: { prospect: prospect() }, global: { mocks } })
		expect(bare.findAll('.detail-label').map((l) => l.text())).toEqual(['KVK'])
		expect(bare.attributes('data-testid')).toBe('prospect-card-12345678')

		const full = mount(ProspectCard, {
			props: { prospect: prospect({ sbiCode: '1071', sbiDescription: 'Brood', employeeCount: 12, address: { city: 'Utrecht', province: 'Utrecht' } }) },
			global: { mocks },
		})
		expect(full.findAll('.detail-label').map((l) => l.text())).toEqual(['SBI', 'Employees', 'Location', 'KVK'])
		expect(full.text()).toContain('1071 — Brood')
		expect(full.text()).toContain('Utrecht, Utrecht')
		expect(full.find('.prospect-card__score').text()).toBe('82%')
	})
})

describe('ProspectWidget', () => {
	beforeEach(() => {
		store.current = reactive({ prospects: [], total: 0, loading: false, error: null, cachedAt: null, fetchProspects: vi.fn() })
	})

	/**
	 * Mount the widget, optionally expanded.
	 *
	 * @param {boolean} expanded Whether to open it.
	 * @return {Promise<object>} The wrapper.
	 */
	async function widget(expanded = true) {
		const w = mount(ProspectWidget, { global: { mocks } })
		if (expanded) {
			await w.find('.prospect-widget__expand-toggle').trigger('click')
		}
		return w
	}

	it('fetches from the cache on mount, and refreshes bypassing it', async () => {
		const w = await widget(false)
		expect(store.current.fetchProspects).toHaveBeenCalledWith()
		await w.vm.refresh()
		expect(store.current.fetchProspects).toHaveBeenLastCalledWith(true)
	})

	it('previews the top match while collapsed, and toggles open from the keyboard', async () => {
		store.current.prospects = [prospect(), prospect({ kvkNumber: '2', tradeName: 'Smid' })]
		store.current.total = 2
		const w = await widget(false)

		expect(w.find('.prospect-widget__preview').text()).toBe('Top match: Bakkerij Jansen')
		expect(w.find('.prospect-count').text()).toBe('2 found')
		// The expand control is a native <button>, so a keyboard Enter/Space is
		// delivered as a click; it carries aria-expanded for assistive tech.
		const toggle = w.find('.prospect-widget__expand-toggle')
		expect(toggle.attributes('aria-expanded')).toBe('false')
		await toggle.trigger('click')
		expect(w.vm.expanded).toBe(true)
		expect(w.find('.prospect-widget__expand-toggle').attributes('aria-expanded')).toBe('true')
		expect(w.find('.prospect-widget__preview').exists()).toBe(false)
	})

	it('lists at most ten prospects, with the cache time', async () => {
		store.current.prospects = Array.from({ length: 12 }, (_, i) => prospect({ kvkNumber: String(i) }))
		store.current.cachedAt = '2026-11-12T09:05:00Z'
		const w = await widget()

		expect(w.findAllComponents(ProspectCard)).toHaveLength(10)
		expect(w.find('.prospect-widget__cache-info').text()).toContain('Last updated:')
	})

	it('asks for an ICP, shows other errors with a retry, and says when nothing matched', async () => {
		store.current.error = 'No ICP configured'
		const w = await widget()
		expect(w.find('.prospect-widget__setup').exists()).toBe(true)

		store.current.error = 'KVK is unreachable'
		await w.vm.$nextTick()
		expect(w.find('.prospect-widget__error').text()).toContain('KVK is unreachable')
		await w.find('.prospect-widget__error button').trigger('click')
		expect(store.current.fetchProspects).toHaveBeenLastCalledWith(true)

		store.current.error = null
		await w.vm.$nextTick()
		expect(w.find('.prospect-widget__empty').exists()).toBe(true)

		store.current.loading = true
		await w.vm.$nextTick()
		expect(w.find('.loading').exists()).toBe(true)
	})

	it('formats the cache time as Dutch hours and minutes, and nothing for no time', async () => {
		const w = await widget(false)
		expect(w.vm.formatTime('')).toBe('')
		expect(w.vm.formatTime('2026-11-12T09:05:00')).toMatch(/^09:05$/)
	})
})
