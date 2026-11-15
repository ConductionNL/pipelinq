// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The variants section of a social post as it renders: one card per network
 * of the chosen accounts with that network's text, the agent note, the
 * approvals with their decisions and dates, and a read that fails.
 *
 * @spec openspec/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
	fetchAccounts: vi.fn(),
	fetchPost: vi.fn(),
}))
vi.mock('@nextcloud/event-bus', () => ({ subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: { template: '<span class="loading" />' },
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
}))
vi.mock('../../src/services/socialApi.js', () => api)
vi.mock('../../src/services/socialNetworkIcons.js', () => ({ networkIcon: () => ({ template: '<i />' }) }))

globalThis.t = (_app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const { default: SocialPostVariantsSection } = await import('../../src/components/marketing/SocialPostVariantsSection.vue')

/**
 * Mount the section for post p1.
 *
 * @param {object} props Further props.
 * @return {Promise<object>} The wrapper, after the read.
 */
async function mountSection(props = {}) {
	const wrapper = mount(SocialPostVariantsSection, {
		props: { postId: 'p1', ...props },
		global: { mocks: { t: globalThis.t } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	api.fetchPost.mockReset()
	api.fetchAccounts.mockReset()
	api.fetchAccounts.mockResolvedValue({
		data: [
			{ id: 'a1', network: 'mastodon' },
			{ uuid: 'a2', network: 'mastodon' },
			{ id: 'a3', network: 'linkedin' },
			{ id: 'a4', network: 'bluesky' },
		],
	})
})

describe('the social post variants section', () => {
	it('shows one card per network of the chosen accounts, each with its own text', async () => {
		api.fetchPost.mockResolvedValue({
			body: 'Algemene tekst',
			accountIds: ['a1', 'a2', 'a3'],
			variants: { linkedin: { body: 'Tekst voor LinkedIn' } },
		})

		const wrapper = await mountSection()
		const cards = wrapper.findAll('.social-variants__card')

		expect(cards).toHaveLength(2)
		expect(wrapper.vm.networks).toEqual(['mastodon', 'linkedin'])
		expect(cards.map((card) => card.find('.social-variants__body').text())).toEqual([
			'Algemene tekst',
			'Tekst voor LinkedIn',
		])
	})

	it('says who wrote an agent-authored post', async () => {
		api.fetchPost.mockResolvedValue({ body: 'x', agentAuthored: true, agentAuthoredBy: 'Hermiq' })

		const wrapper = await mountSection()

		expect(wrapper.find('.social-variants__agent').text()).toBe('Written by an agent: Hermiq')
		expect(wrapper.text()).toContain('This post names no accounts yet.')
	})

	it('lists the approvals with their decision, who and when, and any note', async () => {
		api.fetchPost.mockResolvedValue({
			body: 'x',
			approvals: [
				{ decision: 'approved', userId: 'anna', decidedAt: '2026-11-13T09:00:00Z', note: 'Prima' },
				{ decision: 'rejected', userId: 'bert', decidedAt: 'not a date' },
				{ decision: 'deferred', userId: 'carla', decidedAt: '' },
			],
		})

		const wrapper = await mountSection()
		const entries = wrapper.findAll('.social-variants__approval')

		expect(entries.map((entry) => entry.find('.social-variants__decision').text())).toEqual(['Approved', 'Rejected', 'deferred'])
		expect(entries[0].find('.social-variants__approval-note').text()).toBe('Prima')
		expect(entries[1].find('.social-variants__approval-meta').text()).toBe('bert · not a date')
		expect(entries[2].find('.social-variants__approval-meta').text()).toBe('carla ·')
		expect(entries[1].find('.social-variants__approval-note').exists()).toBe(false)
	})

	it('hides the approvals when asked', async () => {
		api.fetchPost.mockResolvedValue({ body: 'x', approvals: [{ decision: 'approved', userId: 'anna', decidedAt: '' }] })

		const wrapper = await mountSection({ hideApprovals: true })

		expect(wrapper.find('.social-variants__approvals').exists()).toBe(false)
	})

	it('says so when the post cannot be read', async () => {
		api.fetchPost.mockRejectedValue(new Error('offline'))

		const wrapper = await mountSection()

		expect(wrapper.find('.note[data-type="error"]').text()).toBe('The post could not be loaded.')
		expect(wrapper.vm.loading).toBe(false)
	})
})
