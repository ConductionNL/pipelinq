/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client page's Activity card as PqKlant and PqOrganisatie draw it: the
 * last card of the overview, with Link email and Compose in Mail in its
 * header, a chip per kind, the internal-note box and the feed rows. The
 * library's timeline widget draws it from /api/timeline; pipelinq's own
 * ActivityTimeline is gone.
 *
 * @spec openspec/changes/client-activity-card/specs/client-detail/spec.md#requirement-the-overview-ends-with-the-activity-card
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations
const config = manifest.pages.find((page) => page.id === 'ClientDetail').config
const widget = (id) => config.widgets.find((entry) => entry.id === id)

describe('ClientDetail Activity card', () => {
	it('ends the overview with the activity card, titled as the boards', () => {
		const overview = widget('client-panels').content.tabs[0]
		expect(overview.widgetIds[overview.widgetIds.length - 1]).toBe('client-activity')
		const card = widget('client-activity')
		expect(card.type).toBe('timeline')
		expect(nl[card.title]).toBe('Activiteit')
	})

	it('offers E-mail koppelen and Opstellen in Mail as pills in the card header', () => {
		const actions = widget('client-activity').headerActions
		expect(actions.map((action) => nl[action.label])).toEqual(['E-mail koppelen', 'Opstellen in Mail'])
		expect(actions.every((action) => action.variant === 'tonal')).toBe(true)
		expect(actions[0].action).toEqual({ type: 'open-modal', target: 'EmailLinkDialog', props: { register: 'pipelinq', schema: 'client', objectId: '@objectId' } })
		expect(actions[1].action).toEqual({ type: 'navigate', target: '/index.php/apps/mail/compose?uri=mailto:@object.email' })
	})

	it('reads the merged timeline, newest first, as feed rows with the author by name', () => {
		const content = widget('client-activity').content
		expect(content.layout).toBe('feed')
		expect(content.order).toBe('desc')
		expect(content.endpointSource.url).toBe('/apps/pipelinq/api/timeline')
		expect(content.endpointSource.params).toEqual({ entityType: 'client', entityId: '@objectId' })
		expect(content.endpointSource.userField).toBe('userName')
	})

	it('draws a chip per kind in the boards\' words, and names each kind in the meta line', () => {
		const content = widget('client-activity').content
		expect(content.typeChips.map((chip) => chip.value)).toEqual(['interaction', 'note', 'task', 'email', 'calendar', 'message'])
		expect(content.typeChips.map((chip) => nl[chip.label])).toEqual(['Contactmomenten', 'Notities', 'Taken', 'E-mail', 'Agenda', 'Berichten'])
		const labels = content.endpointSource.typeLabels
		expect(Object.fromEntries(Object.entries(labels).map(([type, label]) => [type, nl[label]]))).toEqual({
			interaction: 'contactmoment',
			worklog: 'werklog',
			note: 'notitie',
			task: 'taak',
			email: 'e-mail, gekoppeld',
			calendar: 'agenda',
			message: 'bericht',
		})
	})

	it('takes an internal note with the boards\' words', () => {
		const composer = widget('client-activity').content.composer
		expect(nl[composer.placeholder]).toBe('Voeg een interne notitie toe. Typ @ om een collega te noemen.')
		expect(nl[composer.submitLabel]).toBe('Notitie toevoegen')
		expect(nl[composer.label]).toBe('Notitie toevoegen')
	})

	it('no longer mounts pipelinq\'s own activity timeline', () => {
		expect(config.bodyWidgets.map((entry) => entry.component)).not.toContain('ActivityTimeline')
		expect(fs.existsSync(path.join(ROOT, 'src/components/ActivityTimeline.vue'))).toBe(false)
		expect(fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')).not.toMatch(/ActivityTimeline/)
	})

	it('registers the link-an-email dialog the header opens', () => {
		expect(fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')).toMatch(/EmailLinkDialog/)
	})
})
