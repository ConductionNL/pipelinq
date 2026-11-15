// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Four contact dialogs and what they hand back: writing an email (Mail
 * composer or a plain mailto link), the missing-consent warning before a
 * segment send, adding or editing an email address or phone number, and
 * adding or editing a social profile.
 *
 * @spec openspec/specs/messaging-saved-replies/spec.md#requirement-an-agent-starts-an-email-from-a-saved-reply-req-msr-005
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const stateMock = vi.hoisted(() => ({ loadState: vi.fn() }))

/**
 * A stand-in that keeps its props on the element and renders its slots.
 *
 * @param {string} name The component name.
 * @return {object} The component.
 */
function stub(name) {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'options', 'disabled', 'error', 'helperText', 'label', 'name', 'type', 'variant'],
		emits: ['update:modelValue', 'click', 'closing', 'pick'],
		render() {
			return h('div', { 'data-stub': name }, [
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	}
}

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
 * Pluralise like gettext, filling `%n` and `{name}`.
 *
 * @param {string} app The app id.
 * @param {string} one The singular.
 * @param {string} many The plural.
 * @param {number} count The count.
 * @param {object} [vars] The placeholder values.
 * @return {string} The text.
 */
function n(app, one, many, count, vars = {}) {
	return t(app, count === 1 ? one : many, vars).replace('%n', String(count))
}

vi.mock('@nextcloud/initial-state', () => stateMock)
vi.mock('@nextcloud/l10n', async (importOriginal) => ({ ...(await importOriginal()), translate: t }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('../../src/components/SavedReplyPicker.vue', () => ({ default: stub('SavedReplyPicker') }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: stub('CnDialog') }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton'),
	NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
	NcSelect: stub('NcSelect'),
	NcTextArea: stub('NcTextArea'),
	NcTextField: stub('NcTextField'),
}))

globalThis.t = t

const { default: WriteEmailModal } = await import('../../src/modals/WriteEmailModal.vue')
const { default: MissingConsentModal } = await import('../../src/modals/MissingConsentModal.vue')
const { default: ContactEmailPhoneModal } = await import('../../src/modals/ContactEmailPhoneModal.vue')
const { default: ContactSocialProfileModal } = await import('../../src/modals/ContactSocialProfileModal.vue')

/**
 * Mount with the translation helpers.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @return {object} The wrapper.
 */
function mountIt(component, props = {}) {
	return mount(component, {
		props,
		global: { mocks: { t, n } },
	})
}

/**
 * The stand-ins of one component.
 *
 * @param {object} w The wrapper.
 * @param {string} name The component name.
 * @return {Array<object>} The stand-ins.
 */
function all(w, name) {
	return w.findAllComponents({ name })
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('WriteEmailModal', () => {
	const originalLocation = window.location

	beforeEach(() => {
		vi.spyOn(window, 'open').mockImplementation(() => null)
		Object.defineProperty(window, 'location', { value: { href: '' }, writable: true, configurable: true })
	})

	afterEach(() => {
		Object.defineProperty(window, 'location', { value: originalLocation, writable: true, configurable: true })
	})

	it('opens the Mail composer in a new tab with the address, trimmed subject and picked reply, then closes', async () => {
		stateMock.loadState.mockReturnValue({ mail: { enabled: true } })
		const w = mountIt(WriteEmailModal, { address: 'jan@example.nl', language: 'nl', placeholderValues: { 'contact.name': 'Jan' } })

		expect(w.text()).toContain('To jan@example.nl')
		expect(w.text()).toContain('The email opens in Mail, where you check it and send it.')
		const picker = all(w, 'SavedReplyPicker')[0]
		expect(picker.vm.$attrs).toMatchObject({ channel: 'email', language: 'nl', values: { 'contact.name': 'Jan' } })

		await all(w, 'NcTextField')[0].vm.$emit('update:modelValue', '  Your request  ')
		await picker.vm.$emit('pick', 'Dear Jan')
		expect(all(w, 'NcTextArea')[0].props('modelValue')).toBe('Dear Jan')
		await all(w, 'NcButton')[1].vm.$emit('click')

		const mailto = 'mailto:jan@example.nl?subject=Your%20request&body=Dear%20Jan'
		expect(window.open).toHaveBeenCalledWith(
			'/index.php/apps/mail/compose?uri=' + encodeURIComponent(mailto),
			'_blank',
			'noopener',
		)
		expect(window.location.href).toBe('')
		expect(w.emitted('close')).toEqual([[]])
	})

	it('falls back to a mailto link in the same window when Mail is not enabled', async () => {
		stateMock.loadState.mockReturnValue({ mail: { enabled: false } })
		const w = mountIt(WriteEmailModal, { address: 'jan@example.nl' })
		expect(w.text()).toContain('The Mail app is not enabled, so the email opens in your own mail program.')

		await all(w, 'NcButton')[1].vm.$emit('click')

		expect(window.open).not.toHaveBeenCalled()
		expect(window.location.href).toBe('mailto:jan@example.nl')
		expect(w.emitted('close')).toEqual([[]])
	})

	it('treats unreadable dependency state as Mail not enabled, and closes on cancel and on closing', async () => {
		stateMock.loadState.mockImplementation(() => {
			throw new Error('no state')
		})
		const w = mountIt(WriteEmailModal, { address: 'a@b.nl' })
		expect(w.vm.mailEnabled).toBe(false)

		await all(w, 'NcButton')[0].vm.$emit('click')
		await all(w, 'CnDialog')[0].vm.$emit('closing')
		expect(w.emitted('close')).toEqual([[], []])
		expect(window.open).not.toHaveBeenCalled()
	})
})

describe('MissingConsentModal', () => {
	const contact = (i, extra = {}) => ({ id: `c${i}`, ...extra })

	it('summarises the count and channel, and names each contact by its best label', () => {
		const w = mountIt(MissingConsentModal, {
			channel: 'sms',
			contacts: [
				contact(1, { name: 'Anna', reason: 'Withdrawn' }),
				contact(2, { email: 'b@x.nl' }),
				contact(3, { phone: '0612345678' }),
				contact(4),
			],
		})

		expect(w.find('.missing-consent__intro').text()).toBe(
			'4 contacts in this segment are missing SMS consent. Choose how to proceed.',
		)
		expect(w.findAll('.missing-consent__name').map((e) => e.text())).toEqual([
			'Anna',
			'b@x.nl',
			'0612345678',
			'Unknown contact',
		])
		expect(w.findAll('.missing-consent__reason').map((e) => e.text())).toEqual(['Withdrawn'])
		expect(w.find('.missing-consent__more').exists()).toBe(false)
	})

	it('shows at most 25 contacts and counts the rest', () => {
		const contacts = Array.from({ length: 27 }, (_, i) => contact(i, { name: `P${i}` }))
		const w = mountIt(MissingConsentModal, { contacts })

		expect(w.findAll('.missing-consent__name')).toHaveLength(25)
		expect(w.find('.missing-consent__more').text()).toBe('2 more contacts not shown')
		expect(w.find('.missing-consent__intro').text()).toContain('missing email consent')
	})

	it('uses the singular for one contact and hides the list for none', () => {
		const one = mountIt(MissingConsentModal, { contacts: [contact(1, { name: 'A' })] })
		expect(one.find('.missing-consent__intro').text()).toBe(
			'1 contact in this segment is missing email consent. Choose how to proceed.',
		)
		const none = mountIt(MissingConsentModal)
		expect(none.find('.missing-consent__list').exists()).toBe(false)
	})

	it('emits cancel, requestConsent and skipAndSend from its buttons and cancel on closing', async () => {
		const w = mountIt(MissingConsentModal, { contacts: [contact(1)] })
		const buttons = all(w, 'NcButton')
		await buttons[0].vm.$emit('click')
		await buttons[1].vm.$emit('click')
		await buttons[2].vm.$emit('click')
		await all(w, 'CnDialog')[0].vm.$emit('closing')

		expect(w.emitted('cancel')).toEqual([[], []])
		expect(w.emitted('requestConsent')).toEqual([[]])
		expect(w.emitted('skipAndSend')).toEqual([[]])
	})
})

describe('ContactEmailPhoneModal', () => {
	it('titles itself by channel and mode', () => {
		expect(all(mountIt(ContactEmailPhoneModal, { channelType: 'email' }), 'CnDialog')[0].props('name')).toBe('Add email address')
		expect(all(mountIt(ContactEmailPhoneModal, { channelType: 'phone' }), 'CnDialog')[0].props('name')).toBe('Add phone number')
		expect(all(mountIt(ContactEmailPhoneModal, { channelType: 'email', entry: { value: 'a@b.nl' } }), 'CnDialog')[0].props('name')).toBe('Edit email address')
		expect(all(mountIt(ContactEmailPhoneModal, { channelType: 'phone', entry: { value: '0612345678' } }), 'CnDialog')[0].props('name')).toBe('Edit phone number')
	})

	it.each([
		['email', '', 'Email address is required'],
		['email', '   ', 'Email address is required'],
		['email', 'not-an-email', 'Invalid email format'],
		['phone', '', 'Phone number is required'],
		['phone', 'call me', 'Invalid phone format'],
	])('refuses %s value %p with %p and clears the error on the next edit', async (channelType, value, message) => {
		const w = mountIt(ContactEmailPhoneModal, { channelType })
		const field = () => all(w, 'NcTextField')[0]
		await field().vm.$emit('update:modelValue', value)
		await all(w, 'NcButton')[1].vm.$emit('click')

		expect(w.emitted('save')).toBeUndefined()
		expect(field().props('error')).toBe(true)
		expect(field().props('helperText')).toBe(message)

		await field().vm.$emit('update:modelValue', value + 'x')
		expect(field().props('error')).toBe(false)
		expect(field().props('helperText')).toBe('')
	})

	it('saves a new phone number trimmed with defaults', async () => {
		const w = mountIt(ContactEmailPhoneModal, { channelType: 'phone' })
		expect(all(w, 'NcTextField')[0].props('label')).toBe('Phone number')
		expect(all(w, 'NcTextField')[0].props('type')).toBe('text')
		await all(w, 'NcTextField')[0].vm.$emit('update:modelValue', '  +31 (0)6-1234 5678 ')
		await all(w, 'NcButton')[1].vm.$emit('click')

		expect(w.emitted('save')).toEqual([[{ kind: 'work', value: '+31 (0)6-1234 5678', primary: false, verified: false }]])
	})

	it('pre-fills an edited email and saves the changed kind and switches', async () => {
		const w = mountIt(ContactEmailPhoneModal, {
			channelType: 'email',
			entry: { kind: 'private', value: 'jan@example.nl', primary: true },
		})
		expect(all(w, 'NcSelect')[0].props('modelValue')).toBe('private')
		expect(all(w, 'NcSelect')[0].props('options')).toEqual(['work', 'private', 'mobile', 'whatsapp', 'other'])
		expect(all(w, 'NcTextField')[0].props('type')).toBe('email')

		await all(w, 'NcSelect')[0].vm.$emit('update:modelValue', 'other')
		await all(w, 'NcCheckboxRadioSwitch')[1].vm.$emit('update:modelValue', true)
		await all(w, 'NcButton')[1].vm.$emit('click')
		await all(w, 'NcButton')[0].vm.$emit('click')

		expect(w.emitted('save')).toEqual([[{ kind: 'other', value: 'jan@example.nl', primary: true, verified: true }]])
		expect(w.emitted('close')).toEqual([[]])
	})
})

describe('ContactSocialProfileModal', () => {
	it('titles itself by mode and offers the networks', () => {
		const add = mountIt(ContactSocialProfileModal)
		expect(all(add, 'CnDialog')[0].props('name')).toBe('Add social profile')
		expect(all(add, 'NcSelect')[0].props('modelValue')).toBe('linkedin')
		expect(all(add, 'NcSelect')[0].props('options')).toHaveLength(10)
		const edit = mountIt(ContactSocialProfileModal, { profile: { network: 'mastodon', handle: 'jan' } })
		expect(all(edit, 'CnDialog')[0].props('name')).toBe('Edit social profile')
		expect(all(edit, 'NcSelect')[0].props('modelValue')).toBe('mastodon')
	})

	it('needs a handle or a URL', async () => {
		const w = mountIt(ContactSocialProfileModal)
		await all(w, 'NcTextField')[0].vm.$emit('update:modelValue', '   ')
		await all(w, 'NcButton')[1].vm.$emit('click')

		expect(w.emitted('save')).toBeUndefined()
		expect(all(w, 'NcTextField')[1].props('helperText')).toBe('A handle or a profile URL is required')
		expect(all(w, 'NcTextField')[1].props('error')).toBe(true)
	})

	it('refuses a URL that is not http(s), and clears the error when the URL changes', async () => {
		const w = mountIt(ContactSocialProfileModal)
		await all(w, 'NcTextField')[1].vm.$emit('update:modelValue', 'ftp://example.com/jan')
		await all(w, 'NcButton')[1].vm.$emit('click')
		expect(w.emitted('save')).toBeUndefined()
		expect(all(w, 'NcTextField')[1].props('helperText')).toBe('Invalid URL format')

		await all(w, 'NcTextField')[1].vm.$emit('update:modelValue', 'https://example.com/jan')
		expect(all(w, 'NcTextField')[1].props('helperText')).toBe('')
	})

	it('saves the trimmed handle and URL with the switches', async () => {
		const w = mountIt(ContactSocialProfileModal, { profile: { network: 'bluesky', followsUs: true } })
		await all(w, 'NcTextField')[0].vm.$emit('update:modelValue', ' jan.bsky.social ')
		await all(w, 'NcTextField')[1].vm.$emit('update:modelValue', ' https://bsky.app/profile/jan ')
		await all(w, 'NcCheckboxRadioSwitch')[0].vm.$emit('update:modelValue', true)
		await all(w, 'NcButton')[1].vm.$emit('click')

		expect(w.emitted('save')).toEqual([[{
			network: 'bluesky',
			handle: 'jan.bsky.social',
			url: 'https://bsky.app/profile/jan',
			verified: true,
			followedByUs: false,
			followsUs: true,
		}]])
	})

	it('saves a handle alone and closes on cancel and closing', async () => {
		const w = mountIt(ContactSocialProfileModal)
		await all(w, 'NcTextField')[0].vm.$emit('update:modelValue', 'jan')
		await all(w, 'NcButton')[1].vm.$emit('click')
		await all(w, 'NcButton')[0].vm.$emit('click')
		await all(w, 'CnDialog')[0].vm.$emit('closing')

		expect(w.emitted('save')[0][0]).toMatchObject({ network: 'linkedin', handle: 'jan', url: '' })
		expect(w.emitted('close')).toEqual([[], []])
	})
})
