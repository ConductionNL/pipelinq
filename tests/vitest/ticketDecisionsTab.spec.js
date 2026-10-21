// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Decisions tab on the ticket page (ticket-decisions-tab). The tab is
 * manifest config: a tabs widget whose Decisions tab stacks the head (what the
 * tab holds, the two routes, Convert to case), decidiq's leaf for the ticket
 * and the same leaf for the case the ticket became. The head is config too:
 * two text widgets and the library `handoff` for Convert to case. So the page
 * is tested as the manifest declares it.
 *
 * @spec openspec/changes/ticket-decisions-tab/specs/ticket-decisions/spec.md#requirement-the-ticket-page-has-a-decisions-tab-req-tdt-001
 * @spec openspec/changes/ticket-decisions-tab/specs/ticket-decisions/spec.md#requirement-decisions-on-the-linked-case-show-on-the-ticket-req-tdt-002
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'
import simpleLayout from '../../src/menu-layout.simple.json'
import { pageAppConfig } from '../../src/utils/pageAppConfig.js'

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
			'ticket-decision-lead',
			'ticket-decision-routes',
			'ticket-decision-convert',
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

	it("mounts decidiq's leaf for the ticket itself, with no other host named", () => {
		const leaf = byId('ticket-decisions')
		expect(leaf.type).toBe('integration')
		expect(leaf.integrationId).toBe('decidesk-decisions')
		expect(leaf.props).toBeUndefined()
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

describe('the head of the Decisions tab', () => {
	it('says what the tab holds and names the two routes, in the app catalogue', () => {
		expect(byId('ticket-decision-lead').type).toBe('text')
		expect(byId('ticket-decision-lead').content.text).toBe(
			'Formal decisions on this ticket, made and signed in decidiq',
		)
		expect(byId('ticket-decision-routes').type).toBe('text')
		expect(byId('ticket-decision-routes').content.text).toBe(
			'A decision can be prepared straight from this ticket as a proposal in decidiq, or the request becomes a case in dossiq first. Either way the decision shows here.',
		)
		const nl = JSON.parse(
			fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8'),
		).translations
		expect(nl[byId('ticket-decision-lead').content.text]).toBeTruthy()
		expect(nl[byId('ticket-decision-routes').content.text]).toBeTruthy()
	})

	it('offers the existing Convert to case action for this ticket', () => {
		const convert = byId('ticket-decision-convert')
		expect(convert.type).toBe('handoff')
		expect(convert.content.availability.url).toBe(
			'/apps/pipelinq/api/handoff/request/@objectId/availability',
		)
		expect(convert.content.action.url).toBe(
			'/apps/pipelinq/api/handoff/request/@objectId/convert-to-case',
		)
	})

	it('leaves Convert to case out of the simple page, which offers it in its Case section', () => {
		const ticket = simpleLayout.pages.find((entry) => entry.id === 'TicketDetail')
		expect(ticket.configPatch.widgets['ticket-decision-convert']).toBeNull()
		const panels = ticket.configPatch.widgets['ticket-panels']
		const decisions = panels.content.tabs.find((tab) => tab.label === 'Decisions')
		expect(decisions.widgetIds).toEqual([
			'ticket-decision-lead',
			'ticket-decision-routes',
			'ticket-decisions',
			'ticket-case-decisions',
		])
		const overview = panels.content.tabs.find((tab) => tab.label === 'Overview')
		expect(overview.widgetIds).toContain('ticket-case')
	})
})
