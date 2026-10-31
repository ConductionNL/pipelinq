// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Decisions tab on the ticket page (ticket-decisions-tab). The tab is
 * manifest config: a tabs widget whose Decisions tab stacks the head (what the
 * tab holds, the two routes, Convert to case), decidiq's leaf for the ticket
 * and the same leaf for the case the ticket became. So the page is tested as
 * the manifest declares it, and the head as the one component pipelinq owns.
 *
 * @spec openspec/specs/ticket-decisions/spec.md#requirement-the-ticket-page-has-a-decisions-tab-req-tdt-001
 * @spec openspec/specs/ticket-decisions/spec.md#requirement-decisions-on-the-linked-case-show-on-the-ticket-req-tdt-002
 */

import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import manifest from '../../src/manifest.json'
import { pageAppConfig } from '../../src/utils/pageAppConfig.js'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
}))
vi.mock('../../src/views/requests/RequestConversionSection.vue', () => ({
	default: {
		name: 'RequestConversionSection',
		props: ['requestId'],
		render() {
			return h('div', {
				'data-stub': 'convert',
				'data-request': this.requestId,
			})
		},
	},
}))

const { default: TicketDecisionRoutes } =
	await import('../../src/components/tickets/TicketDecisionRoutes.vue')

const page = manifest.pages.find((entry) => entry.id === 'TicketDetail')
const widgets = page.config.widgets
const byId = (id) => widgets.find((widget) => widget.id === id)

describe('the ticket page declares a Decisions tab', () => {
	const panels = byId('ticket-panels')
	const decisions = panels.content.tabs.find((tab) => tab.label === 'Decisions')

	it('has a tab strip with Overview first and Decisions beside it', () => {
		expect(panels.type).toBe('tabs')
		expect(panels.content.tabs.map((tab) => tab.label)).toEqual([
			'Overview',
			'Decisions',
		])
		expect(panels.content.tabs[0].widgetId).toBe('ticket-data')
	})

	it("stacks the head, the ticket's decisions and the case's decisions in that tab", () => {
		expect(decisions.widgetIds).toEqual([
			'ticket-decision-routes',
			'ticket-decisions',
			'ticket-case-decisions',
		])
		for (const id of decisions.widgetIds) {
			expect(byId(id), id).toBeTruthy()
		}
	})

	it('hides the tab without decidiq', () => {
		expect(decisions.visibleWhen).toEqual({ appInstalled: 'decidiq' })
	})

	it("mounts decidiq's leaf for the ticket itself, with no other host named and no collapse", () => {
		const leaf = byId('ticket-decisions')
		expect(leaf.type).toBe('integration')
		expect(leaf.integrationId).toBe('decidesk-decisions')
		expect(leaf.props).toEqual({ collapsible: false })
	})

	it("gives the leaf the ticket's words for its empty state (board PqTicketBesluitvorming)", () => {
		// nextcloud-vue translates `labels` in pipelinq's language and hands
		// them to the leaf (leaf-labels-in-the-host-language); decidiq draws
		// them (decisions-leaf-speaks-the-hosts-words).
		const leaf = byId('ticket-decisions')
		expect(leaf.labels).toEqual({
			emptyTitle: 'No decision linked',
			emptyText: 'A decision can be prepared straight from this ticket as a proposal in decidiq, or the request becomes a case in dossiq first. Either way the decision shows here.',
			createLabel: 'Create a proposal for this ticket',
		})
		const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
		expect(nl['No decision linked']).toBe('Geen besluit gekoppeld')
		expect(nl['Create a proposal for this ticket']).toBe('Voorstel maken voor dit ticket')
		expect(nl[leaf.labels.emptyText]).toBeTruthy()
	})

	it("mounts the same leaf for the linked case, by the ticket's caseReference", () => {
		const leaf = byId('ticket-case-decisions')
		expect(leaf.type).toBe('integration')
		expect(leaf.integrationId).toBe('decidesk-decisions')
		// Tokens, so the host mounts nothing on a ticket without a case or on
		// an instance without a case app
		// (nextcloud-vue CnDetailWidgetHost.widgetPropTokens.spec.js).
		expect(leaf.props).toEqual({
			register: '@config.caseRegister',
			schema: '@config.caseSchema',
			objectId: '@object.caseReference',
		})
	})

	it("names no other app's register or schema: the case app is looked up", () => {
		// `caseReference` is app-agnostic (ADR-048), so the widget is too.
		const leaf = byId('ticket-case-decisions')
		for (const value of Object.values(leaf.props)) {
			expect(value.charAt(0), value).toBe('@')
		}
	})

	it('shows the decisions in one place: no cell of their own on the overview', () => {
		const placed = page.config.layout.map((cell) => cell.widgetId)
		expect(placed).toEqual(['ticket-panels', 'ticket-related'])
		// The strip renders its children; a cell would render them twice.
		const children = panels.content.tabs.flatMap(
			(tab) => tab.widgetIds || [tab.widgetId],
		)
		for (const id of children) {
			expect(placed, id).not.toContain(id)
		}
	})

	it('places every declared widget exactly once, in a cell or in a tab', () => {
		const placed = page.config.layout.map((cell) => cell.widgetId)
		const children = panels.content.tabs.flatMap(
			(tab) => tab.widgetIds || [tab.widgetId],
		)
		expect([...placed, ...children].sort()).toEqual(
			widgets.map((widget) => widget.id).sort(),
		)
	})
})

describe('where cases live reaches the page as app config', () => {
	const config = { currency: 'EUR', pipelineTarget: 0 }

	it("adds the installed case app's register and schema", () => {
		expect(
			pageAppConfig(config, {
				caseRegister: 'cases',
				caseSchema: 'case-file',
			}),
		).toEqual({ ...config, caseRegister: 'cases', caseSchema: 'case-file' })
	})

	it.each([
		['no case app', {}],
		['nothing at all', undefined],
		['a register without a schema', { caseRegister: 'cases' }],
		['an empty schema', { caseRegister: 'cases', caseSchema: '' }],
		['a value that is no text', { caseRegister: 'cases', caseSchema: 7 }],
	])('adds neither key with %s', (label, caseTarget) => {
		expect(pageAppConfig(config, caseTarget)).toEqual(config)
	})

	it('does not let the config state stand in for the case target', () => {
		expect(pageAppConfig({ ...config, caseRegister: 'left-over' }, {})).toEqual(
			config,
		)
	})

	it('hands main.js both states', () => {
		const main = fs.readFileSync(
			path.resolve(__dirname, '../../src/main.js'),
			'utf8',
		)
		expect(main).toMatch(
			/pageAppConfig\(\s*loadState\('pipelinq', 'config', \{\}\),\s*loadState\('pipelinq', 'case_target', \{\}\),\s*\)/,
		)
	})
})

describe('TicketDecisionRoutes', () => {
	/**
	 * Mount the head on ticket t-1, with the app's global translate.
	 *
	 * @param {object} props Props beside the ticket id.
	 * @return {object} The wrapper.
	 */
	function mountRoutes(props = {}) {
		return mount(TicketDecisionRoutes, {
			props: { ticketId: 't-1', ...props },
			global: { mocks: { t: (app, text) => text } },
		})
	}

	it('says what the tab holds; the two routes are the leaf\'s empty state, not a second line here', () => {
		const wrapper = mountRoutes()

		expect(wrapper.text()).toContain(
			'Formal decisions on this ticket, made and signed in decidiq',
		)
		expect(wrapper.text()).not.toContain('the request becomes a case in dossiq')
	})

	it('offers the existing Convert to case action for this ticket', () => {
		const wrapper = mountRoutes()

		expect(
			wrapper.find('[data-stub="convert"]').attributes('data-request'),
		).toBe('t-1')
	})

	it('leaves Convert to case out when the page already offers it', () => {
		const wrapper = mountRoutes({ hideConvert: true })

		expect(wrapper.find('[data-stub="convert"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('made and signed in decidiq')
	})
})
