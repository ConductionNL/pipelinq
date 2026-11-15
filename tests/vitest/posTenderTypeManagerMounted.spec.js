// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The tender types section of the admin page: its name and description, the
 * tender type list inside it, and the title-row button that opens a new one
 * in that list.
 *
 * @spec openspec/specs/pos/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text) => String(text)
})

const createNew = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', emits: ['click'], render() { return h('button', { onClick: () => this.$emit('click') }, [this.$slots.icon?.(), this.$slots.default?.()]) } },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnSettingsSection: { name: 'CnSettingsSection', props: ['name', 'description'], render() { return h('section', [h('h2', this.name), h('p', this.description), this.$slots.actions?.(), this.$slots.default?.()]) } },
}))
vi.mock('../../src/views/pos/PosTenderTypeList.vue', () => ({
	default: { name: 'PosTenderTypeList', methods: { createNew }, render: () => h('div', { class: 'tender-list' }) },
}))

const { default: PosTenderTypeManager } = await import('../../src/views/settings/PosTenderTypeManager.vue')

describe('PosTenderTypeManager', () => {
	it('names the section, holds the list and opens a new tender type from the title row', async () => {
		const wrapper = mount(PosTenderTypeManager, { global: { mocks: { t: globalThis.t } } })

		expect(wrapper.find('h2').text()).toBe('Payment methods at the till')
		expect(wrapper.find('p').text()).toBe('How a customer can pay at the till, and the ledger account each payment method posts to.')
		expect(wrapper.find('.tender-list').exists()).toBe(true)
		expect(createNew).not.toHaveBeenCalled()

		await wrapper.find('button').trigger('click')

		expect(wrapper.find('button').text()).toBe('New tender type')
		expect(createNew).toHaveBeenCalledTimes(1)
	})
})
