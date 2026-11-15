// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The call scorecard section as it renders: the load failure and empty
 * notes, the list of scorecards with their criteria count and state, and the
 * editor that opens on New and on Edit, adds and removes criteria, shows the
 * problems and cancels.
 *
 * @spec openspec/changes/contact-moments-call-review/specs/call-review/spec.md#requirement-a-reviewer-scores-a-call-and-writes-coaching-points-req-crv-002
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text) => String(text)
	globalThis.n = (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count))
})

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => params[k] ?? m) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['type', 'disabled', 'variant'],
		emits: ['click'],
		render() {
			return h('button', { type: this.type || 'button', disabled: this.disabled, onClick: () => this.$emit('click') }, [this.$slots.icon?.(), this.$slots.default?.()])
		},
	},
	NcCheckboxRadioSwitch: { name: 'NcCheckboxRadioSwitch', props: ['modelValue'], render() { return h('label', { class: 'switch', 'data-on': String(this.modelValue) }, this.$slots.default?.()) } },
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		render() {
			return h('input', { 'aria-label': this.label, value: this.modelValue, onInput: (e) => this.$emit('update:modelValue', e.target.value) })
		},
	},
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnSettingsSection: { name: 'CnSettingsSection', props: ['name', 'description'], render() { return h('section', [h('h2', this.name), this.$slots.actions?.(), this.$slots.default?.()]) } },
}))

const { default: ScorecardManager } = await import('../../src/views/settings/ScorecardManager.vue')

/**
 * Mount the section over a listing reply.
 *
 * @param {Promise<object>} reply What the GET resolves or rejects with.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountWith(reply) {
	http.get.mockReturnValue(reply)
	const wrapper = mount(ScorecardManager, { global: { mocks: { t: globalThis.t, n: globalThis.n } } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	for (const fn of Object.values(http)) {
		fn.mockReset()
	}
})

describe('ScorecardManager as it renders', () => {
	it('says so when the scorecards could not be loaded', async () => {
		const wrapper = await mountWith(Promise.reject(new Error('down')))

		expect(wrapper.find('[role="alert"]').text()).toBe('The scorecards could not be loaded.')
		expect(wrapper.find('.scorecard-manager__list').exists()).toBe(false)
	})

	it('invites a first scorecard when there is none, and the note goes once the editor opens', async () => {
		const wrapper = await mountWith(Promise.resolve({ data: { results: [] } }))
		expect(wrapper.text()).toContain('No scorecard yet. Add one to start reviewing calls.')

		await wrapper.find('[data-testid="scorecard-new"]').trigger('click')

		expect(wrapper.text()).not.toContain('No scorecard yet.')
		expect(wrapper.find('[data-testid="scorecard-form"]').exists()).toBe(true)
		expect(wrapper.findAll('[data-testid^="scorecard-criterion-"]')).toHaveLength(1)
		expect(wrapper.find('.switch').attributes('data-on')).toBe('true')
	})

	it('lists the scorecards with their criteria count and state', async () => {
		const wrapper = await mountWith(Promise.resolve({ data: { results: [
			{ id: 'a', title: 'Intake', isActive: true, criteria: [{ key: 'x', label: 'X' }] },
			{ '@self': { id: 'b' }, title: 'Escalation', isActive: false, criteria: [{ key: 'x' }, { key: 'y' }] },
		] } }))

		const items = wrapper.findAll('.scorecard-manager__item')
		expect(items).toHaveLength(2)
		expect(items[0].find('.scorecard-manager__meta').text()).toBe('1 criterion · Active')
		expect(items[1].find('.scorecard-manager__meta').text()).toBe('2 criteria · Not active')
		expect(wrapper.find('[data-testid="scorecard-edit-b"]').exists()).toBe(true)
	})

	it('edits a stored scorecard: adds and removes criteria, shows the problems and cancels', async () => {
		const wrapper = await mountWith(Promise.resolve({ data: { results: [
			{ id: 'a', title: 'Intake', isActive: false, criteria: [{ key: 'greet', label: 'Greeting', weight: 2, scale: 5 }] },
		] } }))

		await wrapper.find('[data-testid="scorecard-edit-a"]').trigger('click')
		expect(wrapper.find('[data-testid="scorecard-title"]').element.value).toBe('Intake')
		expect(wrapper.find('.switch').attributes('data-on')).toBe('false')

		await wrapper.find('[data-testid="scorecard-add-criterion"]').trigger('click')
		expect(wrapper.findAll('[data-testid^="scorecard-criterion-"]')).toHaveLength(2)
		await wrapper.find('[data-testid="scorecard-remove-0"]').trigger('click')
		await wrapper.find('[data-testid="scorecard-remove-0"]').trigger('click')
		expect(wrapper.findAll('[data-testid^="scorecard-criterion-"]')).toHaveLength(0)

		await wrapper.find('[data-testid="scorecard-title"]').setValue('  ')
		await wrapper.find('[data-testid="scorecard-form"]').trigger('submit')
		await flushPromises()
		expect(http.put).not.toHaveBeenCalled()
		expect(wrapper.findAll('.scorecard-manager__problems li').length).toBeGreaterThan(0)

		const cancel = wrapper.findAll('button').find((button) => button.text() === 'Cancel')
		await cancel.trigger('click')
		expect(wrapper.find('[data-testid="scorecard-form"]').exists()).toBe(false)
	})
})
