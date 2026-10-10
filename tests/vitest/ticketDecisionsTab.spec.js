// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Decisions tab on the ticket page (ticket-decisions-tab, tasks 1.2 and
 * 2.1): the board's heading and empty state, decidiq's leaf mounted for the
 * ticket, and the decisions on the linked case read through OpenRegister.
 *
 * @spec openspec/changes/ticket-decisions-tab/specs/ticket-decisions/spec.md#requirement-the-ticket-page-has-a-decisions-tab-req-tdt-001
 * @spec openspec/changes/ticket-decisions-tab/specs/ticket-decisions/spec.md#requirement-decisions-on-the-linked-case-show-on-the-ticket-req-tdt-002
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({ get: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params) =>
		'/index.php'
		+ String(path).replace(/\{(\w+)\}/g, (whole, key) =>
			params && key in params ? params[key] : whole,
		),
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		String(text).replace(/\{(\w+)\}/g, (whole, key) =>
			vars && key in vars ? String(vars[key]) : whole,
		),
}))

/**
 * A stub that shows its props, so the test can read what was mounted.
 *
 * @param {string} name The component name.
 * @return {object} The stub.
 */
function stub(name) {
	return {
		name,
		inheritAttrs: false,
		props: ['only', 'register', 'schema', 'objectId', 'surface'],
		render() {
			return h('div', { 'data-stub': name, 'data-object': this.objectId })
		},
	}
}

vi.mock('@conduction/nextcloud-vue', () => ({
	CnIntegrationWidget: stub('CnIntegrationWidget'),
}))
vi.mock('../../src/views/requests/RequestConversionSection.vue', () => ({
	default: stub('RequestConversionSection'),
}))

const { default: TicketDecisionsTab } =
	await import('../../src/components/tickets/TicketDecisionsTab.vue')

/**
 * Answer the decisions read per subject.
 *
 * @param {object} bySubject Decisions keyed by subjectId.
 * @return {void}
 */
function answer(bySubject) {
	axiosMock.get.mockImplementation(async (url, { params }) => ({
		data: { results: bySubject[params.subjectId] || [] },
	}))
}

/**
 * Mount the tab on a ticket.
 *
 * @param {object} ticket The ticket.
 * @return {Promise<object>} The wrapper.
 */
async function mountOn(ticket) {
	const wrapper = mount(TicketDecisionsTab, {
		props: { objectId: ticket.id, objectData: ticket },
	})
	await flushPromises()
	return wrapper
}

describe('TicketDecisionsTab', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
	})

	it('shows the board heading and mounts decidiq\'s leaf for the ticket', async () => {
		answer({})
		const wrapper = await mountOn({ id: 't-1', ticketType: 'request' })

		expect(wrapper.find('h3').text()).toBe('Decisions')
		expect(wrapper.text()).toContain(
			'Formal decisions on this ticket, made and signed in decidiq',
		)
		const leaf = wrapper.findComponent({ name: 'CnIntegrationWidget' })
		expect(leaf.props()).toMatchObject({
			only: 'decidesk-decisions',
			register: 'pipelinq',
			schema: 'ticket',
			objectId: 't-1',
		})
	})

	it('explains the two routes when nothing is linked', async () => {
		answer({})
		const wrapper = await mountOn({ id: 't-1', ticketType: 'request' })

		const empty = wrapper.find('[data-testid="ticket-decisions-empty"]')
		expect(empty.text()).toContain('No decision linked')
		expect(empty.text()).toContain(
			'A decision can be prepared straight from this ticket as a proposal in decidiq, or the request becomes a case in dossiq first. Either way the decision shows here.',
		)
		expect(wrapper.findComponent({ name: 'RequestConversionSection' }).props('objectId')).toBe('t-1')
		expect(wrapper.find('[data-testid="ticket-decisions-open-decidiq"]').attributes('href')).toBe(
			'/index.php/apps/decidiq/decisions',
		)
		expect(axiosMock.get).toHaveBeenCalledTimes(1)
		expect(axiosMock.get.mock.calls[0][0]).toBe(
			'/index.php/apps/openregister/api/objects/decidiq/decision',
		)
	})

	it('lists the decisions on the linked case under the case title, linking to decidiq', async () => {
		answer({
			'case-9': [
				{ id: 'd-1', title: 'Vergunning verleend', subjectLabel: 'Zaak Z-2026-0031', lifecycle: 'decided' },
			],
		})
		const wrapper = await mountOn({ id: 't-1', ticketType: 'request', caseReference: 'case-9' })

		const caseBlock = wrapper.find('[data-testid="ticket-decisions-case"]')
		expect(caseBlock.find('h4').text()).toBe('On the case Zaak Z-2026-0031')
		const link = caseBlock.find('a')
		expect(link.text()).toBe('Vergunning verleend')
		expect(link.attributes('href')).toBe('/index.php/apps/decidiq/decisions/d-1')
		expect(wrapper.find('[data-testid="ticket-decisions-empty"]').exists()).toBe(false)
		expect(axiosMock.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/decidiq/decision',
			{ params: { subjectId: 'case-9', _limit: 100 } },
		)
	})

	it('hides the empty state while the ticket itself carries a decision', async () => {
		answer({ 't-1': [{ id: 'd-2', title: 'Voorstel subsidie' }] })
		const wrapper = await mountOn({ id: 't-1', ticketType: 'request' })
		expect(wrapper.find('[data-testid="ticket-decisions-empty"]').exists()).toBe(false)
	})

	it('shows only what OpenRegister returns for this user, and survives a refused read', async () => {
		axiosMock.get.mockRejectedValue(Object.assign(new Error('Forbidden'), { response: { status: 403 } }))
		const wrapper = await mountOn({ id: 't-1', ticketType: 'request', caseReference: 'case-9' })
		expect(wrapper.find('[data-testid="ticket-decisions-case"]').exists()).toBe(false)
		expect(wrapper.find('h3').text()).toBe('Decisions')
	})
})
