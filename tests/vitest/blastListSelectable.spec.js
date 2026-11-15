/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The newsletter list draws a selection column, as board PqNieuwsbrieven
 * does, and a selection can be downloaded.
 *
 * @spec openspec/specs/marketing-ui/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '..', '..')
const fragment = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.d/75-marketing-blasts.json'), 'utf8'))

describe('newsletter list selection', () => {
	const blasts = fragment.pages.find((p) => p.id === 'Blasts')

	it('draws the selection column', () => {
		expect(blasts.config.selectable).toBe(true)
	})

	it('offers Download for a selection, and no other bulk action', () => {
		expect(blasts.config.showMassExport).toBe(true)
		expect(blasts.config.showMassDelete).toBe(false)
		expect(blasts.config.showMassCopy).toBe(false)
		expect(blasts.config.showMassImport).toBe(false)
	})
})
