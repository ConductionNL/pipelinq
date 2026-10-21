// @vitest-environment node
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the resident sees on a request ticket (portal-resident-view-preview).
 * GET /api/tickets/{id}/resident-view answers the panels as translated
 * `{ label, value }` rows (ResidentViewControllerTest); the page draws them as
 * three nextcloud-vue facts cards that read the endpoint and reload on the
 * page refresh signal a saved answer sends. This file pins the config.
 *
 * @spec openspec/changes/resident-view-as-configuration/specs/resident-view-preview/spec.md#requirement-the-ticket-page-draws-the-preview-as-facts-cards
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (...parts) => readFileSync(resolve(__dirname, '../..', ...parts), 'utf8')
const manifest = JSON.parse(read('src', 'manifest.json'))
const sections = manifest.pages.find((p) => p.id === 'TicketDetail').config.bodyWidgets
const byId = (id) => sections.find((s) => s.id === id)

describe('the resident view on a ticket', () => {
	it.each([
		['resident-view', 'residentRows'],
		['resident-view-portal', 'portalRows'],
		['resident-view-internal', 'internalRows'],
	])('%s draws the %s the endpoint answers, on request tickets only', (id, rows) => {
		const card = byId(id)
		expect(card.type).toBe('facts')
		expect(card.component).toBeUndefined()
		expect(card.visibleWhen).toEqual({ field: 'ticketType', op: 'eq', value: 'request' })
		expect(card.content.endpointSource).toEqual({ url: '/apps/pipelinq/api/tickets/@objectId/resident-view' })
		expect(card.content.rowsPath).toBe(rows)
		expect(card.content.errorText).toBe('What the resident sees could not be loaded.')
	})

	it('says everything else stays internal', () => {
		expect(byId('resident-view').content.notice).toEqual({ text: 'Everything else on this ticket stays internal.' })
	})

	it('no longer ships the app-local section', () => {
		expect(read('src', 'registry.js')).not.toContain('ResidentView')
	})
})
