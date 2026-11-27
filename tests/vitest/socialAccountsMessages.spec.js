// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Messages line on the Social accounts page: a Facebook page or Instagram
 * business account says whether its visitor messages reach the queue, apart
 * from posting. Until the broker admits the conversations call and Meta grants
 * the messaging permissions it reads "Messages: waiting on Meta"; a network
 * without messages has no line.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-facebook-and-instagram-messages-reach-the-queue-req-itq-004
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

const { messagingLine, MESSAGING_NETWORKS } = await import('../../src/services/socialNetworks.js')

describe('messagingLine', () => {
	it('has no line for a network without messages', () => {
		expect(messagingLine({ network: 'mastodon', messagingStatus: 'receiving' })).toBeNull()
		expect(messagingLine(null)).toBeNull()
		expect(MESSAGING_NETWORKS).toEqual(['facebook', 'instagram'])
	})

	it('reads an account not read yet as waiting', () => {
		expect(messagingLine({ network: 'facebook' })).toEqual({ state: 'waiting', reason: '' })
	})

	it('reads a broker or network refusal as waiting, with its reason', () => {
		for (const code of ['not_permitted', 'rejected_by_network', 'not_configured']) {
			expect(messagingLine({ network: 'instagram', messagingStatus: code, messagingStatusReason: 'No rule allows this path' }))
				.toEqual({ state: 'waiting', reason: 'No rule allows this path' })
		}
	})

	it('tells receiving, reconnect, paused and retrying apart', () => {
		expect(messagingLine({ network: 'facebook', messagingStatus: 'receiving', messagingStatusReason: 'stale' }))
			.toEqual({ state: 'receiving', reason: '' })
		expect(messagingLine({ network: 'facebook', messagingStatus: 'relink_needed' }).state).toBe('relink')
		expect(messagingLine({ network: 'facebook', messagingStatus: 'budget_exhausted' }).state).toBe('paused')
		expect(messagingLine({ network: 'facebook', messagingStatus: 'unavailable' }).state).toBe('retrying')
	})
})

describe('the Messages line on the page', () => {
	const accounts = [
		{ id: 'm1', network: 'mastodon', handle: '@gemeente', status: 'active' },
		{ id: 'f1', network: 'facebook', displayName: 'Gemeente', status: 'active', messagingStatus: 'not_permitted', messagingStatusReason: 'The broker does not allow this call' },
		{ id: 'i1', network: 'instagram', displayName: 'Gemeente', status: 'active', messagingStatus: 'receiving' },
		{ id: 'f2', network: 'facebook', displayName: 'Bibliotheek', status: 'active', messagingStatus: 'relink_needed' },
		{ id: 'f3', network: 'facebook', displayName: 'Sport', status: 'active', messagingStatus: 'budget_exhausted' },
		{ id: 'f4', network: 'facebook', displayName: 'Cultuur', status: 'active', messagingStatus: 'unavailable' },
	]

	beforeEach(() => {
		Object.values(api).forEach((fn) => fn.mockReset())
		api.fetchAccounts.mockResolvedValue({ data: accounts, readiness: {} })
	})

	const line = (wrapper, id) => wrapper.find(`[data-testid="social-account-messages-${id}"]`)

	it('says waiting on Meta, with the reason, until messages are read', async () => {
		const wrapper = mount(SocialAccountsView, { global: { mocks: { t } } })
		await flushPromises()
		expect(line(wrapper, 'f1').text()).toBe('Messages: waiting on Meta')
		expect(wrapper.text()).toContain('The broker does not allow this call')
	})

	it('says receiving once the inbox is read, and the other states in words', async () => {
		const wrapper = mount(SocialAccountsView, { global: { mocks: { t } } })
		await flushPromises()
		expect(line(wrapper, 'i1').text()).toBe('Messages: receiving')
		expect(line(wrapper, 'f2').text()).toBe('Messages: reconnect needed')
		expect(line(wrapper, 'f3').text()).toBe('Messages: paused, the read budget is used up')
		expect(line(wrapper, 'f4').text()).toBe('Messages: not reachable, trying again')
	})

	it('shows no Messages line for a network without messages', async () => {
		const wrapper = mount(SocialAccountsView, { global: { mocks: { t } } })
		await flushPromises()
		expect(line(wrapper, 'm1').exists()).toBe(false)
	})
})
