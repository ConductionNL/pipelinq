// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The tap links and "Log a visit" as mounted components
 * (platform-phone-on-the-road), with the object store replaced. The last
 * specs pin the callers: the registry resolves both sections and the
 * client, contact and lead pages declare them.
 *
 * @spec openspec/changes/platform-phone-on-the-road/specs/mobile-experience/spec.md#requirement-a-visit-is-logged-in-one-small-sheet-req-mob-003
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const storeMock = vi.hoisted(() => ({ saveObject: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => storeMock,
}))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		emits: ['click'],
		render() {
			return h('button', { onClick: () => this.$emit('click') }, [
				this.$slots.icon?.(),
				this.$slots.default?.(),
			])
		},
	},
	NcDialog: {
		name: 'NcDialog',
		render() {
			return h('div', { class: 'nc-dialog' }, [
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	},
}))

let ContactLinks
let LogVisitAction

beforeAll(async () => {
	globalThis.t = (app, text) => text
	ContactLinks = (await import('../../src/components/ContactLinks.vue')).default
	LogVisitAction = (await import('../../src/components/LogVisitAction.vue'))
		.default
})

beforeEach(() => {
	storeMock.saveObject.mockReset()
	dialogs.showError.mockReset()
	dialogs.showSuccess.mockReset()
	window.OC = { getCurrentUser: () => ({ uid: 'pieter' }) }
})

const global = { mixins: [{ methods: { t: (app, text) => text } }] }
const read = (p) => readFileSync(resolve(__dirname, '../..', p), 'utf8')

describe('ContactLinks', () => {
	it('dials 0612345678 and still shows the number as typed', () => {
		const w = mount(ContactLinks, { props: { phone: '06 12 34 56 78' }, global })
		const link = w.find('[data-testid="contact-link-phone"]')
		expect(link.attributes('href')).toBe('tel:0612345678')
		expect(link.text()).toContain('06 12 34 56 78')
	})

	it('opens the map app with the address', () => {
		const w = mount(ContactLinks, {
			props: { address: 'Dorpsstraat 1, Utrecht' },
			global,
		})
		expect(
			w.find('[data-testid="contact-link-address"]').attributes('href'),
		).toBe('geo:0,0?q=' + encodeURIComponent('Dorpsstraat 1, Utrecht'))
	})

	it('renders nothing when there is nothing to link', () => {
		const w = mount(ContactLinks, { props: {}, global })
		expect(w.find('ul').exists()).toBe(false)
	})
})

describe('LogVisitAction', () => {
	/**
	 * Open the dialog, type a note and optional day, and save.
	 *
	 * @param {object} props Component props.
	 * @param {string} day Follow-up day or ''.
	 * @return {Promise<object>} The wrapper.
	 */
	async function logVisit(props, day) {
		const w = mount(LogVisitAction, { props, global })
		await w.find('[data-testid="log-visit-button"]').trigger('click')
		await w.find('#log-visit-note').setValue('wants a quote for two ovens')
		if (day) await w.find('#log-visit-follow-up').setValue(day)
		await w.find('form').trigger('submit')
		await flushPromises()
		return w
	}

	it('saves a visit contact moment and a follow-up task on the picked day', async () => {
		storeMock.saveObject.mockResolvedValue({ id: 'saved' })
		const w = await logVisit({ clientId: 'client-1' }, '2026-10-02')
		expect(storeMock.saveObject).toHaveBeenCalledTimes(2)
		const [type1, ticket] = storeMock.saveObject.mock.calls[0]
		const [type2, task] = storeMock.saveObject.mock.calls[1]
		expect(type1).toBe('ticket')
		expect(ticket).toMatchObject({
			channel: 'visit',
			direction: 'outbound',
			client: 'client-1',
			description: 'wants a quote for two ovens',
			assignee: 'pieter',
		})
		expect(type2).toBe('crmTask')
		expect(task).toMatchObject({
			type: 'followUpTask',
			clientId: 'client-1',
			assigneeUserId: 'pieter',
		})
		expect(dialogs.showSuccess).toHaveBeenCalled()
		expect(w.emitted('saved')).toBeTruthy()
	})

	it('saves only the contact moment when no day is picked', async () => {
		storeMock.saveObject.mockResolvedValue({ id: 'saved' })
		await logVisit({ leadId: 'lead-1', clientId: 'client-1' }, '')
		expect(storeMock.saveObject).toHaveBeenCalledTimes(1)
		expect(storeMock.saveObject.mock.calls[0][1]).toMatchObject({
			lead: 'lead-1',
			client: 'client-1',
		})
	})

	it('keeps the dialog open with the error when the visit is not saved', async () => {
		storeMock.saveObject.mockResolvedValue(null)
		const w = await logVisit({ clientId: 'client-1' }, '')
		expect(w.find('[role="alert"]').text()).toBe('The visit could not be saved.')
	})

	it('does not log the visit twice when only the task fails', async () => {
		storeMock.saveObject
			.mockResolvedValueOnce({ id: 'visit' })
			.mockRejectedValueOnce(new Error('boom'))
		const w = await logVisit({ clientId: 'client-1' }, '2026-10-02')
		expect(dialogs.showError).toHaveBeenCalled()
		expect(w.find('form').exists()).toBe(false)
	})
})

describe('callers', () => {
	it('the registry resolves both sections', () => {
		const registry = read('src/registry.js')
		expect(registry).toMatch(
			/ContactLinks: \{[\s\S]{0,80}component: ContactLinks/,
		)
		expect(registry).toMatch(
			/LogVisitAction: \{[\s\S]{0,80}component: LogVisitAction/,
		)
	})

	it('client, contact and lead pages declare them', () => {
		const pages = JSON.parse(read('src/manifest.json')).pages
		const widgets = (id) =>
			pages.find((p) => p.id === id).config.bodyWidgets.map((w) => w.component)
		expect(widgets('ClientDetail')).toEqual(
			expect.arrayContaining(['ContactLinks', 'LogVisitAction']),
		)
		expect(widgets('ContactDetail')).toContain('ContactLinks')
		expect(widgets('LeadDetail')).toContain('LogVisitAction')
	})
})
