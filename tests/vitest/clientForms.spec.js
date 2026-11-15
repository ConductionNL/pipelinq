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
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import manifest from '../../src/manifest.json'
import {
	contactWriteBackPlugin,
	needsWriteBack,
} from '../../src/store/plugins/contactWriteBack.js'

const createWithContact = vi.hoisted(() => vi.fn())
vi.mock('../../src/services/contactSyncApi.js', () => ({
	createWithContact,
	writeBack: vi.fn(),
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
const store = vi.hoisted(() => ({ fetchCollection: vi.fn(async () => []) }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		props: ['name', 'eyebrow', 'width'],
		render() {
			return h('div', { 'data-name': 'CnDialog', 'data-eyebrow': this.eyebrow }, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	},
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(async () => ({ data: { languages: [] } })) },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (p) => p,
	generateOcsUrl: (p) => p,
}))
vi.mock('@nextcloud/l10n', () => ({
	getLanguage: () => 'en',
	translate: (app, text) => text,
}))
vi.mock('@nextcloud/vue', () => {
	const passthrough = (name) => ({
		name,
		inheritAttrs: false,
		render() {
			return h('div', { 'data-name': name }, [
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	})
	return {
		NcDialog: passthrough('NcDialog'),
		NcCheckboxRadioSwitch: passthrough('NcCheckboxRadioSwitch'),
		NcTextArea: passthrough('NcTextArea'),
		NcButton: {
			name: 'NcButton',
			emits: ['click'],
			render() {
				return h(
					'button',
					{ onClick: () => this.$emit('click') },
					this.$slots.default?.(),
				)
			},
		},
		NcSelect: {
			name: 'NcSelect',
			props: ['taggable', 'options', 'inputLabel', 'modelValue'],
			render: () => h('span'),
		},
		NcTextField: {
			name: 'NcTextField',
			props: ['modelValue', 'label', 'helperText'],
			render() {
				return h('input', {
					'data-label': this.label,
					'data-helper': this.helperText || '',
					value: this.modelValue,
				})
			},
		},
	}
})

globalThis.t = (_app, text) => text

const { default: ClientCreateDialog } =
	await import('../../src/dialogs/ClientCreateDialog.vue')

function mountDialog(props) {
	const push = vi.fn(() => Promise.resolve())
	const wrapper = mount(ClientCreateDialog, {
		props,
		global: {
			mocks: { t: (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_all, key) => vars[key] ?? `{${key}}`), $router: { push, resolve: ({ params }) => ({ href: `/apps/pipelinq/clients/${params.id}` }) } },
		},
	})
	return { wrapper, push }
}

describe('New client dialog opened from a picker', () => {
	it('prefills the typed name', async () => {
		const { wrapper } = mountDialog({ name: 'Acme', stayOnPage: true })
		await flushPromises()
		const form = wrapper.findComponent({ name: 'ClientForm' })
		expect(form.vm.form.name).toBe('Acme')
	})

	it('prefills from the nextcloud-vue picker too', async () => {
		const { wrapper } = mountDialog({ initialData: { name: 'Beta BV' } })
		await flushPromises()
		const form = wrapper.findComponent({ name: 'ClientForm' })
		expect(form.vm.form.name).toBe('Beta BV')
	})

	it('hands the client back and stays on the form', async () => {
		createWithContact.mockResolvedValueOnce({ id: 'c-1', name: 'Acme' })
		const { wrapper, push } = mountDialog({ name: 'Acme', stayOnPage: true })
		await wrapper.vm.onSave({ name: 'Acme', type: 'organization' })
		expect(wrapper.emitted('created')[0][0]).toBe('c-1')
		expect(wrapper.emitted('created')[0][1]).toMatchObject({ name: 'Acme' })
		expect(wrapper.emitted('close')).toBeTruthy()
		expect(push).not.toHaveBeenCalled()
	})

	it('still opens the new client when the Clients page opened it', async () => {
		createWithContact.mockResolvedValueOnce({ id: 'c-2' })
		const { wrapper, push } = mountDialog({})
		await wrapper.vm.onSave({ name: 'Gamma', type: 'person' })
		expect(push).toHaveBeenCalledWith({
			name: 'ClientDetail',
			params: { id: 'c-2' },
		})
	})
})

describe('errors wait for the user', () => {
	const nameHelper = (wrapper) =>
		wrapper.find('input[data-label="Name"]').attributes('data-helper')

	it('shows no "Name is required" on a freshly opened form', async () => {
		const { wrapper } = mountDialog({})
		await flushPromises()
		expect(nameHelper(wrapper)).toBe('')
	})

	it('shows it once the name was typed and cleared', async () => {
		const { wrapper } = mountDialog({})
		await flushPromises()
		const form = wrapper.findComponent({ name: 'ClientForm' })
		form.vm.form.name = 'A'
		await flushPromises()
		form.vm.form.name = ''
		await flushPromises()
		expect(nameHelper(wrapper)).toBe('Name is required')
	})

	it('shows it after a save attempt', async () => {
		const { wrapper } = mountDialog({})
		await flushPromises()
		wrapper.findComponent({ name: 'ClientForm' }).vm.onSave()
		await flushPromises()
		expect(nameHelper(wrapper)).toBe('Name is required')
	})
})

describe('client form fields', () => {
	it('offers industry as a fixed list', async () => {
		const { wrapper } = mountDialog({})
		await flushPromises()
		const industry = wrapper
			.findAllComponents({ name: 'NcSelect' })
			.find((s) => s.props('inputLabel') === 'Industry')
		expect(industry.props('taggable')).toBeFalsy()
		expect(industry.props('options').length).toBeGreaterThan(0)
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

describe('New client dialog as board PqKlantNieuw draws it', () => {
	it('is the board dialog with the type as two choices and the rest in their board places', async () => {
		const { wrapper } = mountDialog({})
		await flushPromises()
		expect(wrapper.find('[data-name="CnDialog"]').attributes('data-eyebrow')).toBe('Residents and businesses')
		expect(wrapper.findComponent({ name: 'ClientForm' }).props('boardLayout')).toBe(true)
		expect(wrapper.findAll('[data-name="NcCheckboxRadioSwitch"]')).toHaveLength(2)
	})

	it('warns about a client with the same e-mail address and offers to open it or save anyway', async () => {
		vi.useFakeTimers()
		store.fetchCollection.mockImplementation(async (_type, filter) => (filter.email ? [{ id: 'c-9', name: 'Bakkerij De Graaf', email: 'info@bakkerijdegraaf.nl' }] : []))
		const { wrapper } = mountDialog({})
		await flushPromises()
		const form = wrapper.findComponent({ name: 'ClientForm' })
		form.vm.form.email = 'INFO@bakkerijdegraaf.nl'
		await flushPromises()
		await vi.advanceTimersByTimeAsync(450)
		await flushPromises()
		vi.useRealTimers()
		const notice = wrapper.find('[data-testid="client-duplicate"]')
		expect(notice.exists()).toBe(true)
		expect(notice.text()).toContain('Possible duplicate: Bakkerij De Graaf has the same email address')
		expect(wrapper.find('[data-testid="client-duplicate-open"]').attributes('href')).toBe('/apps/pipelinq/clients/c-9')
		expect(notice.find('[data-testid="client-duplicate-icon"]').exists()).toBe(true)
	})

	/**
	 * @spec openspec/specs/client-management/spec.md#requirement-the-duplicate-warning-is-a-bordered-notice
	 */
	it('draws the duplicate warning as the board\'s bordered notice, 12px in, the notes field 89.5px tall', () => {
		const sfc = readFileSync(resolve(__dirname, '../../src/views/clients/ClientForm.vue'), 'utf8')
		const style = sfc.slice(sfc.indexOf('<style'))
		expect(style).toMatch(/\.client-form__duplicate \{[^}]*padding: 12px 14px;[^}]*border: 1px solid var\(--cn-board-notice-border, #f0d9a8\);[^}]*background: var\(--cn-board-notice-bg, #fff4de\);/)
		expect(style).toMatch(/:deep\(#client-board-notes\) \{\s*min-height: 89\.5px;/)
	})
})
