// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The label picker the hand-written lead, client and article forms use: it
 * offers the shared label list, refuses a typed word, and keeps a label an
 * item already carries when the list no longer has it.
 *
 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#requirement-labels-are-one-shared-list-for-articles-leads-and-customers
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const storeMock = vi.hoisted(() => ({ fetchCollection: vi.fn() }))

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => storeMock,
}))
vi.mock('@nextcloud/vue', () => ({
	NcSelect: {
		name: 'NcSelect',
		inheritAttrs: false,
		props: {
			modelValue: Array,
			options: Array,
			inputLabel: String,
			multiple: Boolean,
			taggable: Boolean,
			placeholder: String,
		},
		emits: ['update:modelValue'],
		render() {
			return h('div', { 'data-stub': 'NcSelect' })
		},
	},
}))

const LabelSelect = (await import('../../src/components/LabelSelect.vue')).default
const { objectTypes } = await import('../../src/config/objectTypes.js')

/** Mount the picker holding some labels. */
async function mountIt(modelValue = []) {
	const wrapper = mount(LabelSelect, { props: { modelValue } })
	await flushPromises()
	return wrapper
}

describe('LabelSelect', () => {
	beforeEach(() => {
		storeMock.fetchCollection.mockReset()
		storeMock.fetchCollection.mockResolvedValue([
			{ id: 'l2', name: 'duurzaam' },
			{ id: 'l1', name: 'afval' },
			{ id: 'l3', name: 'ondernemers' },
		])
	})

	it('offers the shared list in alphabetical order and refuses a typed word', async () => {
		const wrapper = await mountIt()
		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(storeMock.fetchCollection).toHaveBeenCalledWith('label', { _limit: 500 })
		expect(select.props('options')).toEqual(['afval', 'duurzaam', 'ondernemers'])
		expect(select.props('multiple')).toBe(true)
		expect(select.props('taggable')).toBeFalsy()
		expect(select.props('inputLabel')).toBe('Labels')
	})

	it('keeps a label the item carries that the list no longer has', async () => {
		const wrapper = await mountIt(['parkeren', 'afval'])
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options')).toEqual(['afval', 'duurzaam', 'ondernemers', 'parkeren'])
	})

	it('hands the picked labels back as names', async () => {
		const wrapper = await mountIt(['afval'])
		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', ['afval', 'ondernemers'])
		expect(wrapper.emitted('update:modelValue')).toEqual([[['afval', 'ondernemers']]])
	})

	it('draws nothing while the list is empty and the item carries no label', async () => {
		storeMock.fetchCollection.mockResolvedValue([])
		const wrapper = await mountIt()
		expect(wrapper.find('[data-stub="NcSelect"]').exists()).toBe(false)
	})

	it('registers the label type in the store', () => {
		expect(objectTypes().map((type) => type.slug)).toContain('label')
	})
})

describe('the hand-written forms pick labels from the list', () => {
	const read = (path) => readFileSync(resolve(__dirname, '../../src', path), 'utf8')

	it('the lead form', () => {
		const source = read('views/leads/LeadForm.vue')
		expect(source).toContain('<LabelSelect')
		expect(source).toMatch(/v-model="form\.labels"/)
		expect(source).toMatch(/labels: Array\.isArray\(this\.lead\.labels\)/)
	})

	it('the client form', () => {
		const source = read('views/clients/ClientForm.vue')
		expect(source).toContain('<LabelSelect')
		expect(source).toMatch(/v-model="form\.labels"/)
	})

	it('the article editor, in place of the free-text tags', () => {
		const source = read('modals/ArticleEditModal.vue')
		expect(source).toContain('<LabelSelect')
		expect(source).toMatch(/v-model="tags"/)
		expect(source).not.toContain('tagsText')
	})
})
