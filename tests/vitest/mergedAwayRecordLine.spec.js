/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A client or contact merged into another record shows one line that says
 * where it went, with a link to the surviving record (decision 288, board
 * PqKlant), and its own work actions step aside.
 *
 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-013-mdm-steward-ui-deep-linked-to-openregister
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const page = (id) => manifest.pages.find((entry) => entry.id === id).config
const MERGED = { field: 'mergedInto', op: 'notEmpty' }

describe.each([
	['ClientDetail', 'client-merged-away'],
	['ContactDetail', 'contact-merged-away'],
])('%s', (pageId, widgetId) => {
	const config = page(pageId)
	const line = config.widgets.find((widget) => widget.id === widgetId)

	it('shows the merged-into line only on a merged-away record, at the top of the band after the party strip', () => {
		expect(line.type).toBe('banner')
		expect(line.visibleWhen).toEqual(MERGED)
		expect(line.content.visibleWhen).toEqual(MERGED)
		const at = config.band.findIndex((entry) => entry.widgetId === widgetId)
		expect(config.band[at]).toEqual({ widgetId, span: 12, chrome: 'bare' })
		expect(config.band.slice(0, at).every((entry) => entry.widgetId.endsWith('contact-strip'))).toBe(true)
	})

	it('names the surviving record and the date, and links to it on its own page', () => {
		expect(line.content.text).toContain('{subject.mergedIntoLabel}')
		expect(line.content.text).toContain('{subject.mergedAt|date}')
		expect(line.content.actions).toEqual([{ label: 'Open the merged record', route: { name: pageId, params: { id: '@subject.mergedInto' } } }])
	})
})

describe('ClientDetail on a merged-away client', () => {
	it('hides the actions that add work to the client', () => {
		const actions = page('ClientDetail').headerActions
		;['log-contact', 'new-request'].forEach((id) => {
			expect(actions.find((action) => action.id === id).visibleWhen).toEqual({ field: 'mergedInto', op: 'empty' })
		})
	})
})

describe('a merged-away client or contact cannot be edited', () => {
	it('makes ClientDetail and ContactDetail read-only while mergedInto is set', () => {
		expect(page('ClientDetail').readOnlyWhen).toEqual(MERGED)
		expect(page('ContactDetail').readOnlyWhen).toEqual(MERGED)
	})
})
