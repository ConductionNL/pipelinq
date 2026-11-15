// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The small dialogs' own wiring: dismissing or cancelling closes them, the
 * pickers and switches write into the form that is emitted, and a destruction
 * list is only rejected with a reason.
 *
 * @spec openspec/changes/platform-client-retention/tasks.md#3-review-and-back-fill
 * @spec openspec/specs/contact-channel-details/spec.md#requirement-channels-are-added-edited-and-removed-through-dedicated-modals
 * @spec openspec/specs/marketing-ui/spec.md#requirement-a-marketer-composes-one-post-for-several-networks
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const socialApi = vi.hoisted(() => ({
	createPost: vi.fn(),
	fetchAccounts: vi.fn(),
	movePost: vi.fn(),
	updatePost: vi.fn(),
}))
vi.mock('../../src/services/socialApi.js', () => socialApi)

/**
 * A stand-in that keeps its props and renders its slots.
 *
 * @param {string} name The component name.
 * @return {object} The component.
 */
function stub(name) {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'options', 'label', 'inputLabel', 'disabled', 'variant', 'type', 'name', 'value'],
		emits: ['update:modelValue', 'click', 'closing', 'blur'],
		render() {
			return h('div', { 'data-stub': name, 'data-testid': this.$attrs['data-testid'] }, [
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	}
}

vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: stub('CnDialog') }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton'),
	NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard'),
	NcSelect: stub('NcSelect'),
	NcTextArea: stub('NcTextArea'),
	NcTextField: stub('NcTextField'),
}))

/**
 * Translate as the identity, filling `{name}` placeholders.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} [vars] The placeholder values.
 * @return {string} The text.
 */
function t(app, text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? String(vars[key]) : match))
}

/**
 * Pick the singular or plural form and fill `%n`.
 *
 * @param {string} app The app id.
 * @param {string} one The singular.
 * @param {string} many The plural.
 * @param {number} count The count.
 * @return {string} The text.
 */
function n(app, one, many, count) {
	return (count === 1 ? one : many).replace('%n', String(count))
}
globalThis.t = t
globalThis.n = n

const { default: DestructionListDecisionModal } = await import('../../src/modals/DestructionListDecisionModal.vue')
const { default: ContactSocialProfileModal } = await import('../../src/modals/ContactSocialProfileModal.vue')
const { default: CtiDispositionModal } = await import('../../src/modals/CtiDispositionModal.vue')
const { default: SocialPostComposeModal } = await import('../../src/modals/SocialPostComposeModal.vue')

/**
 * Mount a dialog with the translation helpers.
 *
 * @param {object} component The dialog.
 * @param {object} [props] Its props.
 * @return {object} The wrapper.
 */
function open(component, props = {}) {
	return mount(component, { props, global: { mocks: { t, n } } })
}

/**
 * The NcButton stub whose text matches.
 *
 * @param {object} wrapper The dialog.
 * @param {string} text The button text.
 * @return {object} The stub.
 */
function button(wrapper, text) {
	return wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.text() === text)
}

/**
 * Dismiss the dialog and press Cancel; both must close it.
 *
 * @param {object} wrapper The dialog.
 * @return {Promise<void>}
 */
async function dismissAndCancel(wrapper) {
	await wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
	await button(wrapper, 'Cancel').vm.$emit('click')
}

describe('the destruction list decision', () => {
	it('approves with an empty payload and counts the records in the sentence', async () => {
		const wrapper = open(DestructionListDecisionModal, { decision: 'approve', entryCount: 3 })

		expect(wrapper.findComponent({ name: 'CnDialog' }).props('name')).toBe('Approve destruction list')
		expect(wrapper.text()).toContain('Approving lets OpenRegister destroy 3 records on this list.')
		expect(button(wrapper, 'Approve').props('variant')).toBe('error')
		await button(wrapper, 'Approve').vm.$emit('click')

		expect(wrapper.emitted('confirm')).toEqual([[{}]])
	})

	it('only rejects with a reason, sent trimmed', async () => {
		const wrapper = open(DestructionListDecisionModal, { decision: 'reject' })
		expect(wrapper.findComponent({ name: 'CnDialog' }).props('name')).toBe('Reject destruction list')
		expect(button(wrapper, 'Reject').props('disabled')).toBe(true)

		wrapper.vm.confirm()
		await wrapper.findComponent({ name: 'NcTextArea' }).vm.$emit('update:modelValue', '   ')
		wrapper.vm.confirm()
		expect(wrapper.emitted('confirm')).toBeUndefined()

		await wrapper.findComponent({ name: 'NcTextArea' }).vm.$emit('update:modelValue', '  Still in use  ')
		expect(button(wrapper, 'Reject').props('disabled')).toBe(false)
		await button(wrapper, 'Reject').vm.$emit('click')

		expect(wrapper.emitted('confirm')).toEqual([[{ reason: 'Still in use' }]])
	})

	it('cannot be confirmed while busy, and closes when dismissed or cancelled', async () => {
		const wrapper = open(DestructionListDecisionModal, { decision: 'approve', busy: true })
		expect(button(wrapper, 'Approve').props('disabled')).toBe(true)

		await dismissAndCancel(wrapper)

		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})

describe('the social profile dialog', () => {
	it('saves the chosen network and the follow switches', async () => {
		const wrapper = open(ContactSocialProfileModal)
		const select = wrapper.findComponent({ name: 'NcSelect' })
		await select.vm.$emit('update:modelValue', 'mastodon')
		const switches = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		await switches[1].vm.$emit('update:modelValue', true)
		await switches[2].vm.$emit('update:modelValue', true)
		await wrapper.findAllComponents({ name: 'NcTextField' })[0].vm.$emit('update:modelValue', ' @anna ')

		await button(wrapper, 'Save').vm.$emit('click')

		expect(wrapper.emitted('save')).toEqual([[{
			network: 'mastodon',
			handle: '@anna',
			url: '',
			verified: false,
			followedByUs: true,
			followsUs: true,
		}]])
	})

	it('closes when dismissed or cancelled', async () => {
		const wrapper = open(ContactSocialProfileModal)

		await dismissAndCancel(wrapper)

		expect(wrapper.emitted('close')).toHaveLength(2)
		expect(wrapper.emitted('save')).toBeUndefined()
	})
})

describe('the call disposition dialog', () => {
	const queues = [{ label: 'Second line', value: 'queue-2' }]

	it('offers the escalation queue only for an escalation, and sends the chosen queue', async () => {
		const wrapper = open(CtiDispositionModal, { queueOptions: queues })
		expect(wrapper.find('[data-testid="cti-disposition-queue"]').exists()).toBe(false)

		await wrapper.findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', 'Invoice question')
		await wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })[0].vm.$emit('update:modelValue', 'escalated')
		const queue = wrapper.findAllComponents({ name: 'NcSelect' }).find((s) => s.attributes('data-testid') === 'cti-disposition-queue')
		expect(queue.props('options')).toEqual(queues)
		expect(queue.vm.$attrs.reduce(queues[0])).toBe('queue-2')
		await queue.vm.$emit('update:modelValue', 'queue-2')

		wrapper.vm.submit()

		expect(wrapper.emitted('submit')).toEqual([[{ subject: 'Invoice question', outcome: 'escalated', notes: '', queue: 'queue-2' }]])
	})

	it('closes when dismissed or cancelled', async () => {
		const wrapper = open(CtiDispositionModal)

		await wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		await wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.attributes('data-testid') === 'cti-disposition-cancel').vm.$emit('click')

		expect(wrapper.emitted('close')).toHaveLength(2)
		expect(wrapper.emitted('submit')).toBeUndefined()
	})
})

describe('the social post composer', () => {
	beforeEach(() => {
		socialApi.fetchAccounts.mockReset()
	})

	it('keeps the typed link and schedule, picks accounts by value, and lists only active accounts', async () => {
		socialApi.fetchAccounts.mockResolvedValue({ data: [{ id: 'a1', active: true }, { id: 'a2', active: false }, { id: 'a3' }] })
		const wrapper = open(SocialPostComposeModal)
		await flushPromises()

		expect(wrapper.vm.accounts.map((account) => account.id)).toEqual(['a1', 'a3'])
		await wrapper.find('#social-compose-link').setValue('https://voorbeeld.nl/nieuws')
		await wrapper.find('#social-compose-schedule').setValue('2026-12-01T09:30')
		expect(wrapper.vm.model.link).toBe('https://voorbeeld.nl/nieuws')
		expect(wrapper.vm.model.scheduledFor).toBe('2026-12-01T09:30')

		const accounts = wrapper.findAllComponents({ name: 'NcSelect' }).find((s) => s.props('inputLabel') === 'Accounts')
		expect(accounts.vm.$attrs.reduce({ label: 'Mastodon', value: 'a1' })).toBe('a1')
	})

	it('says so when the accounts cannot be loaded, and closes when dismissed', async () => {
		socialApi.fetchAccounts.mockRejectedValue(new Error('down'))
		const wrapper = open(SocialPostComposeModal)
		await flushPromises()

		expect(wrapper.vm.error).toBe('The accounts could not be loaded.')
		await wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')

		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
