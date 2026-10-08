// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lead form has no probability input any more (openspec/changes/review-finish).
 * The forecast reads a lead's qualification score since
 * pipeline-numbers-tell-the-truth, so a probability typed here fed nothing.
 * Editing a lead that already has one keeps the stored value on save.
 *
 * @spec openspec/changes/review-finish/specs/lead-management/spec.md
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
		props: ['label', 'modelValue'],
		render() {
			return h('label', { 'data-label': this.label })
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

describe('LeadForm without a probability input', () => {
	it('renders no probability field', async () => {
		const wrapper = mountForm()
		await flushPromises()
		const labels = wrapper
			.findAll('[data-label]')
			.map((el) => el.attributes('data-label'))
		expect(labels).toContain('Value')
		expect(labels.some((label) => /probability/i.test(label))).toBe(false)
	})

	it('starts a new lead in the reporting currency, without a probability', async () => {
		const wrapper = mountForm()
		await flushPromises()
		expect(wrapper.vm.form.currency).toBe('USD')
		expect('probability' in wrapper.vm.form).toBe(false)
	})

	it('keeps a stored probability when an existing lead is saved', async () => {
		const wrapper = mountForm({
			lead: {
				id: 'l1',
				title: 'Tender',
				probability: 40,
				pipeline: 'p1',
				client: 'c1',
			},
		})
		await flushPromises()
		wrapper.vm.onSave()
		const saved = wrapper.emitted('save')?.[0]?.[0]
		expect(saved?.probability).toBe(40)
	})
})
