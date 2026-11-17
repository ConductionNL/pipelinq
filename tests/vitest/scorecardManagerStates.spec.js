// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The call scorecard section, mounted: the note on a failed load and with no
 * scorecards, the list it draws, and the editor form it opens for a new or a
 * stored scorecard, with axios doubled.
 *
 * @spec openspec/changes/contact-moments-call-review/specs/call-review/spec.md#requirement-a-reviewer-scores-a-call-and-writes-coaching-points-req-crv-002
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => params[k] ?? m) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/vue', async () => {
	const { h } = await import('vue')
	return {
		NcButton: {
			name: 'NcButton',
			props: ['type', 'disabled'],
			emits: ['click'],
			render() {
				return h('button', { type: this.type || 'button', disabled: this.disabled, onClick: () => this.$emit('click') }, [this.$slots.icon?.(), this.$slots.default?.()])
			},
		},
		NcCheckboxRadioSwitch: {
			name: 'NcCheckboxRadioSwitch',
			props: ['modelValue'],
			emits: ['update:modelValue'],
			render() {
				return h('label', { 'data-checked': String(this.modelValue) }, this.$slots.default?.())
			},
		},
		NcTextField: {
			name: 'NcTextField',
			props: ['modelValue', 'label'],
			emits: ['update:modelValue'],
			render() {
				return h('input', { 'aria-label': this.label, value: this.modelValue, onInput: (e) => this.$emit('update:modelValue', e.target.value) })
			},
		},
	}
})
vi.mock('@conduction/nextcloud-vue', async () => {
	const { h } = await import('vue')
	return {
		CnSettingsSection: {
			name: 'CnSettingsSection',
			props: ['name', 'description'],
			render() {
				return h('section', { 'data-name': this.name }, [this.$slots.actions?.(), this.$slots.default?.()])
			},
		},
	}
})

const translate = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)
const plural = (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count))
globalThis.t = translate
globalThis.n = plural

const { default: ScorecardManager } = await import('../../src/views/settings/ScorecardManager.vue')

/**
 * Mount the section and let its load settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function section() {
	const wrapper = mount(ScorecardManager, { global: { mocks: { t: translate, n: plural } } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	for (const fn of Object.values(http)) {
		fn.mockReset()
	}
})

describe('the call scorecard section', () => {
	it('says the scorecards could not be loaded', async () => {
		http.get.mockRejectedValue(new Error('down'))

		const wrapper = await section()

		expect(wrapper.find('[role="alert"]').text()).toBe('The scorecards could not be loaded.')
		expect(wrapper.find('ul').exists()).toBe(false)
	})

	it('invites a first scorecard when there is none, and the note goes once the form opens', async () => {
		http.get.mockResolvedValue({ data: { results: [] } })

		const wrapper = await section()
		expect(wrapper.find('.scorecard-manager__note').text()).toBe('No scorecard yet. Add one to start reviewing calls.')

		await wrapper.find('[data-testid="scorecard-new"]').trigger('click')

		expect(wrapper.find('.scorecard-manager__note').exists()).toBe(false)
		expect(wrapper.find('[data-testid="scorecard-form"]').exists()).toBe(true)
		expect(wrapper.findAll('.scorecard-manager__criterion')).toHaveLength(1)
	})

	it('lists each scorecard with its criteria count and whether it is active', async () => {
		http.get.mockResolvedValue({
			data: {
				results: [
					{ id: 's1', title: 'Calls', isActive: true, criteria: [{ key: 'a' }, { key: 'b' }] },
					{ '@self': { id: 's2' }, title: 'Old', isActive: false, criteria: [{ key: 'a' }] },
					{ id: 's3', title: 'Empty' },
				],
			},
		})

		const wrapper = await section()
		const items = wrapper.findAll('.scorecard-manager__item')

		expect(items.map((item) => item.find('.scorecard-manager__title').text())).toEqual(['Calls', 'Old', 'Empty'])
		expect(items.map((item) => item.find('.scorecard-manager__meta').text().replace(/\s+/g, ' '))).toEqual([
			'2 criteria · Active',
			'1 criterion · Not active',
			'0 criteria · Active',
		])
		expect(wrapper.find('[data-testid="scorecard-edit-s2"]').exists()).toBe(true)
	})

	it('opens a stored scorecard in the form, adds and removes criteria and cancels', async () => {
		http.get.mockResolvedValue({ data: { results: [{ id: 's1', title: 'Calls', isActive: false, criteria: [{ key: 'greeting', label: 'Greeting', weight: 2, scale: 4 }] }] } })

		const wrapper = await section()
		await wrapper.find('[data-testid="scorecard-edit-s1"]').trigger('click')

		const inputs = wrapper.find('[data-testid="scorecard-criterion-0"]').findAll('input')
		expect(inputs.map((input) => input.element.value)).toEqual(['Greeting', '2', '4'])
		expect(wrapper.find('[data-checked]').attributes('data-checked')).toBe('false')

		await wrapper.find('[data-testid="scorecard-add-criterion"]').trigger('click')
		expect(wrapper.findAll('.scorecard-manager__criterion')).toHaveLength(2)

		await wrapper.find('[data-testid="scorecard-remove-0"]').trigger('click')
		const left = wrapper.findAll('.scorecard-manager__criterion')
		expect(left).toHaveLength(1)
		expect(left[0].find('input').element.value).toBe('')

		const cancel = wrapper.findAll('.scorecard-manager__buttons button')[1]
		await cancel.trigger('click')
		expect(wrapper.find('[data-testid="scorecard-form"]').exists()).toBe(false)
	})

	it('lists what stops the save and posts nothing', async () => {
		http.get.mockResolvedValue({ data: { results: [] } })

		const wrapper = await section()
		await wrapper.find('[data-testid="scorecard-new"]').trigger('click')
		await wrapper.find('[data-testid="scorecard-form"]').trigger('submit')
		await flushPromises()

		const problems = wrapper.findAll('.scorecard-manager__problems li')
		expect(problems.length).toBeGreaterThan(0)
		expect(http.post).not.toHaveBeenCalled()
	})

	it('posts the typed scorecard and closes the form', async () => {
		http.get.mockResolvedValue({ data: { results: [] } })
		http.post.mockResolvedValue({ data: {} })

		const wrapper = await section()
		await wrapper.find('[data-testid="scorecard-new"]').trigger('click')
		await wrapper.find('[data-testid="scorecard-title"]').setValue('Calls')
		await wrapper.find('[data-testid="scorecard-criterion-0"] input').setValue('Greeting')
		await wrapper.find('[data-testid="scorecard-form"]').trigger('submit')
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/pipelinq/callScorecard', {
			title: 'Calls',
			isActive: true,
			criteria: [{ key: 'greeting', label: 'Greeting', weight: 1, scale: 5 }],
		})
		expect(wrapper.find('[data-testid="scorecard-form"]').exists()).toBe(false)
	})
})
