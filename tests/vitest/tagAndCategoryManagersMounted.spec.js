// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The tag manager and the product category manager, mounted: adding,
 * renaming and removing through their inline forms, the duplicate-name
 * refusal, and the error each failure leaves on screen.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? String(vars[key]) : match))

vi.mock('@conduction/nextcloud-vue', () => ({
	CnSettingsSection: {
		name: 'CnSettingsSection',
		props: ['name'],
		render() {
			return h('section', { 'data-name': this.name }, [h('header', this.$slots.actions?.()), this.$slots.default?.()])
		},
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		inheritAttrs: false,
		props: ['variant', 'disabled'],
		emits: ['click'],
		render() {
			return h('button', { class: 'nc-button', disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('i', { 'data-loading': true }) },
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		render() {
			return h('div', { class: 'note' }, this.$slots.default?.())
		},
	},
}))
vi.mock('vue-material-design-icons/Check.vue', () => ({ default: { render: () => null } }))
vi.mock('vue-material-design-icons/Close.vue', () => ({ default: { render: () => null } }))
vi.mock('vue-material-design-icons/Plus.vue', () => ({ default: { render: () => null } }))
const store = { fetchCollection: vi.fn(), saveObject: vi.fn(), deleteObject: vi.fn() }
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))

let TagManager
let ProductCategoryManager

beforeAll(async () => {
	globalThis.t = t
	TagManager = (await import('../../src/views/settings/TagManager.vue')).default
	ProductCategoryManager = (await import('../../src/views/settings/ProductCategoryManager.vue')).default
})

beforeEach(() => {
	vi.clearAllMocks()
	globalThis.confirm = vi.fn(() => true)
})

afterEach(() => {
	delete globalThis.confirm
})

const tags = [{ id: 1, name: 'hot' }, { id: 2, name: 'cold' }]

/**
 * @param {object} props TagManager props and listeners.
 * @return {object} The mounted manager.
 */
function tagManager(props = {}) {
	return mount(TagManager, { props: { title: 'Lead sources', tags, ...props }, global: { mocks: { t } }, attachTo: document.body })
}

describe('TagManager', () => {
	it('shows a loader, an empty state, or the chips with their labels', () => {
		expect(tagManager({ loading: true }).find('[data-loading]').exists()).toBe(true)
		expect(tagManager({ tags: [] }).text()).toContain('No items configured yet.')

		const wrapper = tagManager({ tagLabel: (name) => name.toUpperCase() })
		expect(wrapper.find('section').attributes('data-name')).toBe('Lead sources')
		expect(wrapper.findAll('.tag-chip__label').map((label) => label.text())).toEqual(['HOT', 'COLD'])
		expect(wrapper.find('header').text()).toBe('+ Add')
	})

	it('adds a trimmed new name through the add listener and closes the form', async () => {
		const onAdd = vi.fn(async () => {})
		const wrapper = tagManager({ onAdd, tags: [], addLabel: 'New source', addPlaceholder: 'Source name' })

		await wrapper.find('header button').trigger('click')
		const input = wrapper.find('.tag-chip--adding input')
		expect(input.attributes('placeholder')).toBe('Source name')
		expect(document.activeElement).toBe(input.element)
		await input.setValue('  warm ')
		await input.trigger('keyup.enter')
		await flushPromises()

		expect(onAdd).toHaveBeenCalledWith('warm')
		expect(wrapper.find('.tag-chip--adding').exists()).toBe(false)
	})

	it('refuses a taken name in any case, ignores a blank one and shows a failed add', async () => {
		const onAdd = vi.fn().mockRejectedValueOnce(new Error('Server said no'))
		const wrapper = tagManager({ onAdd })

		await wrapper.find('header button').trigger('click')
		const input = wrapper.find('.tag-chip--adding input')
		await input.setValue('  ')
		await wrapper.find('.tag-chip--adding .tag-chip__action--save').trigger('click')
		await input.setValue('HOT')
		await wrapper.find('.tag-chip--adding .tag-chip__action--save').trigger('click')
		expect(wrapper.find('.note').text()).toBe('An item with the name "HOT" already exists.')
		expect(onAdd).not.toHaveBeenCalled()

		await input.setValue('mild')
		await input.trigger('keyup.enter')
		await flushPromises()
		expect(onAdd).toHaveBeenCalledWith('mild')
		expect(wrapper.find('.note').text()).toBe('Server said no')
		expect(wrapper.find('.tag-chip--adding').exists()).toBe(true)

		await wrapper.find('.tag-chip--adding .tag-chip__action--cancel').trigger('click')
		expect(wrapper.find('.tag-chip--adding').exists()).toBe(false)
		expect(wrapper.find('.note').exists()).toBe(false)
	})

	it('renames on double-click, letting a tag keep its own name but not take another', async () => {
		const onRename = vi.fn(async () => {})
		const wrapper = tagManager({ onRename })

		await wrapper.findAll('.tag-chip__label')[0].trigger('dblclick')
		const input = wrapper.find('.tag-chip--editing input')
		expect(input.element.value).toBe('hot')
		expect(document.activeElement).toBe(input.element)

		await input.setValue('Cold')
		await wrapper.find('.tag-chip--editing .tag-chip__action--save').trigger('click')
		expect(wrapper.find('.note').text()).toBe('An item with the name "Cold" already exists.')

		await input.setValue('HOT')
		await input.trigger('keyup.enter')
		await flushPromises()
		expect(onRename).toHaveBeenCalledWith(1, 'HOT')
		expect(wrapper.find('.tag-chip--editing').exists()).toBe(false)
	})

	it('keeps the editor open with the error when a rename fails, and cancels on escape', async () => {
		const onRename = vi.fn().mockRejectedValue(new Error('Locked'))
		const wrapper = tagManager({ onRename })

		await wrapper.findAll('.tag-chip__label')[1].trigger('dblclick')
		await wrapper.find('.tag-chip--editing input').setValue('frozen')
		await wrapper.find('.tag-chip--editing input').trigger('keyup.enter')
		await flushPromises()
		expect(wrapper.find('.note').text()).toBe('Locked')

		await wrapper.find('.tag-chip--editing input').trigger('keyup.escape')
		expect(wrapper.find('.tag-chip--editing').exists()).toBe(false)
		expect(wrapper.find('.note').exists()).toBe(false)
	})

	it('asks before removing, naming how many items use the value', async () => {
		const usageCheck = vi.fn().mockResolvedValueOnce(3).mockResolvedValueOnce(0).mockRejectedValueOnce(new Error('x'))
		const wrapper = tagManager({ usageCheck })
		const remove = () => wrapper.findAll('.tag-chip__remove')[0].trigger('click')

		await remove()
		await flushPromises()
		globalThis.confirm.mockReturnValueOnce(false)
		await remove()
		await flushPromises()
		await remove()
		await flushPromises()

		expect(usageCheck).toHaveBeenCalledWith('hot')
		expect(globalThis.confirm.mock.calls.map((call) => call[0])).toEqual([
			'3 items currently use "hot". They will retain their value, but it will no longer be available for new items.',
			'Are you sure you want to remove "hot"?',
			'Are you sure you want to remove "hot"?',
		])
		expect(wrapper.emitted('remove')).toEqual([[1], [1]])
	})
})

/**
 * @return {Promise<object>} The mounted category manager after its first load.
 */
async function categoryManager() {
	const wrapper = mount(ProductCategoryManager, { global: { mocks: { t } }, attachTo: document.body })
	await flushPromises()
	return wrapper
}

/**
 * @param {object} wrapper The category manager.
 * @param {string} label A button text.
 * @return {object} The first button with that text.
 */
function button(wrapper, label) {
	return wrapper.findAll('button').find((item) => item.text() === label)
}

describe('ProductCategoryManager', () => {
	it('loads and sorts categories by order, then name', async () => {
		store.fetchCollection.mockResolvedValue([{ id: 'c', name: 'Zeta' }, { id: 'b', name: 'Beta', order: 2, description: 'Second' }, { id: 'a', name: 'Alpha' }, { id: 'd', order: 1 }])
		const wrapper = await categoryManager()

		expect(store.fetchCollection).toHaveBeenCalledWith('productCategory', { _limit: 100 })
		expect(wrapper.findAll('.category-item__name').map((name) => name.text())).toEqual(['', 'Beta', 'Alpha', 'Zeta'])
		expect(wrapper.findAll('.category-item__desc').map((desc) => desc.text())).toEqual(['Second'])
	})

	it('shows the empty state when loading fails or finds nothing', async () => {
		store.fetchCollection.mockRejectedValueOnce(new Error('down')).mockResolvedValueOnce(null)

		expect((await categoryManager()).text()).toContain('No product categories configured yet.')
		expect((await categoryManager()).text()).toContain('No product categories configured yet.')
	})

	it('adds a category at the end of the order and reloads', async () => {
		store.fetchCollection.mockResolvedValue([{ id: 'a', name: 'Alpha' }])
		store.saveObject.mockResolvedValue({})
		const wrapper = await categoryManager()

		await button(wrapper, 'Add category').trigger('click')
		expect(button(wrapper, 'Add').attributes('disabled')).toBeDefined()
		const [name, description] = wrapper.findAll('.category-item--adding input')
		expect(document.activeElement).toBe(name.element)
		await name.setValue(' Hardware ')
		await description.setValue(' Boxes ')
		await name.trigger('keyup.enter')
		await flushPromises()

		expect(store.saveObject).toHaveBeenCalledWith('productCategory', { name: 'Hardware', description: 'Boxes', order: 1 })
		expect(store.fetchCollection).toHaveBeenCalledTimes(2)
		expect(wrapper.find('.category-item--adding').exists()).toBe(false)
	})

	it('shows a failed add, ignores a blank one and cancels on escape', async () => {
		store.fetchCollection.mockResolvedValue([])
		store.saveObject.mockRejectedValueOnce(new Error('Name taken')).mockRejectedValueOnce({})
		const wrapper = await categoryManager()

		await button(wrapper, 'Add category').trigger('click')
		const name = wrapper.find('.category-item--adding input')
		await name.trigger('keyup.enter')
		expect(store.saveObject).not.toHaveBeenCalled()
		await name.setValue('X')
		await button(wrapper, 'Add').trigger('click')
		await flushPromises()
		expect(wrapper.find('.note').text()).toBe('Name taken')
		await button(wrapper, 'Add').trigger('click')
		await flushPromises()
		expect(wrapper.find('.note').text()).toBe('Failed to create category')

		await name.trigger('keyup.escape')
		expect(wrapper.find('.category-item--adding').exists()).toBe(false)
		expect(wrapper.find('.note').exists()).toBe(false)
	})

	it('edits a category in place and saves it by id', async () => {
		store.fetchCollection.mockResolvedValue([{ id: 'a', name: 'Alpha', description: 'First' }])
		store.saveObject.mockResolvedValueOnce({}).mockRejectedValueOnce({})
		const wrapper = await categoryManager()

		await button(wrapper, 'Edit').trigger('click')
		const [name, description] = wrapper.findAll('.category-item--editing input')
		expect([name.element.value, description.element.value]).toEqual(['Alpha', 'First'])
		expect(document.activeElement).toBe(name.element)
		await description.setValue('')
		await button(wrapper, 'Save').trigger('click')
		await flushPromises()
		expect(store.saveObject).toHaveBeenCalledWith('productCategory', { id: 'a', name: 'Alpha', description: '' })
		expect(wrapper.find('.category-item--editing').exists()).toBe(false)

		await button(wrapper, 'Edit').trigger('click')
		await wrapper.find('.category-item--editing input').setValue(' ')
		expect(button(wrapper, 'Save').attributes('disabled')).toBeDefined()
		await wrapper.find('.category-item--editing input').trigger('keyup.enter')
		await wrapper.find('.category-item--editing input').setValue('Al')
		await wrapper.find('.category-item--editing input').trigger('keyup.enter')
		await flushPromises()
		expect(store.saveObject).toHaveBeenCalledTimes(2)
		expect(wrapper.find('.note').text()).toBe('Failed to update category')

		await button(wrapper, 'Cancel').trigger('click')
		expect(wrapper.find('.category-item--editing').exists()).toBe(false)
	})

	it('removes a category only after confirmation and shows a failed removal', async () => {
		store.fetchCollection.mockResolvedValue([{ id: 'a', name: 'Alpha' }])
		store.deleteObject.mockResolvedValueOnce(true).mockRejectedValueOnce(new Error('In use'))
		const wrapper = await categoryManager()

		globalThis.confirm.mockReturnValueOnce(false)
		await button(wrapper, 'Remove').trigger('click')
		await flushPromises()
		expect(store.deleteObject).not.toHaveBeenCalled()

		await button(wrapper, 'Remove').trigger('click')
		await flushPromises()
		await button(wrapper, 'Remove').trigger('click')
		await flushPromises()

		expect(globalThis.confirm).toHaveBeenCalledWith('Are you sure you want to remove "Alpha"?')
		expect(store.deleteObject.mock.calls).toEqual([['productCategory', 'a'], ['productCategory', 'a']])
		expect(store.fetchCollection).toHaveBeenCalledTimes(2)
		expect(wrapper.find('.note').text()).toBe('In use')
	})
})
