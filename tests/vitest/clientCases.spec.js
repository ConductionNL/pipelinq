/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PqKlant / PqOrganisatie Zaken: the client's cases from dossiq, read by the
 * client's master entity, in the overview, in their own tab and (on an
 * organisation) as Open zaken in the side column.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const manifest = read('src/manifest.json')
const nl = read('l10n/nl.json').translations
const routes = fs.readFileSync(path.join(ROOT, 'appinfo/routes.php'), 'utf8')
const config = manifest.pages.find((page) => page.id === 'ClientDetail').config
const widget = (id) => config.widgets.find((entry) => entry.id === id)
const side = (id) => config.sideColumn.find((entry) => entry.id === id)
const URL = '/apps/pipelinq/api/clients/@objectId/cases'

describe('ClientDetail cases', () => {
	it('reads the cases from the client cases endpoint, which the routes declare', () => {
		expect(routes).toContain("['name' => 'clientCase#index', 'url' => '/api/clients/{id}/cases', 'verb' => 'GET']")
		for (const content of [widget('client-cases').content, widget('client-cases-all').content, side('side-open-case').content]) {
			expect(content.endpointSource.url).toBe(URL)
			expect(content.endpointSource.responsePath).toBe('results')
			expect(content.rowHrefField).toBe('url')
		}
		expect(side('side-open-case').content.endpointSource.params.status).toBe('open')
	})

	it('draws a case as the boards do: title, number, type and handler, the status pill, done or due', () => {
		const content = widget('client-cases').content
		expect(content.limit).toBe(3)
		expect(content.title).toBe('{title}')
		expect(content.meta.parts.map((part) => nl[part] || part)).toEqual(['{identifier}', '{caseTypeTitle}', 'behandelaar {assigneeName}'])
		expect(content.pill).toEqual({ field: 'statusName', variant: 'purple', colorField: 'statusColour' })
		expect(content.trailing.map((part) => nl[part])).toEqual(['Klaar {endDate|date}', 'Uiterlijk {deadline|date}'])
		expect(nl[content.headerLink.label]).toBe('Alle {total} zaken bekijken')
	})

	it('opens the Cases tab from the overview card, the tab after Overview', () => {
		const tabs = widget('client-panels').content.tabs
		expect(tabs[1]).toMatchObject({ id: 'client-cases-tab', widgetId: 'client-cases-all', countFrom: 'widget' })
		expect(widget('client-cases').content.headerLink.href).toBe(`#${tabs[1].id}`)
		expect(nl[tabs[1].label]).toBe('Zaken')
	})

	it('shows Open zaken on an organisation, under the main contact', () => {
		const ids = config.sideColumn.map((entry) => (typeof entry === 'string' ? entry : entry.id))
		expect(ids.indexOf('side-open-case')).toBe(ids.indexOf('side-main-contact') + 1)
		expect(side('side-open-case').visibleWhen).toEqual({ field: 'type', op: 'eq', value: 'organization' })
		expect(nl[side('side-open-case').title]).toBe('Open zaken')
	})
})
