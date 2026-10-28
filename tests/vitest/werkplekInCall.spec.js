// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The werkplek's in-call state (board PqWerkplekKlant). The header reads the
 * call from the address, `client=<id>&call=active` or `inCallPreview=<number>`
 * (looked up like a real call), and writes the client in focus and the call
 * flag into the page workspace; the dashboard cards hide on that flag and the
 * client-bound cards stay. Ending the call clears the flag.
 *
 * @spec openspec/specs/kcc-werkplek/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { h, ref } from 'vue'

const cti = vi.hoisted(() => ({ screenPop: vi.fn() }))
vi.mock('../../src/services/ctiApi.js', () => cti)
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: {} })) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		emits: ['click'],
		render() {
			return h('button', { onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
}))
const store = vi.hoisted(() => ({ fetchObject: vi.fn(() => Promise.resolve({ id: 'c-1', name: 'Sanne de Vries' })) }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('../../src/components/werkplek/WerkplekAgentStatus.vue', () => ({ default: { render: () => h('span', { class: 'agent-status' }) } }))

let Header

beforeAll(async () => {
	Header = (await import('../../src/views/werkplek/widgets/WerkplekHeaderActions.vue')).default
})

beforeEach(() => {
	cti.screenPop.mockReset()
})

/**
 * Mount the header on an address, with a workspace bag.
 *
 * @param {string} search The query string.
 * @return {Promise<{w: object, workspace: object}>}
 */
async function mountOn(search) {
	window.history.replaceState({}, '', '/apps/pipelinq/werkplek' + search)
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

describe('werkplek in-call state', () => {
	it('starts a call for the client the address names', async () => {
		const { w, workspace } = await mountOn('?client=c-1&call=active')
		expect(workspace.value.selectedClient).toBe('c-1')
		expect(workspace.value.callActive).toBe(true)
		// The page title reads "Gesprek met Sanne de Vries" (PqWerkplekKlant).
		expect(workspace.value.selectedClientName).toBe('Sanne de Vries')
		expect(w.find('[data-testid="werkplek-call-state"]').exists()).toBe(true)
	})

	it('looks a previewed number up as a real call would, and only reads', async () => {
		cti.screenPop.mockResolvedValue({ matches: [{ _matchType: 'contact', id: 'p-1' }, { _matchType: 'client', id: 'c-9', name: 'Henk Bakker' }] })
		const { workspace } = await mountOn('?inCallPreview=0612345678')
		expect(cti.screenPop).toHaveBeenCalledWith('0612345678')
		expect(workspace.value.selectedClient).toBe('c-9')
		expect(workspace.value.selectedClientName).toBe('Henk Bakker')
		expect(workspace.value.callActive).toBe(true)
	})

	it('stays the dashboard without a call, and ends a call on request', async () => {
		const idle = await mountOn('')
		expect(idle.workspace.value.callActive).toBeUndefined()
		expect(idle.w.find('.agent-status').exists()).toBe(true)
		const { w, workspace } = await mountOn('?client=c-1&call=active')
		await w.find('[data-testid="werkplek-call-end"]').trigger('click')
		expect(workspace.value.callActive).toBe(false)
	})
})
