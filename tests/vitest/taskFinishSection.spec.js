// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The finish card on the task page (board PqTaak): Log attempt and Finish post
 * the result to the callback endpoints and refresh the page; a finished task
 * shows no card.
 *
 * @spec openspec/specs/callback-management/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({ post: vi.fn() }))
const busMock = vi.hoisted(() => ({ emit: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/event-bus', () => busMock)
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', props: ['disabled'], emits: ['click'], render() { return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.()) } },
	NcTextArea: { name: 'NcTextArea', props: ['modelValue'], emits: ['update:modelValue'], render: () => h('textarea') },
}))

const { default: TaskFinishSection } = await import('../../src/components/TaskFinishSection.vue')

function mountSection(status = 'open') {
	return mount(TaskFinishSection, {
		props: { objectId: 'task-1', status },
		global: { mocks: { t: (app, text) => text } },
	})
}

describe('the finish card', () => {
	beforeEach(() => {
		axiosMock.post.mockReset().mockResolvedValue({ data: {} })
		busMock.emit.mockReset()
	})

	it('logs an attempt with the result and refreshes the page', async () => {
		const wrapper = mountSection()
		wrapper.findComponent({ name: 'NcTextArea' }).vm.$emit('update:modelValue', 'Geen gehoor')
		await flushPromises()
		await wrapper.find('[data-testid="task-finish-attempt"]').trigger('click')
		await flushPromises()
		expect(axiosMock.post).toHaveBeenCalledWith(
			'/index.php/apps/pipelinq/api/callbacks/task-1/attempts',
			{ result: 'Geen gehoor' },
		)
		expect(busMock.emit).toHaveBeenCalledWith('cn:page:refresh', {})
	})

	it('finishes the task with the result as its result text', async () => {
		const wrapper = mountSection('in_progress')
		wrapper.findComponent({ name: 'NcTextArea' }).vm.$emit('update:modelValue', 'Uitgelegd')
		await flushPromises()
		await wrapper.find('[data-testid="task-finish-complete"]').trigger('click')
		await flushPromises()
		expect(axiosMock.post).toHaveBeenCalledWith(
			'/index.php/apps/pipelinq/api/callbacks/task-1/complete',
			{ resultText: 'Uitgelegd' },
		)
	})

	it('shows nothing on a finished task', () => {
		expect(mountSection('completed').find('[data-testid="task-finish-section"]').exists()).toBe(false)
	})
})
