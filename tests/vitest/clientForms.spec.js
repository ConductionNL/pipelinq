// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One New client dialog everywhere, and it returns to the form that opened
 * it (pipelinq-audit-admin-forms-pos, D7 and the client-form extras).
 *
 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/client-forms/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import manifest from '../../src/manifest.json'
import {
	contactWriteBackPlugin,
	needsWriteBack,
} from '../../src/store/plugins/contactWriteBack.js'

const createClientWithContact = vi.hoisted(() => vi.fn())
const updateField = vi.hoisted(() => vi.fn())
vi.mock('../../src/services/contactSyncApi.js', () => ({
	createClientWithContact,
	createWithContact: vi.fn(),
	writeBack: vi.fn(),
}))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({
		fetchSchema: vi.fn(async () => {}),
		getSchema: () => ({ properties: { name: { type: 'string' } }, required: ['name', 'type'] }),
	}),
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', inheritAttrs: false, render() { return h('button', this.$attrs, this.$slots.default?.()) } },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnFormDialog: {
		name: 'CnFormDialog',
		props: ['schema', 'item', 'initialData', 'includeFields', 'fieldOverrides', 'dialogTitle', 'register'],
		emits: ['confirm', 'close'],
		methods: { setResult: vi.fn(), updateField },
		render() {
			return h('div', this.$slots['before-fields']?.())
		},
	},
}))

globalThis.t = (_app, text) => text

const { default: ClientCreateDialog } =
	await import('../../src/dialogs/ClientCreateDialog.vue')
const { default: ContactCreateDialog } =
	await import('../../src/dialogs/ContactCreateDialog.vue')

function mountDialog(props) {
	const push = vi.fn(() => Promise.resolve())
	const wrapper = mount(ClientCreateDialog, {
		props,
		global: {
			mocks: { t: (_app, text) => text, $router: { push } },
		},
	})
	return { wrapper, push }
}

const form = (wrapper) => wrapper.findComponent({ name: 'CnFormDialog' })

describe('New client dialog opened from a picker', () => {
	it('prefills the typed name', async () => {
		const { wrapper } = mountDialog({ name: 'Acme', stayOnPage: true })
		await flushPromises()
		expect(form(wrapper).props('initialData')).toEqual({ name: 'Acme' })
	})

	it('prefills from the nextcloud-vue picker too', async () => {
		const { wrapper } = mountDialog({ initialData: { name: 'Beta BV' } })
		await flushPromises()
		expect(form(wrapper).props('initialData')).toEqual({ name: 'Beta BV' })
	})

	it('hands the client back and stays on the form', async () => {
		createClientWithContact.mockResolvedValueOnce({ id: 'c-1', name: 'Acme' })
		const { wrapper, push } = mountDialog({ name: 'Acme', stayOnPage: true })
		await flushPromises()
		await wrapper.vm.onConfirm({ name: 'Acme', type: 'organization' })
		expect(wrapper.emitted('created')[0][0]).toBe('c-1')
		expect(wrapper.emitted('created')[0][1]).toMatchObject({ name: 'Acme' })
		expect(wrapper.emitted('close')).toBeTruthy()
		expect(push).not.toHaveBeenCalled()
	})

	it('still opens the new client when the Clients page opened it', async () => {
		createClientWithContact.mockResolvedValueOnce({ id: 'c-2' })
		const { wrapper, push } = mountDialog({})
		await flushPromises()
		await wrapper.vm.onConfirm({ name: 'Gamma', type: 'person' })
		expect(push).toHaveBeenCalledWith({
			name: 'ClientDetail',
			params: { id: 'c-2' },
		})
	})

	it('asks what the Clients page declares, through the library form', async () => {
		const { wrapper } = mountDialog({})
		await flushPromises()
		const clients = manifest.pages.find((p) => p.id === 'Clients').config
		expect(form(wrapper).props('includeFields')).toEqual(clients.includeFields)
		expect(form(wrapper).props('fieldOverrides')).toEqual(clients.fieldOverrides)
	})
})

describe('New contact dialog', () => {
	it('asks name, client, role, email and phone with the Contacts page checks, and can make a client', async () => {
		const wrapper = mount(ContactCreateDialog, {
			props: { name: 'Jan', client: 'k-1' },
			global: { mocks: { t: (_app, text) => text }, stubs: { ClientCreateDialog: true } },
		})
		await flushPromises()
		const dialog = wrapper.findComponent({ name: 'CnFormDialog' })
		const contacts = manifest.pages.find((p) => p.id === 'Contacts').config
		expect(dialog.props('includeFields')).toEqual(['name', 'client', 'role', 'email', 'phone'])
		expect(dialog.props('fieldOverrides')).toEqual(contacts.fieldOverrides)
		expect(dialog.props('initialData')).toEqual({ name: 'Jan', client: 'k-1' })
		expect(wrapper.find('[data-testid="contact-create-new-client"]').exists()).toBe(true)
		wrapper.vm.onClientCreated('k-9')
		expect(updateField).toHaveBeenCalledWith('client', 'k-9')
	})
})

describe('the client form config', () => {
	const config = manifest.pages.find((p) => p.id === 'Clients').config
	const o = config.fieldOverrides

	it('asks every field the old form asked, the hidden ones brought back', () => {
		expect(config.includeFields).toEqual(['name', 'type', 'email', 'phone', 'website', 'industry', 'accountOwner', 'correspondenceLanguage', 'timezone', 'address', 'notes'])
		for (const key of ['website', 'address', 'notes']) {
			expect(o[key].hidden, key).toBe(false)
		}
	})

	it('checks the values as the old form did, in its words', () => {
		const check = (key, good, bad) => {
			const re = new RegExp(o[key].validation.pattern)
			expect(re.test(good), key).toBe(true)
			expect(re.test(bad), key).toBe(false)
		}
		check('email', 'jan@example.nl', 'jan@example')
		check('phone', '+31 6 1234 5678', '12ab')
		check('website', 'https://acme.nl', 'acme')
		expect([o.email, o.phone, o.website].map((f) => f.validation.patternMessage)).toEqual(['Invalid email format', 'Invalid phone format', 'Invalid URL format'])
		expect(o.name.validation.maxLength).toBe(255)
	})

	it('starts with the signed-in owner, the reader\'s language and the readable type labels', () => {
		expect(o.accountOwner).toEqual({ widget: 'user', default: '@me' })
		expect(o.correspondenceLanguage.defaultToken).toBe('current-language')
		expect(o.correspondenceLanguage.optionsFrom).toMatchObject({ url: '/apps/pipelinq/api/correspondence-languages', responsePath: 'options' })
		expect(o.type.enumLabels).toEqual({ person: 'Person', organization: 'Organization' })
		expect(o.industry.widget).toBe('multiselect')
		expect(o.industry.items.enum.length).toBeGreaterThan(0)
	})
})

describe('one client dialog everywhere', () => {
	const page = (id) => manifest.pages.find((p) => p.id === id)

	it('lets pickers use the New client dialog, not a generic form', () => {
		const clients = page('Clients').config
		expect(clients.createModal).toBe('ClientCreateDialog')
		// nextcloud-vue prefers a createOverride over the createModal for a
		// picker's create, which is what showed the generic schema form.
		expect(clients.createOverride).toBeUndefined()
	})

	it('asks for name and email when editing a client, and industry from the list', () => {
		for (const id of ['Clients', 'ClientDetail']) {
			const overrides = page(id).config.fieldOverrides
			expect(overrides.name.readOnly).toBe(false)
			expect(overrides.email.readOnly).toBe(false)
			expect(overrides.industry.widget).toBe('multiselect')
			expect(overrides.industry.items.enum).toContain('Consultancy')
		}
	})
})

describe('identity edits reach the Nextcloud Contact', () => {
	it('writes back an edit of name, email or phone only', () => {
		expect(needsWriteBack('client', { id: 'c', name: 'X' })).toBe(true)
		expect(needsWriteBack('contact', { id: 'c', email: 'x@y.nl' })).toBe(true)
		expect(needsWriteBack('client', { name: 'new, no id' })).toBe(false)
		expect(needsWriteBack('lead', { id: 'l', name: 'X' })).toBe(false)
		expect(needsWriteBack('client', { id: 'c', notes: 'x' })).toBe(false)
	})

	it('calls the write-back after a successful save', () => {
		const write = vi.fn()
		let listener = null
		const store = {
			$onAction: (fn) => {
				listener = fn
			},
		}
		contactWriteBackPlugin(write).setup(store)
		let afterFn = null
		listener({
			name: 'saveObject',
			args: ['client', { id: 'c-9', name: 'New name' }],
			after: (fn) => {
				afterFn = fn
			},
		})
		afterFn({ id: 'c-9' })
		expect(write).toHaveBeenCalledWith('client', 'c-9')
	})
})
