// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Round-5 cloud check of 10 October 2026, pipelinq items 2 to 5
 * (item 1, the "Lead created: " activity, is PHP and tested in
 * tests/Unit/Service/ObjectEventHandlerServiceTest.php).
 *
 * 2. The client's Messages card said "No contacts linked to this client yet"
 *    after a contact was added on the Contacts tab, and its copy carried an
 *    em-dash.
 * 3. The contact create dialog showed Dutch help text in the English UI, and
 *    cut the longer one off at "art.".
 * 4. The tour's last step said "Click Modules and more in the menu" while the
 *    item sits in the closed Advanced foldout.
 * 5. A contact added on the client page did not show in its Related card.
 *
 * @spec openspec/changes/r6-contact-activity-relations-copy/specs/client-management/spec.md
 * @spec openspec/changes/r6-contact-activity-relations-copy/specs/unify-client-contact/spec.md
 * @spec openspec/changes/r6-contact-activity-relations-copy/specs/walkthrough/spec.md
 */

import { splitDescription } from '@conduction/nextcloud-vue/src/utils/schema.js'
import { emit } from '@nextcloud/event-bus'
import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const storeMock = vi.hoisted(() => ({
	fetchObject: vi.fn(),
	fetchCollection: vi.fn(),
}))
const libraryCreate = vi.hoisted(() => vi.fn())
const createWithContact = vi.hoisted(() => vi.fn())

vi.mock('@conduction/nextcloud-vue', () => ({
	CnObjectListWidget: {
		name: 'CnObjectListWidget',
		methods: { onCreateConfirm: libraryCreate },
	},
}))
vi.mock('../../src/services/contactSyncApi.js', () => ({ createWithContact }))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (p) => '/index.php' + p,
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
	NcButton: { name: 'NcButton', render: () => h('button') },
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['description'],
		render() {
			return h('div', { class: 'empty' }, this.description)
		},
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('span') },
	NcSelect: {
		name: 'NcSelect',
		props: ['options'],
		render() {
			return h(
				'div',
				{ class: 'contact-picker' },
				(this.options || []).map((o) => o.label).join(','),
			)
		},
	},
}))

globalThis.t = (app, text) => text

const { default: ContactAwareObjectListWidget } =
	await import('../../src/components/widgets/ContactAwareObjectListWidget.js')
const {
	installPageRefreshOnCreate,
	OBJECT_CREATED_EVENT,
	PAGE_REFRESH_CHANNEL,
	WIDGET_REFRESH_CHANNEL,
} = await import('../../src/services/pageRefreshOnCreate.js')
const { default: MessagingConversationSection } =
	await import('../../src/views/messaging/MessagingConversationSection.vue')

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))
const EN = readJson('l10n', 'en.json').translations
const NL = readJson('l10n', 'nl.json').translations

/**
 * Deep-merge like ConfigFileLoaderService::deepMergeConfig: objects merge
 * key by key, everything else is replaced.
 *
 * @param {object} base The base.
 * @param {object} override The fragment.
 * @return {object} The base, merged in place.
 */
function merge(base, override) {
	const isObject = (v) => v && typeof v === 'object' && !Array.isArray(v)
	for (const [key, value] of Object.entries(override)) {
		if (isObject(value) && isObject(base[key])) {
			merge(base[key], value)
		} else {
			base[key] = value
		}
	}
	return base
}

/** The register as the import builds it: the monolith plus every fragment. */
function mergedRegister() {
	const register = readJson('lib', 'Settings', 'pipelinq_register.json')
	fs.readdirSync(path.join(ROOT, 'lib', 'Settings', 'register.d'))
		.filter((name) => name.endsWith('.json'))
		.sort()
		.forEach((name) =>
			merge(register, readJson('lib', 'Settings', 'register.d', name)),
		)
	return register
}

describe('a contact added on a client page reaches the other cards', () => {
	beforeEach(() => {
		createWithContact.mockReset()
		libraryCreate.mockReset()
	})

	it('the contact-first create announces the new contact on the window', async () => {
		createWithContact.mockResolvedValue({ id: 'ct-1', name: 'AUDIT R5 contact' })
		const heard = []
		const listener = (event) => heard.push(event.detail)
		window.addEventListener(OBJECT_CREATED_EVENT, listener)
		const vm = {
			content: { schema: 'contact' },
			resolvedFilter: { client: 'client-1' },
			$refs: { createDialog: { setResult: vi.fn() } },
			$emit: vi.fn(),
			fetchRows: vi.fn(),
		}

		await ContactAwareObjectListWidget.methods.onCreateConfirm.call(vm, {
			name: 'AUDIT R5 contact',
		})
		window.removeEventListener(OBJECT_CREATED_EVENT, listener)

		expect(heard).toHaveLength(1)
		expect(heard[0]).toMatchObject({
			id: 'ct-1',
			register: 'pipelinq',
			schema: 'contact',
		})
	})

	it('a failed create announces nothing', async () => {
		createWithContact.mockRejectedValue(new Error('no'))
		const heard = []
		const listener = (event) => heard.push(event.detail)
		window.addEventListener(OBJECT_CREATED_EVENT, listener)
		await ContactAwareObjectListWidget.methods.onCreateConfirm.call(
			{
				content: { schema: 'contact' },
				resolvedFilter: {},
				$refs: { createDialog: { setResult: vi.fn() } },
				$emit: vi.fn(),
				fetchRows: vi.fn(),
			},
			{ name: 'X' },
		)
		window.removeEventListener(OBJECT_CREATED_EVENT, listener)
		expect(heard).toHaveLength(0)
	})

	it('a new contact refreshes the page and the widgets, so the Related card reloads', () => {
		const sent = []
		const remove = installPageRefreshOnCreate(window, (channel) =>
			sent.push(channel),
		)
		window.dispatchEvent(
			new CustomEvent(OBJECT_CREATED_EVENT, {
				detail: { id: 'ct-1', register: 'pipelinq', schema: 'contact' },
			}),
		)
		remove()

		expect(sent).toContain(PAGE_REFRESH_CHANNEL)
		expect(sent).toContain(WIDGET_REFRESH_CHANNEL)
	})

	it('the widget channel is the one the Related card reloads on', () => {
		const lib = read(
			'node_modules',
			'@conduction',
			'nextcloud-vue',
			'src',
			'components',
			'CnRelatedObjectsWidget',
			'CnRelatedObjectsWidget.vue',
		)
		expect(lib).toContain(
			`const REFRESH_BUS_CHANNEL = '${WIDGET_REFRESH_CHANNEL}'`,
		)
	})

	it("the Messages card fetches the client's contacts again on a page refresh", async () => {
		storeMock.fetchObject.mockResolvedValue({
			id: 'client-1',
			name: 'AUDIT aaa',
		})
		storeMock.fetchCollection.mockResolvedValueOnce([])
		const wrapper = mount(MessagingConversationSection, {
			props: { entityId: 'client-1', entityType: 'client' },
			global: { mocks: { t: (app, text) => text } },
		})
		await flushPromises()
		expect(wrapper.find('.empty').exists()).toBe(true)

		storeMock.fetchCollection.mockResolvedValue([
			{ id: 'ct-1', name: 'AUDIT R5 contact' },
		])
		emit(PAGE_REFRESH_CHANNEL, {})
		await flushPromises()

		expect(wrapper.find('.empty').exists()).toBe(false)
		expect(wrapper.find('.contact-picker').text()).toContain('AUDIT R5 contact')
		wrapper.unmount()
	})
})

describe('the Messages card copy', () => {
	const KEY =
		'No contacts are linked to this client yet. Add a contact to send messages.'

	it('carries no em-dash and has an English and a Dutch entry', () => {
		const source = read(
			'src',
			'views',
			'messaging',
			'MessagingConversationSection.vue',
		)
		expect(source).toContain(KEY)
		expect(source).not.toContain('No contacts linked to this client yet —')
		expect(EN[KEY]).toBe(KEY)
		expect(NL[KEY]).toBeTruthy()
		expect(NL[KEY]).not.toBe(KEY)
		expect(`${EN[KEY]} ${NL[KEY]}`).not.toMatch(/[—]|--/)
	})
})

describe('contact and client help text', () => {
	const register = mergedRegister()
	const DUTCH =
		/\b(na|het|wordt|worden|uit|afgeleid|beheerd|niet|wanneer|alleen|zichtbaar|naar|bij)\b/i

	/**
	 * The visible fields of a schema with a description.
	 *
	 * @param {string} slug The schema slug.
	 * @return {Array<[string, string]>} [property, description] pairs.
	 */
	function described(slug) {
		return Object.entries(register.components.schemas[slug].properties)
			.filter(([, prop]) => prop.visible !== false && prop.description)
			.map(([key, prop]) => [key, prop.description])
	}

	it.each(['contact', 'client'])(
		'no visible %s field shows a Dutch description',
		(slug) => {
			const dutch = described(slug).filter(([, text]) => DUTCH.test(text))
			expect(dutch).toEqual([])
		},
	)

	it.each(['verifiedBSN', 'secrecy'])(
		'the contact %s text is English, translated, and shown in full',
		(key) => {
			const text =
				register.components.schemas.contact.properties[key].description
			expect(text).not.toMatch(DUTCH)
			expect(NL[text]).toBeTruthy()
			expect(NL[text]).not.toBe(text)
			for (const shown of [text, NL[text]]) {
				expect(splitDescription(shown)).toEqual({ short: shown, long: '' })
			}
		},
	)
})

describe("the tour's last step", () => {
	const layout = readJson('src', 'menu-layout.simple.json')
	const step = layout.tours[0].steps.find((s) => s.id === 'see-modules')
	const item = layout.menu.find((m) => m.route === 'Modules')

	it('names the Advanced foldout while the item sits in the footer section', () => {
		expect(item.section).toBe('footer')
		expect(step.task).toContain('Advanced')
		expect(step.task).toContain('Modules and more')
		expect(EN[step.task]).toBe(step.task)
		expect(NL[step.task]).toContain('Geavanceerd')
	})
})
