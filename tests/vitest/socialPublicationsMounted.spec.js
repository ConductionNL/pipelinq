// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The publications of a social post as they render: one row per account with
 * its state and reason, Retry only where trying again can help, the share
 * path for an account no application may post to, and a read that fails.
 *
 * @spec openspec/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
	fetchPublications: vi.fn(),
	confirmShare: vi.fn(),
	fetchShare: vi.fn(),
	retryPublication: vi.fn(),
}))
vi.mock('@nextcloud/event-bus', () => ({ subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', props: ['disabled', 'href'], emits: ['click'], template: '<button :disabled="disabled" :data-href="href" @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: { template: '<i class="loading" />' },
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
}))
vi.mock('../../src/services/socialApi.js', () => api)

globalThis.t = (_app, text) => text
const { default: SocialPublicationsSection } = await import('../../src/components/marketing/SocialPublicationsSection.vue')

const ROWS = [
	{ id: 'pub-1', network: 'linkedin', status: 'published', url: 'https://www.linkedin.com/feed/update/1' },
	{ id: 'pub-2', network: 'mastodon', status: 'failed', failureCode: 'unavailable', failureReason: 'The server did not answer.' },
	{ id: 'pub-3', network: 'x', status: 'failed', failureCode: 'grant_revoked', failureReason: 'Reconnect the account.' },
	{ id: 'pub-4', network: 'instagram', status: 'awaiting_share' },
]

async function section() {
	const w = mount(SocialPublicationsSection, {
		props: { postId: 'post-1' },
		global: { mocks: { t: globalThis.t } },
	})
	await flushPromises()
	return w
}

describe('SocialPublicationsSection on screen', () => {
	beforeEach(() => {
		Object.values(api).forEach((fn) => fn.mockReset())
		api.fetchPublications.mockResolvedValue(ROWS)
	})

	it('shows a row per account, a link to what went out, the reason, and Retry only where it can help', async () => {
		const w = await section()
		const rows = w.findAll('[data-testid="social-publication-row"]')

		expect(api.fetchPublications).toHaveBeenCalledWith('post-1')
		expect(rows).toHaveLength(4)
		expect(rows[0].find('a').attributes('href')).toBe('https://www.linkedin.com/feed/update/1')
		expect(rows[1].find('[data-testid="social-publication-reason"]').text()).toBe('The server did not answer.')
		expect(rows.map((row) => row.find('[data-testid="social-publication-retry"]').exists())).toEqual([false, true, false, false])
		expect(rows.map((row) => row.find('[data-testid="social-publication-share"]').exists())).toEqual([false, false, false, true])
	})

	it('retries a publication and reads the list again', async () => {
		api.retryPublication.mockResolvedValue({})
		const w = await section()

		await w.find('[data-testid="social-publication-retry"]').trigger('click')
		await flushPromises()

		expect(api.retryPublication).toHaveBeenCalledWith('pub-2')
		expect(api.fetchPublications).toHaveBeenCalledTimes(2)
		expect(w.find('.note').exists()).toBe(false)
	})

	it('shows the prepared text with the composer, and records the share', async () => {
		api.fetchShare.mockResolvedValue({ publicationId: 'pub-4', body: 'Kom naar de open dag!', composerUrl: 'https://www.instagram.com/' })
		api.confirmShare.mockResolvedValue({})
		const w = await section()

		await w.find('[data-testid="social-publication-share"]').trigger('click')
		await flushPromises()
		expect(api.fetchShare).toHaveBeenCalledWith('pub-4')
		expect(w.find('[data-testid="social-share-body"]').element.value).toBe('Kom naar de open dag!')
		const composer = w.findAll('button').find((button) => button.text() === 'Open the composer')
		expect(composer.attributes('data-href')).toBe('https://www.instagram.com/')

		await w.find('[data-testid="social-share-confirm"]').trigger('click')
		await flushPromises()
		expect(api.confirmShare).toHaveBeenCalledWith('pub-4')
		expect(w.find('[data-testid="social-share-body"]').exists()).toBe(false)
	})

	it('copies the prepared text, and says so when the clipboard refuses', async () => {
		api.fetchShare.mockResolvedValue({ publicationId: 'pub-4', body: 'Kom langs' })
		const writeText = vi.fn().mockResolvedValue(undefined)
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
		const w = await section()
		await w.find('[data-testid="social-publication-share"]').trigger('click')
		await flushPromises()
		const copy = () => w.findAll('button').find((button) => button.text() === 'Copy text')

		expect(w.findAll('button').some((button) => button.text() === 'Open the composer')).toBe(false)
		await copy().trigger('click')
		await flushPromises()
		expect(writeText).toHaveBeenCalledWith('Kom langs')

		writeText.mockRejectedValue(new Error('denied'))
		await copy().trigger('click')
		await flushPromises()
		expect(w.find('.note').text()).toBe('The text could not be copied. Select it and copy it by hand.')
	})

	it('shows the error when the publications cannot be read', async () => {
		api.fetchPublications.mockRejectedValue(new Error('500'))
		const w = await section()

		expect(w.find('.note').attributes('data-type')).toBe('error')
		expect(w.find('.note').text()).toBe('The publications could not be loaded.')
		expect(w.find('[data-testid="social-publication-row"]').exists()).toBe(false)
	})
})
