// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An unknown caller's earlier contacts are shown in the intake dialog
 * (REQ-CHP-001): read from the phone search over tickets for the caller's
 * number, each linking to its ticket, and an empty or failed read says so.
 *
 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.5
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const get = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => get(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

vi.mock('@nextcloud/vue', () => {
	const field = (name, tag) => ({ name, props: ['id', 'label', 'labelOutside', 'modelValue', 'placeholder'], render() { return h(tag, { id: this.id }) } })
	return {
		NcButton: { name: 'NcButton', render() { return h('button', this.$slots.default?.()) } },
		NcTextArea: field('NcTextArea', 'textarea'),
		NcTextField: field('NcTextField', 'input'),
	}
})

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { name: 'CnDialog', render() { return h('div', [this.$slots.default?.(), this.$slots.actions?.()]) } },
	CnNoteCard: { name: 'CnNoteCard', props: ['text', 'type'], render() { return h('div', this.text) } },
}))

let Modal

/**
 * @return {Promise<object>} The settled dialog for an unknown caller.
 */
async function mountModal() {
	const wrapper = mount(Modal, {
		props: { e164: '+31612345678' },
		global: { mixins: [{ methods: { t: (_app, text, vars) => globalThis.t(_app, text, vars) } }] },
	})
	await flushPromises()
	return wrapper
}

beforeAll(async () => {
	globalThis.t = (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_all, key) => vars[key] ?? `{${key}}`)
	Modal = (await import('../../src/modals/NewContactIntakeModal.vue')).default
})

beforeEach(() => {
	get.mockReset()
})

describe('earlier contacts from an unknown number', () => {
	it('asks the phone search for the caller\'s number and lists each earlier contact with a link', async () => {
		get.mockResolvedValue({
			data: {
				results: [
					{ id: 't-2', title: 'Vraag over afvalpas', ticketType: 'contactmoment', occurredAt: '2026-10-12T09:00:00Z', assignee: 'anna', _phoneMatch: 'exact' },
					{ id: 't-1', title: 'Klacht container', ticketType: 'complaint', occurredAt: '2026-10-01T09:00:00Z', _phoneMatch: 'possible' },
				],
			},
		})

		const w = await mountModal()

		expect(get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/tickets/by-phone', { params: { number: '+31612345678' } })
		const items = w.findAll('[data-testid="cti-intake-history-item"]')
		expect(items).toHaveLength(2)
		expect(items[0].find('a').text()).toBe('Vraag over afvalpas')
		expect(items[0].find('a').attributes('href')).toBe('/index.php/apps/pipelinq/tickets/t-2')
		expect(items[0].text()).toContain('Contact moment · anna')
		expect(items[0].text()).not.toContain('possible match')
		expect(items[1].text()).toContain('Ticket')
		expect(items[1].text()).toContain('possible match')
	})

	it('says there are no earlier contacts for a first-time caller', async () => {
		get.mockResolvedValue({ data: { results: [] } })

		const w = await mountModal()

		expect(w.find('[data-testid="cti-intake-history-empty"]').text()).toBe('There are no earlier contacts from this number.')
		expect(w.findAll('[data-testid="cti-intake-history-item"]')).toHaveLength(0)
	})

	it('says so when the earlier contacts cannot be read', async () => {
		get.mockRejectedValue(new Error('500'))

		const w = await mountModal()

		expect(w.find('[data-testid="cti-intake-history-failed"]').exists()).toBe(true)
		expect(w.find('[data-testid="cti-intake-history-empty"]').exists()).toBe(false)
	})
})
