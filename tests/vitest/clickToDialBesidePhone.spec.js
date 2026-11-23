// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The click-to-dial button beside a phone number: shown only when the agent's
 * dial line is on, dialling that number from the agent's own extension,
 * disabled during a call, and mounted beside the phone numbers of the client
 * contact strip and the Channels tab of a client or contact.
 *
 * @spec openspec/changes/specify-capability-rows/tasks.md#task-36.1
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({
	NcActionButton: { template: '<button class="nc-action"><slot /></button>' },
	NcActions: { template: '<div class="nc-actions"><slot name="icon" /><slot /></div>' },
	NcButton: {
		props: ['variant', 'ariaLabel', 'disabled'],
		emits: ['click'],
		template: '<button class="nc-button" :aria-label="ariaLabel" :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcLoadingIcon: { template: '<i class="loading" />' },
}))
const cti = vi.hoisted(() => ({ clickToDial: vi.fn() }))
vi.mock('../../src/services/ctiApi.js', () => cti)
vi.mock('../../src/modals/ContactEmailPhoneModal.vue', () => ({ default: { template: '<div />' } }))
vi.mock('../../src/modals/ContactSocialProfileModal.vue', () => ({ default: { template: '<div />' } }))
vi.mock('../../src/modals/WriteEmailModal.vue', () => ({ default: { template: '<div />' } }))
vi.mock('../../src/services/contactSyncApi.js', () => ({ writeBack: vi.fn() }))
const store = vi.hoisted(() => ({ fetchObject: vi.fn(), saveObject: vi.fn() }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))

globalThis.t = (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => String(vars[k] ?? m))
const { forgetDialLine } = await import('../../src/services/dialLine.js')
const { default: CtiClickToDialButton } = await import('../../src/components/CtiClickToDialButton.vue')
const { default: ContactLinks } = await import('../../src/components/ContactLinks.vue')
const { default: ContactChannelsSection } = await import('../../src/components/ContactChannelsSection.vue')

const global = { mocks: { t: globalThis.t } }

/**
 * @param {object} data What GET /api/cti/dial-line answers.
 */
function lineAnswers(data) {
	http.get.mockResolvedValue({ data })
}

beforeEach(() => {
	forgetDialLine()
	http.get.mockReset()
	cti.clickToDial.mockReset()
})

describe('the button beside a number', () => {
	it('dials the number from the agent\'s own extension', async () => {
		lineAnswers({ enabled: true, extension: '101', busy: false })
		cti.clickToDial.mockResolvedValue({ success: true })
		const wrapper = mount(CtiClickToDialButton, { props: { targetNumber: '+31612987654', showNumber: false }, global })
		await flushPromises()

		const button = wrapper.find('[data-testid="cti-click-to-dial-btn"]')
		expect(button.exists()).toBe(true)
		expect(button.attributes('aria-label')).toBe('Call +31612987654')
		expect(wrapper.find('a').exists()).toBe(false)
		expect(http.get).toHaveBeenCalledWith('/apps/pipelinq/api/cti/dial-line')

		await button.trigger('click')
		await flushPromises()
		expect(cti.clickToDial).toHaveBeenCalledWith('+31612987654', '101')
		expect(wrapper.emitted('initiated')).toHaveLength(1)
	})

	it('shows nothing when no platform is configured or the read fails', async () => {
		lineAnswers({ enabled: false, extension: '', busy: false })
		const off = mount(CtiClickToDialButton, { props: { targetNumber: '0612345678', showNumber: false }, global })
		await flushPromises()
		expect(off.find('[data-testid="cti-click-to-dial-btn"]').exists()).toBe(false)
		expect(off.find('.cti-click-to-dial').exists()).toBe(false)

		forgetDialLine()
		http.get.mockRejectedValue(new Error('403'))
		const failed = mount(CtiClickToDialButton, { props: { targetNumber: '0612345678' }, global })
		await flushPromises()
		expect(failed.find('[data-testid="cti-click-to-dial-btn"]').exists()).toBe(false)
		expect(failed.find('a').attributes('href')).toBe('tel:0612345678')
	})

	it('is disabled while the agent is on a call and places none', async () => {
		lineAnswers({ enabled: true, extension: '101', busy: true })
		const wrapper = mount(CtiClickToDialButton, { props: { targetNumber: '0612345678', showNumber: false }, global })
		await flushPromises()

		const button = wrapper.find('[data-testid="cti-click-to-dial-btn"]')
		expect(button.attributes('disabled')).toBeDefined()
		expect(button.attributes('aria-label')).toBe('Cannot initiate call while on another call')
		await wrapper.vm.dial()
		expect(cti.clickToDial).not.toHaveBeenCalled()
	})

	it('reads the line once for every number on the page', async () => {
		lineAnswers({ enabled: true, extension: '101', busy: false })
		mount(CtiClickToDialButton, { props: { targetNumber: '0612345678' }, global })
		mount(CtiClickToDialButton, { props: { targetNumber: '0201234567' }, global })
		await flushPromises()
		expect(http.get).toHaveBeenCalledTimes(1)
	})

	it('with an extension given, reads no line', async () => {
		const wrapper = mount(CtiClickToDialButton, { props: { targetNumber: '0612345678', extension: '305' }, global })
		await flushPromises()
		expect(http.get).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="cti-click-to-dial-btn"]').exists()).toBe(true)
	})
})

describe('where the button is mounted', () => {
	it('sits beside the phone number of the contact strip, dialling the number without spaces', async () => {
		lineAnswers({ enabled: true, extension: '101', busy: false })
		const wrapper = mount(ContactLinks, { props: { phone: '06 1234 5678', email: 'a@example.org', variant: 'buttons' }, global })
		await flushPromises()

		const phoneItem = wrapper.find('[data-testid="contact-link-phone"]').element.parentElement
		const button = wrapper.find('[data-testid="cti-click-to-dial-btn"]')
		expect(phoneItem.contains(button.element)).toBe(true)
		expect(wrapper.findAll('[data-testid="cti-click-to-dial-btn"]')).toHaveLength(1)
		await button.trigger('click')
		expect(cti.clickToDial).toHaveBeenCalledWith('0612345678', '101')
	})

	it('is not in the contact strip without a dial line', async () => {
		lineAnswers({ enabled: false, extension: '', busy: false })
		const wrapper = mount(ContactLinks, { props: { phone: '06 1234 5678' }, global })
		await flushPromises()
		expect(wrapper.find('[data-testid="cti-click-to-dial-btn"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="contact-link-phone"]').exists()).toBe(true)
	})

	it('sits on each phone row of the Channels tab and on no e-mail row', async () => {
		lineAnswers({ enabled: true, extension: '101', busy: false })
		store.fetchObject.mockResolvedValue({
			id: 'c1',
			emails: [{ kind: 'work', value: 'a@example.org' }],
			phones: [{ kind: 'mobile', value: '06-1234 5678' }, { kind: 'work', value: '020 123 4567' }],
		})
		const wrapper = mount(ContactChannelsSection, { props: { entityId: 'c1', entityType: 'contact' }, global })
		await flushPromises()

		const buttons = wrapper.findAll('[data-testid="cti-click-to-dial-btn"]')
		expect(buttons.map((b) => b.attributes('aria-label'))).toEqual(['Call 0612345678', 'Call 0201234567'])
		await buttons[1].trigger('click')
		expect(cti.clickToDial).toHaveBeenCalledWith('0201234567', '101')
	})
})
