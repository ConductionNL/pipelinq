// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rendered social accounts list: one row per account with its name,
 * handle only when it adds something, the status chip and reason, and only
 * the buttons that can work. A network with no filing gets its reason and no
 * Connect button; a connected account offers Reconnect and Revoke, and both
 * buttons go to the broker and the revoke endpoint for that row.
 *
 * @spec openspec/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const api = vi.hoisted(() => ({
	fetchAccounts: vi.fn(),
	fetchConnectParameters: vi.fn(),
	startBrokerConnection: vi.fn(),
	attachCredential: vi.fn(),
	revokeAccount: vi.fn(),
}))
vi.mock('../../src/services/socialApi.js', () => api)
vi.mock('../../src/services/socialNetworkIcons.js', () => ({
	networkIcon: () => ({ render: () => h('i') }),
}))
vi.mock('vue-material-design-icons/ShareVariantOutline.vue', () => ({
	default: { render: () => h('i') },
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		emits: ['click'],
		render() {
			return h(
				'button',
				{ disabled: this.disabled, onClick: () => this.$emit('click') },
				this.$slots.default?.(),
			)
		},
	},
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['name'],
		render() {
			return h('div', { class: 'empty' }, this.name)
		},
	},
	NcLoadingIcon: {
		name: 'NcLoadingIcon',
		render: () => h('div', { class: 'loading' }),
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		render() {
			return h('div', { class: 'note-' + this.type }, this.$slots.default?.())
		},
	},
}))

const t = (app, text) => text
globalThis.t = t

const { default: SocialAccountsView } =
	await import('../../src/views/social/SocialAccountsView.vue')

const accounts = [
	{
		id: 'a1',
		network: 'mastodon',
		displayName: 'Gemeente',
		handle: '@gemeente',
		status: 'active',
		credentialRef: 'cred-1',
	},
	{ id: 'a2', network: 'bluesky', handle: 'gem.bsky', status: 'not_configured' },
	{
		uuid: 'a3',
		network: 'linkedin',
		displayName: 'Spokes',
		handle: 'Spokes',
		status: 'pending',
		publishMode: 'share',
	},
]

function mountView(route = {}) {
	return mount(SocialAccountsView, {
		global: { mocks: { t, $route: route } },
	})
}

function row(wrapper, network) {
	return wrapper.find(`[data-testid="social-account-${network}"]`)
}
const buttons = (r) => r.findAll('button').map((b) => b.text())

describe('the rendered social accounts list', () => {
	beforeEach(() => {
		Object.values(api).forEach((fn) => fn.mockReset())
		api.fetchAccounts.mockResolvedValue({
			data: accounts,
			readiness: {
				bluesky: { state: 'not_configured', reason: 'No app filed' },
			},
		})
	})

	it('shows a loader, then one row per account', async () => {
		const wrapper = mountView()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.loading').exists()).toBe(true)
		await flushPromises()
		expect(wrapper.find('.loading').exists()).toBe(false)
		expect(wrapper.findAll('.social-accounts__row')).toHaveLength(3)
	})

	it('names each account and shows the handle only when it differs', async () => {
		const wrapper = mountView()
		await flushPromises()
		const names = wrapper.findAll('.social-accounts__name').map((n) => n.text())
		const meta = wrapper
			.findAll('.social-accounts__meta')
			.map((n) => n.text().replace(/\s+/g, ' '))
		expect(names).toEqual(['Gemeente', 'gem.bsky', 'Spokes'])
		expect(meta).toEqual(['@gemeente · Mastodon', 'Bluesky', 'LinkedIn'])
	})

	it('shows the status chip and the network reason where there is one', async () => {
		const wrapper = mountView()
		await flushPromises()
		expect(row(wrapper, 'mastodon').find('.social-accounts__chip').text()).toBe(
			'Connected',
		)
		expect(row(wrapper, 'bluesky').find('.social-accounts__chip').text()).toBe(
			'Not configured',
		)
		expect(row(wrapper, 'bluesky').find('.social-accounts__reason').text()).toBe(
			'No app filed',
		)
		expect(
			row(wrapper, 'mastodon').find('.social-accounts__reason').exists(),
		).toBe(false)
	})

	it('offers only the buttons that can work', async () => {
		const wrapper = mountView()
		await flushPromises()
		expect(buttons(row(wrapper, 'mastodon'))).toEqual(['Reconnect', 'Revoke'])
		expect(buttons(row(wrapper, 'bluesky'))).toEqual([])
		expect(buttons(row(wrapper, 'linkedin'))).toEqual([])
	})

	it('sends the browser to the broker for the clicked row', async () => {
		api.fetchConnectParameters.mockResolvedValue({ state: 's1' })
		api.startBrokerConnection.mockResolvedValue('https://broker.test/consent')
		const location = { href: '' }
		const original = Object.getOwnPropertyDescriptor(window, 'location')
		Object.defineProperty(window, 'location', {
			value: location,
			configurable: true,
		})
		try {
			const wrapper = mountView()
			await flushPromises()
			await row(wrapper, 'mastodon').findAll('button')[0].trigger('click')
			await flushPromises()
			expect(api.fetchConnectParameters).toHaveBeenCalledWith('a1')
			expect(api.startBrokerConnection).toHaveBeenCalledWith({ state: 's1' })
			expect(location.href).toBe('https://broker.test/consent')
		} finally {
			Object.defineProperty(window, 'location', original)
		}
	})

	it('revokes the clicked row and redraws from the reloaded list', async () => {
		api.revokeAccount.mockResolvedValue({})
		const wrapper = mountView()
		await flushPromises()
		api.fetchAccounts.mockResolvedValue({
			data: [{ ...accounts[0], credentialRef: '', status: 'disabled' }],
			readiness: {},
		})
		await row(wrapper, 'mastodon').findAll('button')[1].trigger('click')
		await flushPromises()
		expect(api.revokeAccount).toHaveBeenCalledWith('a1')
		expect(wrapper.findAll('.social-accounts__row')).toHaveLength(1)
		expect(buttons(row(wrapper, 'mastodon'))).toEqual(['Connect'])
		expect(row(wrapper, 'mastodon').find('.social-accounts__chip').text()).toBe(
			'Disabled',
		)
	})

	it('shows the empty state without accounts and the error when loading fails', async () => {
		api.fetchAccounts.mockResolvedValueOnce({ data: [], readiness: {} })
		const empty = mountView()
		await flushPromises()
		expect(empty.find('.empty').text()).toBe('No social accounts yet.')

		api.fetchAccounts.mockRejectedValueOnce(new Error('down'))
		const failed = mountView()
		await flushPromises()
		expect(failed.find('.note-error').text()).toBe(
			'The accounts could not be loaded.',
		)
	})

	it('shows the success notice after the broker sends the browser back', async () => {
		api.attachCredential.mockResolvedValue({})
		const wrapper = mountView({
			params: { id: 'a1' },
			query: { connected: 'ok' },
		})
		await flushPromises()
		expect(api.attachCredential).toHaveBeenCalledWith('a1')
		expect(wrapper.find('.note-success').text()).toBe(
			'The account is connected.',
		)
		expect(api.fetchAccounts).toHaveBeenCalledTimes(2)
	})
})
