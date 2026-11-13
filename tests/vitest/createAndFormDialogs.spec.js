// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The create and form dialogs, mounted: the lead and request saves (payload,
 * created event, navigation or toast, error text, saving state), the contact
 * create with its seed and error paths, the add-contact-role form guard, and
 * the article form's editor hand-off and error feedback, and the log-visit
 * form guard.
 *
 * @spec openspec/specs/fe-dialogs/spec.md
 */
import { config, flushPromises, mount } from '@vue/test-utils'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
	store: null,
	showError: null,
	showSuccess: null,
	createWithContact: null,
	busEmit: null,
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: (...args) => mocks.showError(...args),
	showSuccess: (...args) => mocks.showSuccess(...args),
}))
vi.mock('@nextcloud/event-bus', () => ({ emit: (...args) => mocks.busEmit(...args) }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => mocks.store }))
vi.mock('../../src/services/contactSyncApi.js', () => ({ createWithContact: (...args) => mocks.createWithContact(...args) }))
vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		CnDialog: defineComponent({
			name: 'CnDialog',
			props: { name: { type: String, default: '' } },
			emits: ['closing'],
			setup(props, { slots }) {
				return () => h('div', { class: 'dialog', 'data-name': props.name }, [
					h('div', { class: 'body' }, slots.default ? slots.default() : []),
					h('div', { class: 'actions' }, slots.actions ? slots.actions() : []),
				])
			},
		}),
		CnFormDialog: defineComponent({
			name: 'CnFormDialog',
			props: { dialogTitle: { type: String, default: '' }, excludeFields: { type: Array, default: () => [] }, item: { type: Object, default: null } },
			emits: ['confirm', 'close'],
			methods: { setResult(result) { this.lastResult = result } },
			data: () => ({ lastResult: null }),
			render() {
				return h('div', { class: 'form-dialog' }, this.$slots['before-fields'] ? this.$slots['before-fields']() : [])
			},
		}),
	}
})
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		NcButton: defineComponent({
			name: 'NcButton',
			props: { variant: { type: String, default: 'secondary' }, disabled: { type: Boolean, default: false } },
			setup(props, { slots }) {
				return () => h('button', { disabled: props.disabled }, slots.default ? slots.default() : [])
			},
		}),
		NcSelect: defineComponent({
			name: 'NcSelect',
			props: { modelValue: { type: [String, Object], default: null }, options: { type: Array, default: () => [] }, reduce: { type: Function, default: null } },
			emits: ['update:modelValue', 'search'],
			setup: () => () => h('div', { class: 'select' }),
		}),
		NcCheckboxRadioSwitch: defineComponent({
			name: 'NcCheckboxRadioSwitch',
			props: { modelValue: { type: Boolean, default: false } },
			emits: ['update:modelValue'],
			setup: () => () => h('label'),
		}),
	}
})

/**
 * A form stub whose onSave() emits `save` with the data it was given.
 *
 * @param {string} name The component name.
 * @return {Promise<object>} The module.
 */
async function form(name) {
	const { defineComponent, h } = await import('vue')
	return {
		default: defineComponent({
			name,
			props: { preLinkedClient: { type: String, default: null }, contact: { type: Object, default: null }, preSelectedClient: { type: String, default: null } },
			emits: ['save', 'update:valid', 'cancel'],
			data: () => ({ payload: {} }),
			methods: {
				onSave() {
					this.$emit('save', this.payload)
				},
			},
			render: () => h('form'),
		}),
	}
}

vi.mock('../../src/views/leads/LeadForm.vue', () => form('LeadForm'))
vi.mock('../../src/views/requests/RequestForm.vue', () => form('RequestForm'))
vi.mock('../../src/views/contacts/ContactForm.vue', () => form('ContactForm'))
vi.mock('../../src/modals/ArticleEditModal.vue', async () => {
	const { defineComponent, h } = await import('vue')
	return { default: defineComponent({ name: 'ArticleEditModal', props: { article: { type: Object, default: null } }, emits: ['close', 'saved'], setup: () => () => h('div', { class: 'editor' }) }) }
})

const dialogs = {}

beforeAll(async () => {
	globalThis.t = (app, text) => text
	config.global.mocks.t = globalThis.t
	for (const name of ['LeadCreateDialog', 'RequestCreateDialog', 'ContactCreateDialog', 'AddContactRoleDialog', 'ArticleDetailFormDialog', 'LogVisitDialog']) {
		dialogs[name] = (await import(`../../src/dialogs/${name}.vue`)).default
	}
}, 60000)

beforeEach(() => {
	mocks.store = { saveObject: vi.fn(async () => ({ id: 'new-1' })), getError: vi.fn(() => null) }
	mocks.showError = vi.fn()
	mocks.showSuccess = vi.fn()
	mocks.createWithContact = vi.fn()
	mocks.busEmit = vi.fn()
})

/**
 * Mount a dialog with a router mock.
 *
 * @param {object} component The dialog.
 * @param {object} props The props.
 * @return {object} The wrapper and the router.
 */
function mountWithRouter(component, props = {}) {
	const router = { push: vi.fn(() => Promise.resolve()) }
	const wrapper = mount(component, { props, global: { mocks: { $router: router } } })
	return { wrapper, router }
}

/**
 * The button with the given text.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} text The text.
 * @return {object} The button.
 */
function button(wrapper, text) {
	return wrapper.findAll('button').find((b) => b.text().trim() === text)
}

describe('LeadCreateDialog', () => {
	it('enables Create once the form is valid, saves the lead and opens it', async () => {
		const { wrapper, router } = mountWithRouter(dialogs.LeadCreateDialog)
		expect(wrapper.find('.dialog').attributes('data-name')).toBe('New Lead')
		expect(button(wrapper, 'Create').attributes('disabled')).toBeDefined()
		const leadForm = wrapper.findComponent({ name: 'LeadForm' })
		leadForm.vm.$emit('update:valid', true)
		leadForm.vm.payload = { title: 'Big deal' }
		await wrapper.vm.$nextTick()
		await button(wrapper, 'Create').trigger('click')
		await flushPromises()
		expect(mocks.store.saveObject).toHaveBeenCalledWith('lead', { title: 'Big deal' })
		expect(wrapper.emitted('created')).toEqual([['new-1']])
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(router.push).toHaveBeenCalledWith({ name: 'LeadDetail', params: { id: 'new-1' } })
		expect(wrapper.vm.saving).toBe(false)
	})

	it('shows the store error, or a default, when the save fails', async () => {
		mocks.store.saveObject = vi.fn(async () => null)
		mocks.store.getError = vi.fn(() => ({ message: 'Title is required' }))
		const { wrapper, router } = mountWithRouter(dialogs.LeadCreateDialog)
		await wrapper.vm.onSave({})
		mocks.store.getError = vi.fn(() => null)
		await wrapper.vm.onSave({})
		expect(mocks.store.getError).toHaveBeenCalledWith('lead')
		expect(mocks.showError.mock.calls).toEqual([['Title is required'], ['Failed to create lead.']])
		expect(wrapper.emitted('created')).toBeUndefined()
		expect(router.push).not.toHaveBeenCalled()
		expect(wrapper.vm.saving).toBe(false)
	})

	it('shows Creating… while saving and closes on cancel', async () => {
		const { wrapper } = mountWithRouter(dialogs.LeadCreateDialog)
		wrapper.vm.saving = true
		await wrapper.vm.$nextTick()
		expect(button(wrapper, 'Creating…')).toBeDefined()
		await button(wrapper, 'Cancel').trigger('click')
		wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})

describe('RequestCreateDialog', () => {
	it('saves a request ticket and opens it by default', async () => {
		const { wrapper, router } = mountWithRouter(dialogs.RequestCreateDialog)
		await wrapper.vm.onSave({ title: 'Question' })
		expect(mocks.store.saveObject).toHaveBeenCalledWith('ticket', { title: 'Question', ticketType: 'request' })
		expect(wrapper.emitted('created')).toEqual([['new-1']])
		expect(router.push).toHaveBeenCalledWith({ name: 'TicketDetail', params: { id: 'new-1' } })
		expect(mocks.showSuccess).not.toHaveBeenCalled()
	})

	it('keeps a given ticket type, and toasts and closes instead when not opening after', async () => {
		const { wrapper, router } = mountWithRouter(dialogs.RequestCreateDialog)
		wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', false)
		await wrapper.vm.$nextTick()
		await wrapper.vm.onSave({ title: 'Complaint', ticketType: 'complaint' })
		expect(mocks.store.saveObject).toHaveBeenCalledWith('ticket', { title: 'Complaint', ticketType: 'complaint' })
		expect(mocks.showSuccess).toHaveBeenCalledWith('The ticket is created.')
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(router.push).not.toHaveBeenCalled()
	})

	it('shows the store error, or a default, when the save fails', async () => {
		mocks.store.saveObject = vi.fn(async () => null)
		const { wrapper } = mountWithRouter(dialogs.RequestCreateDialog)
		await wrapper.vm.onSave({})
		expect(mocks.store.getError).toHaveBeenCalledWith('ticket')
		expect(mocks.showError).toHaveBeenCalledWith('Failed to create request.')
		expect(wrapper.vm.saving).toBe(false)
	})

	it('submits through the form from the Create request button', async () => {
		const { wrapper } = mountWithRouter(dialogs.RequestCreateDialog)
		wrapper.findComponent({ name: 'RequestForm' }).vm.$emit('update:valid', true)
		await wrapper.vm.$nextTick()
		await button(wrapper, 'Create request').trigger('click')
		await flushPromises()
		expect(mocks.store.saveObject).toHaveBeenCalledTimes(1)
	})
})

describe('ContactCreateDialog', () => {
	it('seeds the form with the given name and client', () => {
		const wrapper = mount(dialogs.ContactCreateDialog, { props: { name: 'Ann', client: 'client-1' } })
		const contactForm = wrapper.findComponent({ name: 'ContactForm' })
		expect(contactForm.props('contact')).toEqual({ name: 'Ann', client: 'client-1' })
		expect(contactForm.props('preSelectedClient')).toBe('client-1')
		expect(mount(dialogs.ContactCreateDialog).findComponent({ name: 'ContactForm' }).props('contact')).toEqual({})
	})

	it('emits the created contact', async () => {
		const created = { '@self': { id: 'c-1' }, name: 'Ann' }
		mocks.createWithContact = vi.fn(async () => created)
		const wrapper = mount(dialogs.ContactCreateDialog)
		await wrapper.vm.onSave({ name: 'Ann' })
		expect(mocks.createWithContact).toHaveBeenCalledWith('contact', { name: 'Ann' })
		expect(wrapper.emitted('created')).toEqual([[created]])
		expect(mocks.showError).not.toHaveBeenCalled()
	})

	it('shows an error for a result without an id or a failed request', async () => {
		const wrapper = mount(dialogs.ContactCreateDialog)
		mocks.createWithContact = vi.fn(async () => ({}))
		await wrapper.vm.onSave({})
		mocks.createWithContact = vi.fn(async () => {
			throw Object.assign(new Error('x'), { response: { data: { error: 'Duplicate email' } } })
		})
		await wrapper.vm.onSave({})
		mocks.createWithContact = vi.fn(async () => {
			throw new Error('network')
		})
		await wrapper.vm.onSave({})
		expect(mocks.showError.mock.calls).toEqual([['Failed to create contact.'], ['Duplicate email'], ['Failed to create contact.']])
		expect(wrapper.emitted('created')).toBeUndefined()
	})

	it('closes from the form and the dialog', () => {
		const wrapper = mount(dialogs.ContactCreateDialog)
		wrapper.findComponent({ name: 'ContactForm' }).vm.$emit('cancel')
		wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})

describe('AddContactRoleDialog', () => {
	it('submits a copy of the form once contact and role are picked', async () => {
		const wrapper = mount(dialogs.AddContactRoleDialog, { props: { contactOptions: [{ id: 'c-1', name: 'Ann' }], roleOptions: [{ value: 'owner', label: 'Owner' }] } })
		expect(wrapper.find('.dialog').attributes('data-name')).toBe('Add contact role')
		expect(button(wrapper, 'Add').attributes('disabled')).toBeDefined()
		const [contact, role] = wrapper.findAllComponents({ name: 'NcSelect' })
		expect(contact.props('reduce')({ id: 'c-1', name: 'Ann' })).toBe('c-1')
		expect(role.props('reduce')({ value: 'owner', label: 'Owner' })).toBe('owner')
		contact.vm.$emit('update:modelValue', 'c-1')
		await wrapper.vm.$nextTick()
		expect(button(wrapper, 'Add').attributes('disabled')).toBeDefined()
		role.vm.$emit('update:modelValue', 'owner')
		await wrapper.find('textarea').setValue('Decision maker')
		expect(button(wrapper, 'Add').attributes('disabled')).toBeUndefined()
		await button(wrapper, 'Add').trigger('click')
		const [[submitted]] = wrapper.emitted('submit')
		expect(submitted).toEqual({ toContact: 'c-1', type: 'owner', notes: 'Decision maker' })
		expect(submitted).not.toBe(wrapper.vm.form)
	})

	it('forwards contact searches and cancels', async () => {
		const wrapper = mount(dialogs.AddContactRoleDialog)
		wrapper.findAllComponents({ name: 'NcSelect' })[0].vm.$emit('search', 'an')
		await button(wrapper, 'Cancel').trigger('click')
		wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(wrapper.emitted('searchContacts')).toEqual([['an']])
		expect(wrapper.emitted('cancel')).toHaveLength(2)
	})
})

describe('ArticleDetailFormDialog', () => {
	const schema = { id: 'article' }

	it('renders nothing until shown with a schema', () => {
		expect(mount(dialogs.ArticleDetailFormDialog, { props: { show: true } }).find('.form-dialog').exists()).toBe(false)
		expect(mount(dialogs.ArticleDetailFormDialog, { props: { schema } }).find('.form-dialog').exists()).toBe(false)
	})

	it('leaves title and body to the editor for an existing article', async () => {
		const item = { id: 'a-1', title: 'FAQ' }
		const wrapper = mount(dialogs.ArticleDetailFormDialog, { props: { show: true, schema, item } })
		const formDialog = wrapper.findComponent({ name: 'CnFormDialog' })
		expect(formDialog.props('dialogTitle')).toBe('Edit article')
		expect(formDialog.props('excludeFields')).toEqual(['title', 'body'])
		expect(wrapper.find('.editor').exists()).toBe(false)
		await button(wrapper, 'Edit title and body').trigger('click')
		const editor = wrapper.findComponent({ name: 'ArticleEditModal' })
		expect(editor.props('article')).toEqual(item)
		editor.vm.$emit('saved')
		await wrapper.vm.$nextTick()
		expect(mocks.busEmit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(wrapper.find('.editor').exists()).toBe(false)
	})

	it('excludes no fields for a new article and closes the editor when hidden', async () => {
		const wrapper = mount(dialogs.ArticleDetailFormDialog, { props: { show: true, schema } })
		expect(wrapper.findComponent({ name: 'CnFormDialog' }).props('excludeFields')).toEqual([])
		expect(wrapper.find('.article-detail-form__editor').exists()).toBe(false)
		await wrapper.setProps({ item: { id: 'a-1' } })
		wrapper.vm.editorOpen = true
		await wrapper.setProps({ show: false })
		expect(wrapper.vm.editorOpen).toBe(false)
	})

	it('passes the form data to confirm and reports its error back', async () => {
		const confirm = vi.fn(async () => ({ error: 'Slug taken' }))
		const close = vi.fn()
		const wrapper = mount(dialogs.ArticleDetailFormDialog, { props: { show: true, schema, confirm, close } })
		const formDialog = wrapper.findComponent({ name: 'CnFormDialog' })
		formDialog.vm.$emit('confirm', { slug: 'faq' })
		await flushPromises()
		expect(confirm).toHaveBeenCalledWith({ slug: 'faq' })
		expect(formDialog.vm.lastResult).toEqual({ error: 'Slug taken' })
		confirm.mockResolvedValueOnce({ ok: true })
		formDialog.vm.lastResult = null
		formDialog.vm.$emit('confirm', {})
		await flushPromises()
		expect(formDialog.vm.lastResult).toBe(null)
		formDialog.vm.$emit('close')
		expect(close).toHaveBeenCalledTimes(1)
	})
})

describe('LogVisitDialog', () => {
	it('saves the trimmed note and follow-up date', async () => {
		const wrapper = mount(dialogs.LogVisitDialog)
		expect(wrapper.find('.dialog').attributes('data-name')).toBe('Log a visit')
		expect(button(wrapper, 'Save visit').attributes('disabled')).toBeDefined()
		await wrapper.find('textarea').setValue('  Met the director  ')
		await wrapper.find('input[type="date"]').setValue('2026-12-01')
		await wrapper.find('form').trigger('submit')
		await button(wrapper, 'Save visit').trigger('click')
		expect(wrapper.emitted('save')).toEqual([
			[{ note: 'Met the director', followUpDate: '2026-12-01' }],
			[{ note: 'Met the director', followUpDate: '2026-12-01' }],
		])
	})

	it('refuses a blank note or a save in progress and shows the error', async () => {
		const wrapper = mount(dialogs.LogVisitDialog, { props: { error: 'Could not save' } })
		await wrapper.find('textarea').setValue('   ')
		await wrapper.find('form').trigger('submit')
		await wrapper.setProps({ saving: true })
		await wrapper.find('textarea').setValue('Note')
		await wrapper.find('form').trigger('submit')
		expect(wrapper.emitted('save')).toBeUndefined()
		expect(wrapper.find('[role="alert"]').text()).toBe('Could not save')
		await button(wrapper, 'Cancel').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
