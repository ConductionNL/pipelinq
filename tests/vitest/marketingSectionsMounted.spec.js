// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Four marketing sections mounted: the subscriber's
 * view of a mailing list, the list's sign-up form, the social post's approval
 * buttons and what a journey did. Checked for what they show and what they send.
 *
 * @spec openspec/specs/marketing-campaigns/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const bus = vi.hoisted(() => ({ emit: vi.fn(), subscribe: vi.fn(), unsubscribe: vi.fn() }))
const toasts = vi.hoisted(() => ({ showError: vi.fn() }))

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('@nextcloud/dialogs', () => toasts)

/**
 * A stub that renders its slots.
 *
 * @param {string} name The component name.
 * @param {string} [tag] The element to render.
 * @return {object} The component.
 */
function stub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['type', 'variant', 'disabled', 'size', 'label', 'name'],
		emits: ['click'],
		render() {
			return h(tag, { 'data-stub': name, 'data-variant': this.variant, disabled: this.disabled || undefined, onClick: () => this.$emit('click') }, [this.label || this.name, this.$slots.default?.()])
		},
	}
}

vi.mock('@conduction/nextcloud-vue', () => ({ CnStatusBadge: stub('CnStatusBadge', 'span') }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcEmptyContent: stub('NcEmptyContent'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard'),
}))

const { default: ListSubscriberViewSection } = await import('../../src/components/marketing/ListSubscriberViewSection.vue')
const { default: ListSignupFormSection } = await import('../../src/components/marketing/ListSignupFormSection.vue')
const { default: SocialPostHeaderActions } = await import('../../src/components/marketing/SocialPostHeaderActions.vue')

const { default: JourneyRunsSection } = await import('../../src/components/marketing/JourneyRunsSection.vue')

const global = { mocks: { t: globalThis.t } }

beforeEach(() => {
	vi.resetAllMocks()
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('ListSubscriberViewSection', () => {
	it('shows the public lists and this one, ticking this one', async () => {
		http.get.mockResolvedValueOnce({ data: { results: [
			{ id: 'l1', name: 'News', publicSignup: true },
			{ '@self': { id: 'l2' }, name: 'Members' },
			{ id: 'l3', name: 'Internal', publicSignup: false },
		] } })
		const wrapper = mount(ListSubscriberViewSection, { global, props: { listId: 'l2' } })
		await flushPromises()
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/pipelinq/mailingList', { params: { status: 'active', _limit: 20 } })
		const lists = wrapper.findAll('.list-preview__list')
		expect(lists.map((l) => [l.text(), l.find('input').element.checked])).toEqual([['News', false], ['Members', true]])
		expect(wrapper.find('.list-preview__address').text()).toBe(window.location.host + '/index.php/apps/pipelinq/api/lists/preferences/…')
	})

	it('shows no lists when they cannot load', async () => {
		http.get.mockRejectedValueOnce(new Error('403'))
		const wrapper = mount(ListSubscriberViewSection, { global, props: { listId: 'l1' } })
		await flushPromises()
		expect(wrapper.findAll('.list-preview__list')).toHaveLength(0)
	})
})

describe('ListSignupFormSection', () => {
	it('shows a form that posts to the list subscribe endpoint', () => {
		const wrapper = mount(ListSignupFormSection, { global, props: { listId: 'a b' } })
		expect(wrapper.find('code').text()).toBe([
			`<form method="post" action="${window.location.origin}/index.php/apps/pipelinq/api/lists/a%20b/subscribe">`,
			'  <input type="email" name="email" required>',
			'  <button>Subscribe</button>',
			'</form>',
		].join('\n'))
	})

	it('copies the code and says so', async () => {
		const writeText = vi.fn().mockResolvedValue(undefined)
		vi.stubGlobal('navigator', { clipboard: { writeText } })
		const wrapper = mount(ListSignupFormSection, { global, props: { listId: 'l1' } })
		expect(wrapper.find('button').text()).toBe('Copy embed code')
		await wrapper.find('button').trigger('click')
		await flushPromises()
		expect(writeText).toHaveBeenCalledWith(wrapper.vm.snippet)
		expect(wrapper.find('button').text()).toBe('Copied')
	})

	it('does not claim a copy the browser refused', async () => {
		vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn().mockRejectedValue(new Error('denied')) } })
		const wrapper = mount(ListSignupFormSection, { global, props: { listId: 'l1' } })
		await wrapper.find('button').trigger('click')
		await flushPromises()
		expect(wrapper.find('button').text()).toBe('Copy embed code')
	})
})

describe('SocialPostHeaderActions', () => {
	it('shows nothing for a post that is not a draft or awaiting approval', () => {
		const wrapper = mount(SocialPostHeaderActions, { global, props: { object: { id: 'p1', status: 'published' } } })
		expect(wrapper.find('button').exists()).toBe(false)
	})

	it('submits a draft and refreshes the page', async () => {
		http.post.mockResolvedValueOnce({ data: {} })
		const wrapper = mount(SocialPostHeaderActions, { global, props: { object: { '@self': { id: 'p1' }, status: 'draft' } } })
		const buttons = wrapper.findAll('button')
		expect(buttons.map((b) => b.text())).toEqual(['Submit for approval'])
		await buttons[0].trigger('click')
		await flushPromises()
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/social-posts/p1/submit', { note: '' })
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(wrapper.vm.busy).toBe(false)
	})

	it('offers reject and approve on a post awaiting approval, by the given id', async () => {
		http.post.mockResolvedValue({ data: {} })
		const wrapper = mount(SocialPostHeaderActions, { global, props: { objectId: 'given', object: { id: 'own', status: 'approval' } } })
		const buttons = wrapper.findAll('button')
		expect(buttons.map((b) => b.text())).toEqual(['Reject', 'Approve'])
		await buttons[1].trigger('click')
		await flushPromises()
		await buttons[0].trigger('click')
		await flushPromises()
		expect(http.post.mock.calls.map((c) => c[0])).toEqual([
			'/index.php/apps/pipelinq/api/social-posts/given/approve',
			'/index.php/apps/pipelinq/api/social-posts/given/reject',
		])
	})

	it('shows the server error of a refused move, else a general one', async () => {
		const wrapper = mount(SocialPostHeaderActions, { global, props: { object: { id: 'p1', status: 'draft' } } })
		http.post.mockRejectedValueOnce({ response: { data: { error: 'Nothing to submit' } } })
		await wrapper.find('button').trigger('click')
		await flushPromises()
		http.post.mockRejectedValueOnce(new Error('500'))
		await wrapper.find('button').trigger('click')
		await flushPromises()
		expect(toasts.showError.mock.calls).toEqual([['Nothing to submit'], ['That did not work.']])
		expect(bus.emit).not.toHaveBeenCalled()
	})
})

describe('JourneyRunsSection', () => {
	it('says a journey without an id has not run, asking nothing', async () => {
		const wrapper = mount(JourneyRunsSection, { global })
		await flushPromises()
		expect(http.get).not.toHaveBeenCalled()
		expect(wrapper.find('[data-stub="NcEmptyContent"]').text()).toBe('This journey has not run yet')
		expect(wrapper.find('.journey-runs__subtitle').exists()).toBe(true)
	})

	it('counts and lists every contact reached or refused', async () => {
		http.get.mockResolvedValueOnce({ data: { results: [
			{ id: 'r1', contactName: 'Ann', state: 'sent', occurredAt: 'not a date' },
			{ id: 'r2', contactId: 'c2', state: 'refused', reason: 'no_consent' },
			{ id: 'r3', contactId: 'c3', state: 'failed', reason: 'odd_reason' },
			{ id: 'r4', contactId: 'c4', state: 'task-created' },
		] } })
		const wrapper = mount(JourneyRunsSection, { global, props: { journeyId: 'j1', showSubtitle: false } })
		await flushPromises()
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/journeys/j1/runs')
		expect(wrapper.find('.journey-runs__subtitle').exists()).toBe(false)
		expect(wrapper.find('.journey-runs__counts').text()).toBe('2 sent, 1 refused, 1 failed')
		const rows = wrapper.findAll('tbody tr').map((tr) => tr.findAll('td').map((td) => td.text()))
		expect(rows).toEqual([
			['Ann', 'not a date', 'Sent', ''],
			['c2', '', 'Refused', 'No consent for this channel'],
			['c3', '', 'Failed', 'odd_reason'],
			['c4', '', 'Task created', ''],
		])
		expect(wrapper.findAll('[data-stub="CnStatusBadge"]').map((b) => b.attributes('data-variant'))).toEqual(['success', 'warning', 'error', 'success'])
	})

	it('shows the server error, else a general one', async () => {
		http.get.mockRejectedValueOnce({ response: { data: { error: 'Journey gone' } } })
		const wrapper = mount(JourneyRunsSection, { global, props: { journeyId: 'j1' } })
		await flushPromises()
		expect(wrapper.find('[role="alert"]').text()).toBe('Journey gone')
		http.get.mockRejectedValueOnce(new Error('500'))
		const other = mount(JourneyRunsSection, { global, props: { journeyId: 'j2' } })
		await flushPromises()
		expect(other.find('[role="alert"]').text()).toBe('Could not read what this journey did.')
		expect(other.vm.loading).toBe(false)
	})

	it('formats a real time and names an unknown state as is', () => {
		const ctx = { t: globalThis.t }
		expect(JourneyRunsSection.methods.when.call(ctx, '2026-11-12T10:00:00Z')).not.toBe('2026-11-12T10:00:00Z')
		expect(JourneyRunsSection.methods.stateVariant.call(ctx, 'paused')).toBe('default')
	})
})
