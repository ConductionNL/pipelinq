// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The werkplek header in a call (board PqWerkplekKlant): "In gesprek" is the
 * status control as a menu that still sets the availability, the running
 * time is its own chip, and the call marks the workspace identified so the
 * line under the title carries "Geïdentificeerd".
 *
 * @spec openspec/specs/kcc-werkplek/spec.md#requirement-the-in-call-header-marks-the-call
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h, ref } from 'vue'

const http = vi.hoisted(() => ({ get: vi.fn(() => Promise.resolve({ data: { agentProfile: { isAvailable: true } } })), put: vi.fn(() => Promise.resolve({ data: {} })) }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../src/services/ctiApi.js', () => ({ screenPop: vi.fn() }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({ fetchObject: () => Promise.resolve({ name: 'Sanne de Vries' }) }) }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		emits: ['click'],
		render() {
			return h('button', { onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
	NcActions: {
		props: ['menuName'],
		render() {
			return h('div', { class: 'menu', 'data-name': this.menuName }, this.$slots.default?.())
		},
	},
	NcActionButton: {
		props: ['modelValue'],
		emits: ['click'],
		render() {
			return h('button', { class: 'menu-item', 'data-checked': String(this.modelValue), onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
}))

/**
 * Mount the header on a call address.
 *
 * @return {Promise<{w: object, workspace: object}>}
 */
async function mountInCall() {
	window.history.replaceState({}, '', '/apps/pipelinq/werkplek?client=c-1&call=active')
	const Header = (await import('../../src/views/werkplek/widgets/WerkplekHeaderActions.vue')).default
	const workspace = ref({})
	const w = mount(Header, {
		global: {
			provide: { cnWorkspaceContext: workspace },
			mixins: [{ methods: { t: (app, text) => text } }],
		},
	})
	await flushPromises()
	return { w, workspace }
}

describe('werkplek header in a call', () => {
	it('draws "In call" as a menu, the time as its own chip, and marks the call identified', async () => {
		const { w, workspace } = await mountInCall()
		expect(w.find('[data-testid="werkplek-call-state"]').attributes('data-name')).toBe('In call')
		expect(w.find('[data-testid="werkplek-call-timer"]').text()).toMatch(/^\d\d:\d\d$/)
		expect(workspace.value.callIdentified).toBe(true)
		await w.find('[data-testid="werkplek-call-end"]').trigger('click')
		expect(workspace.value.callIdentified).toBe(false)
		expect(w.find('[data-testid="werkplek-call-timer"]').exists()).toBe(false)
	})

	it('sets the availability from the in-call menu', async () => {
		const { w } = await mountInCall()
		const items = w.findAll('[data-testid="werkplek-call-state"] .menu-item')
		expect(items.map((item) => item.text())).toEqual(['Available', 'Unavailable'])
		expect(items[0].attributes('data-checked')).toBe('true')
		await items[1].trigger('click')
		await flushPromises()
		expect(http.put).toHaveBeenCalledWith('/apps/pipelinq/api/kcc-werkplek/availability', { isAvailable: false })
	})
})
