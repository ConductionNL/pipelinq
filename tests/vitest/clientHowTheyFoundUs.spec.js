/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client page's Identity card says how a resident found us, as PqKlant
 * draws it: the form and when, the landing page, and the campaign with its
 * source, from the client's last web form submission.
 *
 * @spec openspec/changes/marketing-campaign-on-client/specs/marketing-origin-on-client/spec.md#requirement-contacts-and-clients-show-how-they-found-you-req-moc-001
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(__dirname, '../..')
const manifest = JSON.parse(readFileSync(resolve(root, 'src/manifest.json'), 'utf8'))
const nl = JSON.parse(readFileSync(resolve(root, 'l10n/nl.json'), 'utf8')).translations
const config = manifest.pages.find((p) => p.id === 'ClientDetail').config
const identity = config.sideColumn.find((entry) => typeof entry === 'object' && entry.id === 'side-identity')

describe('How a resident found us on the client Identity card', () => {
	it('follows the client number and source with the form, the landing page and the campaign', () => {
		expect(identity.content.fields.map((field) => field.label)).toEqual(['Client number', 'Source', 'Form', 'Landing page', 'Campaign'])
	})

	it('reads them from the last touch: the form with its date, the page, the campaign and its source', () => {
		const [, , form, page, campaign] = identity.content.fields
		expect(form).toMatchObject({ parts: [{ path: 'lastTouch.form' }, { path: 'lastTouch.at', format: 'date' }], separator: ', ' })
		expect(page).toMatchObject({ path: 'lastTouch.landingPage' })
		expect(campaign).toMatchObject({ parts: ['lastTouch.campaign', 'lastTouch.source'] })
	})

	it('has the board\'s Dutch labels', () => {
		expect([nl.Form, nl['Landing page'], nl.Campaign]).toEqual(['Formulier', 'Landingspagina', 'Campagne'])
	})
})
