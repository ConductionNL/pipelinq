// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The two body sections of a social post (its variants, its publications):
 * the post they are bound to, the read on mount, and the re-read on
 * `cn:page:refresh` until they go.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 */
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const bus = vi.hoisted(() => ({ subscribe: vi.fn(), unsubscribe: vi.fn() }))
const api = vi.hoisted(() => ({
	fetchAccounts: vi.fn(async () => ({ data: [] })),
	fetchPost: vi.fn(async () => ({})),
	fetchPublications: vi.fn(async () => []),
	confirmShare: vi.fn(),
	fetchShare: vi.fn(),
	retryPublication: vi.fn(),
}))
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcLoadingIcon: {}, NcNoteCard: {} }))
vi.mock('../../src/services/socialApi.js', () => api)

globalThis.t = (_app, text) => text

const sections = {
	variants: (await import('../../src/components/marketing/SocialPostVariantsSection.vue')).default,
	publications: (await import('../../src/components/marketing/SocialPublicationsSection.vue')).default,
}

beforeEach(() => {
	bus.subscribe.mockReset()
	bus.unsubscribe.mockReset()
})

/**
 * @param {object} section The section.
 * @param {object} options Its props and the provided section context.
 * @return {object} The wrapper.
 */
function mountSection(section, options = {}) {
	return shallowMount(section, {
		props: options.props || {},
		global: { mocks: { t: globalThis.t }, provide: 'context' in options ? { cnSectionContext: options.context } : {} },
	})
}

describe.each(Object.keys(sections))('the %s section', (name) => {
	const section = sections[name]

	it('is bound to its prop, else the host\'s objectId, else its id, else none', () => {
		expect(mountSection(section, { props: { postId: 'p1' }, context: { objectId: 'o1' } }).vm.effectiveId()).toBe('p1')
		expect(mountSection(section, { context: { objectId: 'o1', id: 'i1' } }).vm.effectiveId()).toBe('o1')
		expect(mountSection(section, { context: { id: 'i1' } }).vm.effectiveId()).toBe('i1')
		expect(mountSection(section, { context: null }).vm.effectiveId()).toBe('')
		expect(mountSection(section).vm.effectiveId()).toBe('')
	})

	it('reads on mount, re-reads on a page refresh, and stops listening when it goes', async () => {
		const wrapper = mountSection(section, { props: { postId: 'p1' } })
		await flushPromises()
		const read = name === 'variants' ? api.fetchPost : api.fetchPublications
		expect(read).toHaveBeenCalledWith('p1')
		expect(bus.subscribe).toHaveBeenCalledWith('cn:page:refresh', wrapper.vm.load)
		const handler = bus.subscribe.mock.calls[0][1]
		wrapper.unmount()
		expect(bus.unsubscribe).toHaveBeenCalledWith('cn:page:refresh', handler)
	})

	it('takes the post id as an optional string', () => {
		const props = { ...Object.assign({}, ...(section.mixins || []).map((m) => m.props || {})), ...section.props }
		expect(props.postId).toEqual({ type: String, default: '' })
	})
})
