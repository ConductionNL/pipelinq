// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Once the agent's availability is saved, the dashboard header line that
 * says "je bent beschikbaar ..." is asked to read it again
 * (`cn:dashboard:description-refresh`); a failed save asks nothing.
 *
 * @spec openspec/changes/kcc-werkplek/tasks.md#task-3.1
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const bus = vi.hoisted(() => ({ emit: vi.fn() }))
const http = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: bus.emit, subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {} }))
vi.mock('../../src/services/ctiApi.js', () => ({ screenPop: vi.fn() }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

const { default: WerkplekHeaderActions } = await import('../../src/views/werkplek/widgets/WerkplekHeaderActions.vue')
const { default: WerkplekAgentStatus } = await import('../../src/components/werkplek/WerkplekAgentStatus.vue')

describe('the availability toggle and the header line', () => {
	beforeEach(() => {
		bus.emit.mockReset()
		http.get.mockResolvedValue({ data: { agentProfile: { isAvailable: false } } })
	})

	it('asks the header to read its line again after a saved toggle, not after a failed one', async () => {
		const global = { mocks: { t: (_app, text) => text }, stubs: { NcButton: { template: '<button @click="$emit(\'click\')"><slot /></button>' }, NcCheckboxRadioSwitch: true } }
		http.put.mockResolvedValueOnce({ data: {} })
		const wrapper = mount(WerkplekHeaderActions, { global })
		await flushPromises()
		await wrapper.findComponent(WerkplekAgentStatus).vm.toggle()
		await flushPromises()
		expect(bus.emit).toHaveBeenCalledWith('cn:dashboard:description-refresh', {})

		bus.emit.mockReset()
		http.put.mockRejectedValueOnce(new Error('down'))
		await wrapper.findComponent(WerkplekAgentStatus).vm.toggle()
		await flushPromises()
		expect(bus.emit).not.toHaveBeenCalledWith('cn:dashboard:description-refresh', {})
	})
})
