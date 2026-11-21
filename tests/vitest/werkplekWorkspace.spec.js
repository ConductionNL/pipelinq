/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The werkplek widgets read the page's workspace bag from the injected
 * context: a ref's value, a plain object as is, and nothing for anything else.
 *
 * @spec openspec/specs/kcc-werkplek/spec.md
 */
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnResourceSelect: {} }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn(), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcCheckboxRadioSwitch: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/components/werkplek/WerkplekAgentStatus.vue', () => ({ default: {} }))
vi.mock('../../src/services/ctiApi.js', () => ({ screenPop: vi.fn() }))

const widgets = {}

beforeAll(async () => {
	globalThis.t = (_app, text) => text
	widgets.select = (await import('../../src/views/werkplek/widgets/WerkplekClientSelect.vue')).default
	widgets.header = (await import('../../src/views/werkplek/widgets/WerkplekHeaderActions.vue')).default
}, 60000)

const bag = { selectedClient: 'c1' }
const contexts = [
	[{ value: bag }, bag],
	[bag, bag],
	[{ value: null }, null],
	[null, null],
	['text', null],
	[42, null],
]

describe.each(['select', 'header'])('the %s widget', (name) => {
	it('injects the workspace context with no default', () => {
		const inject = Object.assign({}, ...(widgets[name].mixins || []).map((m) => m.inject || {}), widgets[name].inject)
		expect(inject.cnWorkspaceContext).toEqual({ default: null })
	})

	it('unwraps the workspace bag', () => {
		const computed = Object.assign({}, ...(widgets[name].mixins || []).map((m) => m.computed || {}), widgets[name].computed)
		for (const [cnWorkspaceContext, expected] of contexts) {
			expect(computed.workspace.call({ cnWorkspaceContext })).toBe(expected)
		}
	})
})
