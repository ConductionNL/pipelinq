/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Branches of three forms pinned before they were split into named steps:
 * the e-mail/phone channel modal, the social profile modal's start state,
 * and the VAT rate settings.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: {} }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcCheckboxRadioSwitch: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
	NcSelect: {},
	NcSettingsSection: {},
	NcTextField: {},
}))

let channelModal
let socialModal
let vat

beforeAll(async () => {
	globalThis.t = (app, text) => text
	channelModal = (await import('../../src/modals/ContactEmailPhoneModal.vue')).default
	socialModal = (await import('../../src/modals/ContactSocialProfileModal.vue')).default
	vat = await import('../../src/views/settings/VatRatesSettings.vue')
}, 60000)

beforeEach(() => {
	http.post.mockReset()
})

describe('ContactEmailPhoneModal.submit', () => {
	/**
	 * @param {string} channelType The channel.
	 * @param {object} form The form.
	 * @return {object} A modal context after submit.
	 */
	function submit(channelType, form) {
		const ctx = { ...channelModal.methods, channelType, form, error: '', $emit: vi.fn() }
		channelModal.methods.submit.call(ctx)
		return ctx
	}

	it('saves a valid value, trimmed, with its flags as booleans', () => {
		const ctx = submit('email', { kind: 'work', value: ' a@b.nl ', primary: 1 })
		expect(ctx.$emit).toHaveBeenCalledWith('save', { kind: 'work', value: 'a@b.nl', primary: true, verified: false })
		expect(ctx.error).toBe('')
		expect(submit('phone', { kind: 'home', value: '+31 6 1234 5678', verified: 'y' }).$emit)
			.toHaveBeenCalledWith('save', { kind: 'home', value: '+31 6 1234 5678', primary: false, verified: true })
		expect(submit('fax', { value: 'anything' }).$emit).toHaveBeenCalledWith('save', { kind: undefined, value: 'anything', primary: false, verified: false })
	})

	it('refuses an empty or malformed value', () => {
		const cases = [
			['email', '  ', 'Email address is required'],
			['email', undefined, 'Email address is required'],
			['phone', '', 'Phone number is required'],
			['fax', '', 'Phone number is required'],
			['email', 'nope', 'Invalid email format'],
			['phone', 'abc', 'Invalid phone format'],
		]
		for (const [channelType, value, error] of cases) {
			const ctx = submit(channelType, { value })
			expect(ctx.error).toBe(error)
			expect(ctx.$emit).not.toHaveBeenCalled()
		}
	})
})

describe('ContactSocialProfileModal.data', () => {
	it('starts from the profile, or empty on LinkedIn', () => {
		const filled = socialModal.data.call({ profile: { network: 'x', handle: '@a', url: 'https://x.com/a', verified: true, followedByUs: true, followsUs: true } })
		expect(filled.form).toEqual({ network: 'x', handle: '@a', url: 'https://x.com/a', verified: true, followedByUs: true, followsUs: true })
		expect(Object.keys(filled.form)).toEqual(['network', 'handle', 'url', 'verified', 'followedByUs', 'followsUs'])
		expect(filled.error).toBe('')
		expect(filled.networkOptions).toEqual(['linkedin', 'x', 'mastodon', 'bluesky', 'facebook', 'instagram', 'threads', 'tiktok', 'youtube', 'other'])
		const empty = socialModal.data.call({ profile: null })
		expect(empty.form).toEqual({ network: 'linkedin', handle: '', url: '', verified: false, followedByUs: false, followsUs: false })
		expect(socialModal.data.call({}).networkOptions).not.toBe(empty.networkOptions)
	})
})

describe('VatRatesSettings', () => {
	it('reads stored rates over the defaults', () => {
		expect(vat.parseVatRates(undefined)).toEqual({ high: 21, low: 9, zero: 0, exempt: 0 })
		expect(vat.parseVatRates('null')).toEqual({ high: 21, low: 9, zero: 0, exempt: 0 })
		expect(vat.parseVatRates('{bad')).toEqual({ high: 21, low: 9, zero: 0, exempt: 0 })
		expect(vat.parseVatRates('{"high":"20","low":"x","zero":null,"other":5}')).toEqual({ high: 20, low: 9, zero: 0, exempt: 0 })
	})

	/**
	 * @param {object} rates The rates typed in.
	 * @return {object} A settings context.
	 */
	function ctx(rates) {
		return { ...vat.default.methods, rates, message: 'old', messageType: '', saving: false }
	}

	it('saves rates from 0 to 100', async () => {
		http.post.mockResolvedValue({})
		const c = ctx({ high: '21', low: 9, zero: 0, exempt: '100' })
		const pending = vat.default.methods.save.call(c)
		expect(c.message).toBe('')
		expect(c.saving).toBe(true)
		await pending
		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/settings', { vat_rates: '{"high":21,"low":9,"zero":0,"exempt":100}' })
		expect(c.message).toBe('VAT rates saved. Reload the app to see the new labels.')
		expect(c.messageType).toBe('success')
		expect(c.saving).toBe(false)
	})

	it('refuses a rate out of range, and says when the save failed', async () => {
		for (const bad of [{ high: -1 }, { high: 101 }, { high: 'x' }]) {
			const c = ctx({ high: 21, low: 9, zero: 0, exempt: 0, ...bad })
			await vat.default.methods.save.call(c)
			expect(c.message).toBe('Each rate must be a number from 0 to 100.')
			expect(c.messageType).toBe('error')
			expect(c.saving).toBe(false)
		}
		expect(http.post).not.toHaveBeenCalled()
		http.post.mockRejectedValue(new Error('x'))
		const c = ctx({ high: 21, low: 9, zero: 0, exempt: 0 })
		await vat.default.methods.save.call(c)
		expect(c.message).toBe('Could not save the VAT rates.')
		expect(c.messageType).toBe('error')
		expect(c.saving).toBe(false)
	})
})
