// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Quick actions on My work (REQ-MW-100): the header offers New ticket and New
 * contact and no New lead; New ticket opens the New request dialog with Open
 * after creating unticked, and a ticket saved there asks the page's lists to
 * read again instead of leaving the page.
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-quick-actions-on-my-work-req-mw-100
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
const bus = vi.hoisted(() => ({
	emit: vi.fn(),
	subscribe: vi.fn(),
	unsubscribe: vi.fn(),
}))
const store = vi.hoisted(() => ({ saveObject: vi.fn(), getError: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		emits: ['closing'],
		render() {
			return h('div', [this.$slots.default?.(), this.$slots.actions?.()])
		},
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		render() {
			return h(
				'button',
				{ disabled: this.disabled, onClick: () => this.$emit('click') },
				this.$slots.default?.(),
			)
		},
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		render() {
			return h('input', {
				type: 'checkbox',
				checked: this.modelValue,
				onChange: () => this.$emit('update:modelValue', !this.modelValue),
			})
		},
	},
}))
vi.mock('../../src/views/requests/RequestForm.vue', () => ({
	default: {
		name: 'RequestForm',
		props: ['preLinkedClient', 'showActions', 'boardLayout'],
		emits: ['save', 'update:valid'],
		methods: { onSave: vi.fn() },
		render: () => h('div'),
	},
}))

globalThis.t = (_app, text) => text

const { default: RequestCreateDialog } =
	await import('../../src/dialogs/RequestCreateDialog.vue')
const registry = readFileSync(resolve(__dirname, '../../src/registry.js'), 'utf8')
const manifest = JSON.parse(
	readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'),
)
const page = manifest.pages.find((p) => p.id === 'MyWork')
const action = (id) => page.config.headerActions.find((a) => a.id === id)

/**
 * Mount the dialog with the props a header action passes.
 *
 * @param {object} props The dialog props.
 * @return {{wrapper: object, push: Function}} The dialog and the router's push.
 */
function mountDialog(props = {}) {
	const push = vi.fn(() => Promise.resolve())
	const wrapper = mount(RequestCreateDialog, {
		props,
		global: { mocks: { t: (_app, text) => text, $router: { push } } },
	})
	return { wrapper, push }
}

beforeEach(() => {
	dialogs.showSuccess.mockReset()
	dialogs.showError.mockReset()
	bus.emit.mockReset()
	store.saveObject.mockReset()
})

describe('the My work header', () => {
	it('offers New ticket and New contact, each opening a registered dialog', () => {
		expect(action('my-work-new-ticket')).toMatchObject({
			label: 'New ticket',
			type: 'open-modal',
			target: 'RequestCreateDialog',
			props: { stayAfterCreate: true },
		})
		expect(action('my-work-new-contact')).toMatchObject({
			label: 'New contact',
			type: 'open-modal',
			target: 'ContactCreateDialog',
		})
		expect(registry).toMatch(/RequestCreateDialog: \{\s*kind: 'modal'/)
		expect(registry).toMatch(/ContactCreateDialog: \{\s*kind: 'modal'/)
	})

	it('has no New lead', () => {
		const labels = page.config.headerActions.map((a) => a.label)
		expect(labels).not.toContain('New lead')
		expect(
			page.config.headerActions.some((a) => a.target === 'LeadCreateDialog'),
		).toBe(false)
	})
})

describe('New ticket from My work', () => {
	it('starts with Open after creating unticked', () => {
		const { wrapper } = mountDialog({ stayAfterCreate: true })
		expect(wrapper.vm.openAfter).toBe(false)
		expect(mountDialog().wrapper.vm.openAfter).toBe(true)
	})

	it('saves the ticket, stays on the page and asks the lists to read again', async () => {
		store.saveObject.mockResolvedValueOnce({ id: 't-9' })
		const { wrapper, push } = mountDialog({ stayAfterCreate: true })
		await wrapper.vm.onSave({ title: 'Paspoort aanvragen', assignee: 'daan' })
		await flushPromises()

		expect(store.saveObject).toHaveBeenCalledWith('ticket', {
			title: 'Paspoort aanvragen',
			assignee: 'daan',
			ticketType: 'request',
		})
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(dialogs.showSuccess).toHaveBeenCalledWith('The ticket is created.')
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(push).not.toHaveBeenCalled()
	})

	it('opens the ticket without a refresh when Open after creating is ticked', async () => {
		store.saveObject.mockResolvedValueOnce({ id: 't-10' })
		const { wrapper, push } = mountDialog()
		await wrapper.vm.onSave({ title: 'Melding' })
		await flushPromises()

		expect(push).toHaveBeenCalledWith({
			name: 'TicketDetail',
			params: { id: 't-10' },
		})
		expect(bus.emit).not.toHaveBeenCalled()
	})
})
