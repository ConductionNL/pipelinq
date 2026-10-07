// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A freshly opened New lead form shows no "Title is required" or "Client is
 * required" until the field was touched or a save was attempted
 * (pipelinq-audit-admin-forms-pos).
 *
 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/client-forms/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const storeMock = vi.hoisted(() => ({
	fetchCollection: vi.fn(async () => []),
	collections: { pipeline: [] },
	objects: {},
}))

vi.mock('@nextcloud/initial-state', () => ({
	loadState: () => ({ currency: 'USD' }),
}))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => storeMock,
}))
vi.mock('../../src/store/modules/leadSources.js', () => ({
	useLeadSourcesStore: () => ({
		fetchSources: vi.fn(async () => []),
		sources: [],
	}),
}))
vi.mock('../../src/dialogs/ClientCreateDialog.vue', () => ({
	default: { name: 'ClientCreateDialog', render: () => null },
}))
vi.mock('../../src/dialogs/ContactCreateDialog.vue', () => ({
	default: { name: 'ContactCreateDialog', render: () => null },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnResourceSelect: { name: 'CnResourceSelect', render: () => null },
}))
vi.mock('@nextcloud/vue', () => {
	const field = (name) => ({
		name,
		props: ['label', 'modelValue', 'helperText'],
		render() {
			return h('label', {
				'data-label': this.label,
				'data-helper': this.helperText || '',
			})
		},
	})
	return {
		NcButton: {
			name: 'NcButton',
			render() {
				return h('button', this.$slots.default?.())
			},
		},
		NcDateTimePickerNative: field('NcDateTimePickerNative'),
		NcSelect: field('NcSelect'),
		NcTextField: field('NcTextField'),
	}
})

globalThis.t = (_app, text) => text
globalThis.n = (_app, s) => s

const { default: LeadForm } = await import('../../src/views/leads/LeadForm.vue')

function mountForm(props = {}) {
	return mount(LeadForm, {
		props,
		global: { mocks: { t: (_app, text) => text, n: (_app, s) => s } },
	})
}

describe('LeadForm errors wait for the user', () => {
	const titleHelper = (wrapper) =>
		wrapper.find('[data-label="Title"]').attributes('data-helper')

	it('shows no required errors when it opens', async () => {
		const wrapper = mountForm()
		await flushPromises()
		expect(titleHelper(wrapper)).toBe('')
		expect(wrapper.findAll('.field-error')).toHaveLength(0)
	})

	it('shows them after a save attempt', async () => {
		const wrapper = mountForm()
		await flushPromises()
		wrapper.vm.onSave()
		await flushPromises()
		expect(titleHelper(wrapper)).toBe('Title is required')
		expect(wrapper.text()).toContain('Client is required')
		expect(wrapper.emitted('save')).toBeUndefined()
	})

	it('shows the title error once the title was typed and cleared', async () => {
		const wrapper = mountForm()
		await flushPromises()
		wrapper.vm.form.title = 'x'
		await flushPromises()
		wrapper.vm.form.title = ''
		await flushPromises()
		expect(titleHelper(wrapper)).toBe('Title is required')
	})
})
