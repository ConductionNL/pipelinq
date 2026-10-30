/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PqKlant / PqOrganisatie: the tab strip's cell is as tall as the open tab.
 * pl6 on :8080 held it at 18 grid rows: Sanne de Vries's Overview ran past
 * it, so the Activity card was cut off under its note field, and Buurtvereniging
 * Oost's shorter Overview left a gap above Berichten and Contactmoment registreren.
 *
 * @spec openspec/changes/client-panels-fit-content/specs/client-detail/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const config = manifest.pages.find((page) => page.id === 'ClientDetail').config

describe('ClientDetail tab strip height', () => {
	it('sizes the tab strip cell to its content', () => {
		const panels = config.layout.find((item) => item.widgetId === 'client-panels')
		expect(panels.sizeToContent).toBe(true)
	})
})
