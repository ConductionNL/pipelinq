// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Writing an email to a contact opens it in Mail when the Mail app is enabled
 * and in the reader's own mail program otherwise, with the trimmed subject and
 * the text (or a picked saved reply). The missing consent dialog counts the
 * contacts per channel, lists at most 25 and names a contact by the first
 * thing it has, and passes each choice on.
 *
 * @spec openspec/specs/messaging-saved-replies/spec.md#requirement-an-agent-starts-an-email-from-a-saved-reply-req-msr-005
 */
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const state = vi.hoisted(() => ({ statuses: {} }))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => {
		if (state.statuses instanceof Error) {
			throw state.statuses
		}
		return key === 'dependency_statuses' ? state.statuses : fallback
	},
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('../../src/components/SavedReplyPicker.vue', () => ({
	default: { name: 'SavedReplyPicker', props: ['channel', 'language', 'values'], emits: ['pick'], render: () => h('div') },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		props: ['name'],
		emits: ['closing'],
		render() {
			return h('div', [this.$slots.default?.(), this.$slots.actions?.()])
		},
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', props: ['variant'], emits: ['click'], render() { return h('button', { onClick: () => this.$emit('click') }, this.$slots.default?.()) } },
	NcTextField: { name: 'NcTextField', props: ['modelValue', 'label'], render: () => h('div') },
	NcTextArea: { name: 'NcTextArea', props: ['modelValue', 'label'], render: () => h('div') },
}))

const translate = (_app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)
const plural = (app, one, many, count, vars = {}) => translate(app, count === 1 ? one : many, vars).replace('%n', String(count))
globalThis.t = translate
globalThis.n = plural

const { default: WriteEmailModal } = await import('../../src/modals/WriteEmailModal.vue')
const { default: MissingConsentModal } = await import('../../src/modals/MissingConsentModal.vue')

const global = { mocks: { t: translate, n: plural } }

/**
 * @param {object} wrapper The dialog.
 * @param {string} label The button text.
 * @return {object} The button.
 */
function button(wrapper, label) {
	return wrapper.findAll('button').find((b) => b.text() === label)
}

describe('writing an email', () => {
	const location = window.location

	beforeEach(() => {
		state.statuses = {}
		vi.stubGlobal('open', vi.fn())
		delete window.location
		window.location = { href: '' }
	})

	afterEach(() => {
		window.location = location
		vi.unstubAllGlobals()
	})

	it('opens Mail in a new tab with the trimmed subject and the text, then closes', async () => {
		state.statuses = { mail: { enabled: true } }
		const wrapper = mount(WriteEmailModal, { global, props: { address: 'jan@example.nl' } })
		expect(wrapper.find('.write-email__to').text()).toBe('To jan@example.nl')
		wrapper.vm.subject = '  Uw aanvraag '
		wrapper.vm.body = 'Hallo Jan'

		await button(wrapper, 'Open in Mail').trigger('click')

		expect(window.open).toHaveBeenCalledWith(
			'/index.php/apps/mail/compose?uri=' + encodeURIComponent('mailto:jan@example.nl?subject=Uw%20aanvraag&body=Hallo%20Jan'),
			'_blank',
			'noopener',
		)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('falls back to the reader\'s mail program when Mail is not enabled', async () => {
		state.statuses = { mail: { enabled: false } }
		const wrapper = mount(WriteEmailModal, { global, props: { address: 'jan@example.nl' } })
		expect(wrapper.find('.write-email__hint').text()).toBe('The Mail app is not enabled, so the email opens in your own mail program.')

		await button(wrapper, 'Open in your mail program').trigger('click')

		expect(window.open).not.toHaveBeenCalled()
		expect(window.location.href).toBe('mailto:jan@example.nl')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('treats unreadable dependency statuses as Mail not enabled', () => {
		state.statuses = new Error('no state')
		const wrapper = mount(WriteEmailModal, { global, props: { address: 'a@b.nl' } })

		expect(wrapper.vm.mailEnabled).toBe(false)
		expect(wrapper.vm.url).toBe('mailto:a@b.nl')
	})

	it('puts a picked saved reply in the message, and hands the picker the channel, language and values', async () => {
		const wrapper = mount(WriteEmailModal, { global, props: { address: 'a@b.nl', language: 'nl', placeholderValues: { name: 'Jan' } } })
		const picker = wrapper.findComponent({ name: 'SavedReplyPicker' })

		expect([picker.props('channel'), picker.props('language'), picker.props('values')]).toEqual(['email', 'nl', { name: 'Jan' }])
		await picker.vm.$emit('pick', 'Beste Jan,')
		expect(wrapper.vm.body).toBe('Beste Jan,')
		expect(wrapper.vm.url).toBe('mailto:a@b.nl?body=Beste%20Jan%2C')
	})
})

describe('the missing consent dialog', () => {
	it('counts the contacts missing email consent and names each by name, email, phone or as unknown', () => {
		const wrapper = mount(MissingConsentModal, {
			global,
			props: {
				contacts: [
					{ id: 1, name: 'Anna', email: 'anna@x.nl', reason: 'Withdrawn' },
					{ id: 2, email: 'bert@x.nl' },
					{ id: 3, phone: '0612345678' },
					{ id: 4 },
				],
			},
		})

		expect(wrapper.find('.missing-consent__intro').text()).toBe('4 contacts in this segment are missing email consent. Choose how to proceed.')
		expect(wrapper.findAll('.missing-consent__name').map((n) => n.text())).toEqual(['Anna', 'bert@x.nl', '0612345678', 'Unknown contact'])
		expect(wrapper.findAll('.missing-consent__reason').map((n) => n.text())).toEqual(['Withdrawn'])
		expect(wrapper.find('.missing-consent__more').exists()).toBe(false)
	})

	it('speaks of SMS consent for the SMS channel, in the singular for one contact', () => {
		const wrapper = mount(MissingConsentModal, { global, props: { channel: 'sms', contacts: [{ id: 1, name: 'Anna' }] } })

		expect(wrapper.find('.missing-consent__intro').text()).toBe('1 contact in this segment is missing SMS consent. Choose how to proceed.')
	})

	it('lists at most 25 contacts and counts the rest', () => {
		const contacts = Array.from({ length: 27 }, (_, i) => ({ id: i, name: 'C' + i }))
		const wrapper = mount(MissingConsentModal, { global, props: { contacts } })

		expect(wrapper.findAll('li')).toHaveLength(25)
		expect(wrapper.find('.missing-consent__more').text()).toBe('2 more contacts not shown')
	})

	it('shows no list without contacts', () => {
		const wrapper = mount(MissingConsentModal, { global })

		expect(wrapper.find('ul').exists()).toBe(false)
		expect(wrapper.find('.missing-consent__intro').text()).toBe('0 contacts in this segment are missing email consent. Choose how to proceed.')
	})

	it.each([['Cancel', 'cancel'], ['Request consent', 'requestConsent'], ['Skip and send', 'skipAndSend']])('passes on %s', async (label, event) => {
		const wrapper = mount(MissingConsentModal, { global, props: { contacts: [{ id: 1 }] } })
		await button(wrapper, label).trigger('click')

		expect(wrapper.emitted(event)).toHaveLength(1)
	})
})
