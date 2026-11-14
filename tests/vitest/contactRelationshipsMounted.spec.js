// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The relationships card as mounted: the empty, compact and grouped views,
 * the ended/active status, row navigation, and the add, edit, remove and
 * search interactions wired to the object store.
 *
 * @spec openspec/specs/contact-relationships/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => dialogs)
const navigation = vi.hoisted(() => ({ openRowTarget: vi.fn() }))
vi.mock('@conduction/nextcloud-vue', () => navigation)
const holder = vi.hoisted(() => ({ store: null }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => holder.store }))
vi.mock('../../src/dialogs/RemoveRelationshipDialog.vue', () => ({
	default: { name: 'RemoveRelationshipDialog', emits: ['close', 'confirm'], render: () => null },
}))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const slotted = (name, tag) => defineComponent({
		name,
		setup(props, { slots }) {
			return () => h(tag, [slots.icon ? slots.icon() : null, slots.default ? slots.default() : null])
		},
	})
	return {
		NcButton: slotted('NcButton', 'button'),
		NcLoadingIcon: slotted('NcLoadingIcon', 'span'),
		NcEmptyContent: defineComponent({
			name: 'NcEmptyContent',
			props: { description: { type: String, default: '' } },
			setup(props) {
				return () => h('div', { class: 'empty-stub' }, props.description)
			},
		}),
		NcSelect: defineComponent({
			name: 'NcSelect',
			props: { modelValue: { default: null }, ariaLabelCombobox: { type: String, default: '' } },
			emits: ['update:modelValue', 'search'],
			setup() {
				return () => h('div', { class: 'select-stub' })
			},
		}),
	}
})

let ContactRelationships

beforeAll(async () => {
	globalThis.t = (app, text) => text
	ContactRelationships = (await import('../../src/components/ContactRelationships.vue')).default
}, 60000)

/**
 * An object store double answering relationships, names and searches.
 *
 * @param {Array<object>} relationships The relationships of this entity.
 * @return {object} The store.
 */
function makeStore(relationships) {
	return {
		fetchCollection: vi.fn(async (type, params) => {
			if (type === 'relationship' && params.fromContact === 'me') return relationships
			if (type === 'relationship') return [{ id: 'inv-1' }]
			if (type === 'contact') return [{ id: 'c9', name: 'Jan' }, { id: 'me', name: 'Self' }]
			return [{ id: 'k9', name: 'Gemeente' }]
		}),
		fetchObject: vi.fn(async (type, id) => ({ id, name: 'Name ' + id })),
		saveObject: vi.fn(async () => ({})),
		deleteObject: vi.fn(async () => true),
	}
}

/**
 * Mount the card and let its fetch settle.
 *
 * @param {object} props Extra props.
 * @return {Promise<object>} The wrapper.
 */
async function mountCard(props = {}) {
	const wrapper = mount(ContactRelationships, {
		props: { entityId: 'me', entityType: 'contact', entityName: 'Mij', ...props },
		global: {
			mocks: { t: (app, text) => text, $router: { push: vi.fn() } },
			stubs: { Plus: true },
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * The button with the given text.
 *
 * @param {object} wrapper The wrapper to search.
 * @param {string} text The button text.
 * @return {object} The button.
 */
function button(wrapper, text) {
	const found = wrapper.findAll('button').find((b) => b.text() === text)
	expect(found, text).toBeDefined()
	return found
}

function rels () {
  return [
	{ id: 'r1', toContact: 'c1', toType: 'contact', type: 'partner', category: 'Familie' },
	{ id: 'r2', toContact: 'k1', toType: 'client', type: 'werkgever', category: 'Professioneel', endDate: '2000-01-01', notes: 'oud', strength: 'weak' },
	{ id: 'r3', toContact: 'me', fromContact: 'k1', type: 'werknemer' },
]
}

beforeEach(() => {
	dialogs.showError.mockReset()
	dialogs.showSuccess.mockReset()
	navigation.openRowTarget.mockReset()
})

afterEach(() => {
	vi.useRealTimers()
})

describe('ContactRelationships mounted', () => {
	it('shows the empty state', async () => {
		holder.store = makeStore([])
		const wrapper = await mountCard()
		expect(wrapper.find('.empty-stub').text()).toBe('No relationships yet.')
	})

	it('lists type and resolved name in compact mode', async () => {
		holder.store = makeStore(rels().slice(0, 1))
		const wrapper = await mountCard({ compact: true })
		expect(wrapper.find('[data-testid="relationships-compact"]').text()).toBe('partnerName c1')
	})

	it('groups relationships, marks ended ones and navigates to the related entity', async () => {
		holder.store = makeStore(rels())
		const wrapper = await mountCard()

		expect(wrapper.findAll('.relationship-group__title').map((h) => h.text())).toEqual(['Familie', 'Professioneel', 'Other'])
		const rows = wrapper.findAll('tbody tr')
		expect(rows[0].text()).toContain('Name c1')
		expect(rows[0].find('.relationship-status').text()).toBe('Active')
		expect(rows[1].find('.relationship-status').text()).toBe('Ended')
		expect(rows[1].classes()).toContain('relationship--ended')

		await rows[1].trigger('click')
		await rows[0].trigger('auxclick')
		expect(navigation.openRowTarget).toHaveBeenNthCalledWith(1, expect.anything(), { name: 'ClientDetail', params: { id: 'k1' } }, expect.anything())
		expect(navigation.openRowTarget).toHaveBeenNthCalledWith(2, expect.anything(), { name: 'ContactDetail', params: { id: 'c1' } }, expect.anything())
	})

	it('adds a relationship: search, pick, type and save', async () => {
		vi.useFakeTimers()
		holder.store = makeStore([])
		const wrapper = await mountCard()

		await wrapper.find('[data-testid="relationships-add"]').trigger('click')
		expect(wrapper.find('.create-dialog h3').text()).toBe('Add relationship')
		const [entity, type, strength] = wrapper.findAllComponents({ name: 'NcSelect' })

		entity.vm.$emit('search', 'J')
		expect(wrapper.vm.entityOptions).toEqual([])
		entity.vm.$emit('search', 'Ja')
		entity.vm.$emit('search', 'Jan')
		await vi.advanceTimersByTimeAsync(300)
		expect(wrapper.vm.entityOptions).toEqual([
			{ id: 'c9', name: 'Jan (contact)', entityType: 'contact' },
			{ id: 'k9', name: 'Gemeente (client)', entityType: 'client' },
		])
		expect(entity.vm.$attrs.reduce({ id: 'k9' })).toBe('k9')
		expect(type.vm.$attrs.reduce({ value: 'ouder' })).toBe('ouder')
		expect(strength.vm.$attrs.reduce({ value: 'weak' })).toBe('weak')

		entity.vm.$emit('update:modelValue', 'k9')
		type.vm.$emit('update:modelValue', 'werkgever')
		strength.vm.$emit('update:modelValue', 'strong')
		await wrapper.find('#contact-relationship-notes').setValue('baas')
		await wrapper.find('#contact-relationship-start-date').setValue('2026-01-01')
		await wrapper.find('#contact-relationship-end-date').setValue('2026-12-31')
		expect(wrapper.vm.addForm).toMatchObject({
			toContact: 'k9', type: 'werkgever', strength: 'strong', notes: 'baas', startDate: '2026-01-01', endDate: '2026-12-31',
			_inverse: 'werknemer', _category: 'Professioneel', _symmetric: false,
		})

		await button(wrapper, 'Add').trigger('click')
		await flushPromises()
		expect(holder.store.saveObject).toHaveBeenCalledTimes(2)
		expect(holder.store.saveObject.mock.calls[0][0]).toBe('relationship')
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Relationship saved')
		expect(wrapper.find('.create-dialog').exists()).toBe(false)
	})

	it('drops search results when the search fails', async () => {
		vi.useFakeTimers()
		holder.store = makeStore([])
		const wrapper = await mountCard()
		await wrapper.find('[data-testid="relationships-add"]').trigger('click')
		holder.store.fetchCollection.mockRejectedValue(new Error('down'))
		wrapper.vm.entityOptions = [{ id: 'old' }]

		wrapper.findAllComponents({ name: 'NcSelect' })[0].vm.$emit('search', 'Jan')
		await vi.advanceTimersByTimeAsync(300)
		expect(wrapper.vm.entityOptions).toEqual([])
	})

	it('edits a relationship with its inverse and closes through the header and cancel', async () => {
		holder.store = makeStore(rels())
		const wrapper = await mountCard()

		await button(wrapper.findAll('tbody tr')[1], 'Edit').trigger('click')
		expect(wrapper.find('.create-dialog h3').text()).toBe('Edit relationship')
		expect(wrapper.vm.editingRelationship._inverseId).toBe('r3')
		expect(wrapper.vm.addForm).toEqual({ toContact: 'k1', type: 'werkgever', notes: 'oud', startDate: '', endDate: '2000-01-01', strength: 'weak' })
		expect(wrapper.findAllComponents({ name: 'NcSelect' })).toHaveLength(2)

		await button(wrapper, '×').trigger('click')
		expect(wrapper.find('.create-dialog').exists()).toBe(false)

		await button(wrapper.findAll('tbody tr')[0], 'Edit').trigger('click')
		expect(wrapper.vm.addForm.strength).toBe('medium')
		await button(wrapper, 'Cancel').trigger('click')
		expect(wrapper.find('.create-dialog').exists()).toBe(false)
	})

	it('removes a relationship and its inverse after confirmation', async () => {
		holder.store = makeStore(rels())
		const wrapper = await mountCard()

		await button(wrapper.findAll('tbody tr')[0], 'Remove').trigger('click')
		const dialog = wrapper.findComponent({ name: 'RemoveRelationshipDialog' })
		expect(dialog.exists()).toBe(true)
		dialog.vm.$emit('close')
		await flushPromises()
		expect(wrapper.findComponent({ name: 'RemoveRelationshipDialog' }).exists()).toBe(false)

		await button(wrapper.findAll('tbody tr')[0], 'Remove').trigger('click')
		wrapper.findComponent({ name: 'RemoveRelationshipDialog' }).vm.$emit('confirm')
		await flushPromises()
		expect(holder.store.deleteObject.mock.calls).toEqual([['relationship', 'r1'], ['relationship', 'inv-1']])
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Relationship removed')
	})
})
