// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An unknown caller's earlier contacts: the intake dialog asks the phone
 * search for the caller's number and lists what it finds, or says there is
 * nothing.
 *
 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-an-unknown-callers-earlier-contacts-are-shown-req-chp-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const get = vi.fn()
let offline = false

vi.mock('@nextcloud/axios', () => ({
	default: { get: (...args) => (offline ? Promise.reject(new Error('offline')) : get(...args)) },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, vars = {}) => `/index.php${path.replace(/\{(\w+)\}/g, (_all, key) => vars[key])}`,
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', render() { return h('button', this.$slots.default?.()) } },
	NcTextArea: { name: 'NcTextArea', render: () => h('textarea') },
	NcTextField: { name: 'NcTextField', render: () => h('input') },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { name: 'CnDialog', render() { return h('div', [this.$slots.default?.(), this.$slots.actions?.()]) } },
	CnNoteCard: { name: 'CnNoteCard', props: ['text'], render() { return h('div', this.text) } },
}))

let Modal

beforeAll(async () => {
	globalThis.t = (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_all, key) => vars[key] ?? `{${key}}`)
	Modal = (await import('../../src/modals/NewContactIntakeModal.vue')).default
})

beforeEach(() => {
	get.mockReset()
	offline = false
})

/**
 * Mount the dialog for a caller.
 *
 * @return {object} The wrapper.
 */
function mountModal() {
	return mount(Modal, {
		props: { e164: '+31612345678', prefillPhone: '0612345678' },
		global: { mixins: [{ methods: { t: (_app, text, vars) => globalThis.t(_app, text, vars) } }] },
	})
}

describe('NewContactIntakeModal earlier contacts', () => {
	it('asks the phone search for the caller and lists the earlier contact moments', async () => {
		get.mockResolvedValue({
			data: {
				number: '+31612345678',
				results: [
					{ id: 't-2', title: 'Vraag over afvalpas', ticketType: 'interaction', occurredAt: '2026-10-02T10:00:00+00:00' },
					{ id: 't-1', title: 'Melding losliggende stoeptegel', ticketType: 'request', occurredAt: '2026-09-20T10:00:00+00:00' },
				],
			},
		})
		const w = mountModal()
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/tickets/by-phone', { params: { number: '+31612345678' } })
		const links = w.findAll('[data-testid="cti-intake-history"] a')
		expect(links.map((link) => link.text())).toEqual(['Vraag over afvalpas', 'Melding losliggende stoeptegel'])
		expect(links[0].attributes('href')).toBe('/index.php/apps/pipelinq/tickets/t-2')
		expect(w.find('[data-testid="cti-intake-history"]').text()).toContain('Contact moment')
	})

	it('says there are no earlier contacts for a first-time caller', async () => {
		get.mockResolvedValue({ data: { number: '+31612345678', results: [] } })
		const w = mountModal()
		await flushPromises()

		expect(w.find('[data-testid="cti-intake-history-empty"]').text()).toBe('No earlier contacts from this number.')
	})

	it('says the same when the search fails, and the form stays usable', async () => {
		offline = true
		const w = mountModal()
		await flushPromises()

		expect(w.find('[data-testid="cti-intake-history-empty"]').exists()).toBe(true)
		expect(w.find('form').exists()).toBe(true)
	})
})
