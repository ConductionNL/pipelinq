// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Messages section on a client or contact lists the contact's messages
 * (pipelinq#2073). It asked the object store for the type `message`, which
 * was renamed `channelMessage` and is no longer registered. The store throws
 * "Object type X is not registered" for such a slug, the section caught that
 * and set its list to empty, so it always said "No messages yet".
 *
 * The object store is replaced by one that answers only for the slugs the
 * REAL registry in src/config/objectTypes.js declares, and throws the store's
 * own error for any other. So a fetch on a slug the registry does not know
 * fails here the way it fails in the browser.
 *
 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-the-messages-section-lists-the-contacts-messages-req-msr-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({ get: vi.fn() }))
const storeMock = vi.hoisted(() => ({
	fetchObject: vi.fn(),
	fetchCollection: vi.fn(),
}))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => '/index.php' + path,
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => String(text),
}))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => storeMock,
}))
vi.mock('../../src/modals/SendMessageModal.vue', () => ({
	default: { name: 'SendMessageModal', render: () => h('div') },
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		render() {
			return h('button', this.$slots.default?.())
		},
	},
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['description'],
		render() {
			return h('div', { class: 'empty' }, this.description)
		},
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('span') },
	NcSelect: { name: 'NcSelect', render: () => h('div') },
}))

// Some of the section's methods call the global `t()` Nextcloud puts on the
// page, not the component's own; give it the same identity translation.
globalThis.t = (app, text) => text

const { objectTypes } = await import('../../src/config/objectTypes.js')
const { default: MessagingConversationSection } =
	await import('../../src/views/messaging/MessagingConversationSection.vue')

const registered = new Set(objectTypes().map((type) => type.slug))

const sms = {
	id: 'm-1',
	contactId: 'contact-1',
	channel: 'sms',
	direction: 'outbound',
	body: 'Uw afspraak staat morgen om 10:00',
	deliveryStatus: 'delivered',
	sentAt: '2026-09-27T10:00:00Z',
}

/**
 * Mount the section on a contact and let its fetches settle.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountOnContact() {
	const wrapper = mount(MessagingConversationSection, {
		props: { entityId: 'contact-1', entityType: 'contact' },
		global: { mocks: { t: (app, text) => text } },
	})
	await flushPromises()
	return wrapper
}

describe('MessagingConversationSection', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.get.mockResolvedValue({ data: {} })
		storeMock.fetchObject.mockReset()
		storeMock.fetchObject.mockResolvedValue({ id: 'contact-1' })
		storeMock.fetchCollection.mockReset()
		storeMock.fetchCollection.mockImplementation((slug) => {
			if (!registered.has(slug)) {
				return Promise.reject(
					new Error(`Object type ${slug} is not registered`),
				)
			}
			return Promise.resolve(slug === 'channelMessage' ? [sms] : [])
		})
	})

	it("lists the contact's messages", async () => {
		const wrapper = await mountOnContact()

		expect(wrapper.text()).toContain('Uw afspraak staat morgen om 10:00')
		expect(wrapper.text()).not.toContain('No messages yet.')
	})

	it('asks the store only for registered types', async () => {
		await mountOnContact()

		const slugs = storeMock.fetchCollection.mock.calls.map(([slug]) => slug)
		expect(slugs).toContain('channelMessage')
		for (const slug of slugs) {
			expect(registered.has(slug), `${slug} is registered`).toBe(true)
		}
	})

	it('names no unregistered slug anywhere in its source', () => {
		const source = readFileSync(
			resolve(
				__dirname,
				'../../src/views/messaging/MessagingConversationSection.vue',
			),
			'utf8',
		)
		const slugs = [
			...source.matchAll(/fetch(?:Collection|Object)\(\s*'([^']+)'/g),
		].map((match) => match[1])

		expect(slugs.length).toBeGreaterThan(0)
		for (const slug of slugs) {
			expect(registered.has(slug), `${slug} is registered`).toBe(true)
		}
	})
})
