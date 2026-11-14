// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The social accounts page as drawn: a spinner while it reads, an empty state
 * without accounts, and per account its name (with the handle when it
 * differs), network, status chip and reason, Connect or Reconnect unless the
 * network is not configured or only shares, and Revoke for a connected one,
 * which revokes and reloads, or reports why it could not. The compose route
 * returns to the post list or opens the saved post.
 *
 * @spec openspec/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const api = vi.hoisted(() => ({
	attachCredential: vi.fn(),
	fetchAccounts: vi.fn(),
	fetchConnectParameters: vi.fn(),
	revokeAccount: vi.fn(),
	startBrokerConnection: vi.fn(),
}))
vi.mock('../../src/services/socialApi.js', () => api)
vi.mock('../../src/modals/SocialPostComposeModal.vue', () => ({
	default: { name: 'SocialPostComposeModal', emits: ['close', 'saved'], render: () => h('div') },
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		emits: ['click'],
		render() {
			return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
	NcEmptyContent: { name: 'NcEmptyContent', props: ['name'], render() { return h('div', { class: 'empty' }, this.name) } },
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('div', { class: 'spinner' }) },
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], render() { return h('div', { class: 'note-' + this.type }, this.$slots.default?.()) } },
}))

const translate = (_app, text) => text
globalThis.t = translate

const { default: SocialAccountsView } = await import('../../src/views/social/SocialAccountsView.vue')
const { default: SocialPostFormView } = await import('../../src/views/social/SocialPostFormView.vue')

const accounts = [
	{ id: 'm1', network: 'mastodon', displayName: 'Gemeente', handle: '@gemeente@social.nl', status: 'active', credentialRef: 'ref-1' },
	{ id: 'b1', network: 'bluesky', displayName: 'gemeente.bsky.social', handle: 'gemeente.bsky.social', status: 'pending' },
	{ uuid: 'l1', network: 'linkedin', handle: 'gemeente', status: 'pending' },
	{ id: 'f1', network: 'facebook', displayName: 'Gemeente', status: 'pending', publishMode: 'share', statusReason: 'Shared by hand.' },
]

/**
 * @return {Promise<object>} The page after its first read.
 */
async function loaded() {
	const wrapper = mount(SocialAccountsView, { global: { mocks: { t: translate } } })
	await flushPromises()
	return wrapper
}

/**
 * @param {object} row A list row.
 * @return {Array<string>} Its button labels.
 */
function buttonsOf(row) {
	return row.findAll('button').map((b) => b.text())
}

beforeEach(() => {
	for (const fn of Object.values(api)) {
		fn.mockReset()
	}
	api.fetchAccounts.mockResolvedValue({ data: accounts, readiness: { linkedin: { state: 'not_configured', reason: 'No LinkedIn app is set up.' } } })
})

describe('the social accounts page', () => {
	it('shows a spinner while the accounts are read', async () => {
		api.fetchAccounts.mockReturnValue(new Promise(() => {}))
		const wrapper = mount(SocialAccountsView, { global: { mocks: { t: translate } } })
		await flushPromises()

		expect(wrapper.find('.spinner').exists()).toBe(true)
		expect(wrapper.find('ul').exists()).toBe(false)
	})

	it('shows an empty state without accounts', async () => {
		api.fetchAccounts.mockResolvedValue({ data: [], readiness: {} })
		const wrapper = await loaded()

		expect(wrapper.find('.empty').text()).toBe('No social accounts yet.')
	})

	it('draws each account with its name, handle when it differs, network, status and reason', async () => {
		const rows = (await loaded()).findAll('li')

		expect(rows.map((row) => row.attributes('data-testid'))).toEqual(['social-account-mastodon', 'social-account-bluesky', 'social-account-linkedin', 'social-account-facebook'])
		expect(rows.map((row) => row.find('.social-accounts__name').text())).toEqual(['Gemeente', 'gemeente.bsky.social', 'gemeente', 'Gemeente'])
		expect(rows.map((row) => row.find('.social-accounts__meta').text().replace(/\s+/g, ' '))).toEqual([
			'@gemeente@social.nl · Mastodon',
			'Bluesky',
			'LinkedIn',
			'Facebook page',
		])
		expect(rows[0].find('.social-accounts__chip').text()).toBe('Connected')
		expect(rows[1].find('.social-accounts__chip').text()).toBe('Connecting')
		expect(rows[0].find('.social-accounts__reason').exists()).toBe(false)
		expect(rows[2].find('.social-accounts__reason').text()).toBe('No LinkedIn app is set up.')
		expect(rows[3].find('.social-accounts__reason').text()).toBe('Shared by hand.')
	})

	it('offers Reconnect and Revoke for a connected account, Connect for a new one, and neither for an unconfigured or share-only network', async () => {
		const rows = (await loaded()).findAll('li')

		expect(rows.map(buttonsOf)).toEqual([['Reconnect', 'Revoke'], ['Connect'], [], []])
	})

	it('sends the browser to the broker on Connect', async () => {
		const location = window.location
		delete window.location
		window.location = { href: '' }
		api.fetchConnectParameters.mockResolvedValueOnce({ provider: 'bluesky' })
		api.startBrokerConnection.mockResolvedValueOnce('https://broker/consent')
		const wrapper = await loaded()

		await wrapper.findAll('li')[1].find('button').trigger('click')
		await flushPromises()

		expect(api.fetchConnectParameters).toHaveBeenCalledWith('b1')
		expect(api.startBrokerConnection).toHaveBeenCalledWith({ provider: 'bluesky' })
		expect(window.location.href).toBe('https://broker/consent')
		window.location = location
	})

	it('revokes a connected account and reloads the list', async () => {
		api.revokeAccount.mockResolvedValueOnce({})
		const wrapper = await loaded()
		api.fetchAccounts.mockResolvedValueOnce({ data: [], readiness: {} })

		await wrapper.findAll('li')[0].findAll('button')[1].trigger('click')
		await flushPromises()

		expect(api.revokeAccount).toHaveBeenCalledWith('m1')
		expect(api.fetchAccounts).toHaveBeenCalledTimes(2)
		expect(wrapper.find('.empty').exists()).toBe(true)
		expect(wrapper.vm.busy).toBe('')
	})

	it('says why a revoke failed, else that it could not be revoked', async () => {
		api.revokeAccount
			.mockRejectedValueOnce({ response: { data: { error: 'Broker unreachable.' } } })
			.mockRejectedValueOnce(new Error('down'))
		const wrapper = await loaded()

		await wrapper.vm.revoke(accounts[0])
		await flushPromises()
		expect(wrapper.find('.note-error').text()).toBe('Broker unreachable.')

		await wrapper.vm.revoke(accounts[0])
		expect(wrapper.vm.error).toBe('The connection could not be revoked.')
		expect(wrapper.vm.busy).toBe('')
		expect(api.fetchAccounts).toHaveBeenCalledTimes(1)
	})

	it('shows the notice once a returning connection is recorded', async () => {
		api.attachCredential.mockResolvedValueOnce({})
		const wrapper = mount(SocialAccountsView, { global: { mocks: { t: translate, $route: { params: { id: 'b1' }, query: { connected: 'ok' } } } } })
		await flushPromises()

		expect(api.attachCredential).toHaveBeenCalledWith('b1')
		expect(wrapper.find('.note-success').text()).toBe('The account is connected.')
	})
})

describe('the compose route', () => {
	/**
	 * @return {{wrapper: object, push: Function}} The view and its router push.
	 */
	function mountView() {
		const push = vi.fn()
		return { wrapper: mount(SocialPostFormView, { global: { mocks: { $router: { push } } } }), push }
	}

	it('returns to the post list when the form closes', async () => {
		const { wrapper, push } = mountView()
		await wrapper.findComponent({ name: 'SocialPostComposeModal' }).vm.$emit('close')

		expect(push).toHaveBeenCalledWith({ name: 'SocialPosts' })
	})

	it.each([
		[{ id: 'p1', uuid: 'u1' }, { name: 'SocialPostDetail', params: { id: 'p1' } }],
		[{ uuid: 'u1' }, { name: 'SocialPostDetail', params: { id: 'u1' } }],
		[null, { name: 'SocialPosts' }],
	])('after saving %j goes to %j', async (post, route) => {
		const { wrapper, push } = mountView()
		await wrapper.findComponent({ name: 'SocialPostComposeModal' }).vm.$emit('saved', post)

		expect(push).toHaveBeenCalledWith(route)
	})
})
