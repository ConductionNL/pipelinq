/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PqKlant / PqOrganisatie: the six figures stand in one row of 67px tiles
 * above the tabs. pl6 on :8080 wrapped them into two rows of 220px-minimum
 * cards (four, then two), which pushed the tabs, the side column and every
 * card under them 67 to 79px down.
 *
 * @spec openspec/changes/client-band-compact/specs/client-detail/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const config = manifest.pages.find((page) => page.id === 'ClientDetail').config

describe('ClientDetail band', () => {
	it('draws the band compact, as the client boards do', () => {
		expect(config.bandDensity).toBe('compact')
	})
})
