/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The event's history as board PqEvenement words it: no reads, "Plaatsen
 * verhoogd van 60 naar 80", "Joke van Dam staat op de wachtlijst", the side
 * card the newest four.
 *
 * @spec openspec/changes/campaign-events/specs/marketing-campaigns/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (path) => JSON.parse(readFileSync(resolve(__dirname, '../..', path), 'utf8'))
const page = read('src/manifest.d/78-campaign-events.json').pages.find((p) => p.id === 'CampaignEventDetail')
const widget = (id) => page.config.widgets.find((w) => w.id === id)
const nl = read('l10n/nl.json').translations

describe('event history in words', () => {
	for (const id of ['event-history', 'event-side-history']) {
		it(`${id} leaves reads out and words what changed`, () => {
			const { content } = widget(id)
			expect(content.excludeActions).toEqual(['read'])
			expect(content.changeLabels).toContainEqual(expect.objectContaining({ field: 'capacity', label: 'Places raised from {changed.capacity.old} to {changed.capacity.new}' }))
			expect(content.related).toContainEqual(expect.objectContaining({
				schema: 'eventRegistration',
				field: 'event',
				dateField: 'registeredAt',
				label: '{name} is on the waiting list',
				when: { field: 'status', value: 'waitlist' },
			}))
		})
	}

	it('keeps the newest four in the side card', () => {
		expect(widget('event-side-history').content.limit).toBe(4)
	})

	it('says it in Dutch as the board does', () => {
		expect(nl['Places raised from {changed.capacity.old} to {changed.capacity.new}']).toBe('Plaatsen verhoogd van {changed.capacity.old} naar {changed.capacity.new}')
		expect(nl['{name} is on the waiting list']).toBe('{name} staat op de wachtlijst')
	})
})

describe('the invitation newsletter and the campaign in the history', () => {
	const blastEvent = read('lib/Settings/register.d/99-zzzzz-blast-event.json').components.schemas.blast

	it('lets a newsletter name the event it invites to', () => {
		expect(blastEvent.version).toBe('1.4.0')
		expect(blastEvent.properties.event).toMatchObject({ type: 'string', format: 'uuid', $ref: 'campaignEvent' })
	})

	for (const id of ['event-history', 'event-side-history']) {
		it(`${id} lists the newsletter sent with the invitation and names the campaign`, () => {
			const { content } = widget(id)
			expect(content.related).toContainEqual(expect.objectContaining({
				schema: 'blast',
				field: 'event',
				dateField: 'sentAt',
				label: '{name} sent with the invitation',
				when: { field: 'status', value: 'sent' },
			}))
			expect(content.actionLabels.create).toBe('Event created in campaign {object.campaign}')
			expect(content.resolve).toEqual({ campaign: { schema: 'campaign' } })
		})
	}

	it('says it in Dutch as the board does', () => {
		expect(nl['{name} sent with the invitation']).toBe('{name} verstuurd met de uitnodiging')
		expect(nl['Event created in campaign {object.campaign}']).toBe('Evenement aangemaakt in campagne {object.campaign}')
	})
})
