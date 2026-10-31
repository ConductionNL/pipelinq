// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A freshly opened New lead form shows no "Title is required" or "Pipeline is
 * required" until the field was touched or a save was attempted
 * (pipelinq-audit-admin-forms-pos).
 *
 * A client is optional (decision 207, 12 October 2026): the form never asks
 * for one, and a lead saves without it.
 *
 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/client-forms/spec.md
 * @spec openspec/specs/lead-management/spec.md#requirement-lead-crud-mvp
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
		sourceNames: [],
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
		expect(wrapper.text()).toContain('Pipeline is required')
		expect(wrapper.text()).not.toContain('Client is required')
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

describe('LeadForm saves a lead without a client', () => {
	it('saves with a title and a pipeline, and sends no client', async () => {
		const wrapper = mountForm()
		await flushPromises()
		wrapper.vm.form.title = 'Website enquiry'
		wrapper.vm.form.pipeline = 'p1'
		await flushPromises()

		expect(wrapper.vm.isValid).toBeTruthy()
		wrapper.vm.onSave()
		await flushPromises()

		const saved = wrapper.emitted('save')
		expect(saved).toHaveLength(1)
		expect(saved[0][0]).toMatchObject({
			title: 'Website enquiry',
			pipeline: 'p1',
		})
		// No key at all: an empty client must not be written as null or ''.
		expect(Object.keys(saved[0][0])).not.toContain('client')
		expect(Object.keys(saved[0][0])).not.toContain('contact')
		expect(wrapper.findAll('.field-error')).toHaveLength(0)
	})

	it('tells the user the client may stay empty', async () => {
		const wrapper = mountForm()
		await flushPromises()
		expect(wrapper.find('[data-testid="lead-form-client-hint"]').text()).toBe(
			'Leave empty to save the lead without a client.',
		)
	})

	it('keeps a chosen client on the saved lead', async () => {
		const wrapper = mountForm()
		await flushPromises()
		wrapper.vm.form.title = 'Renewal'
		wrapper.vm.form.pipeline = 'p1'
		wrapper.vm.onClientChange('c1')
		wrapper.vm.onSave()
		await flushPromises()
		expect(wrapper.emitted('save')[0][0].client).toBe('c1')
	})
})
