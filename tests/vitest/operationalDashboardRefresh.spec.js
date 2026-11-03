/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The operational dashboard draws one Refresh: its own header button. The
 * page Actions menu would add a second, so the page stands it down with
 * showRefresh: false.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const page = manifest.pages.find((p) => p.id === 'OperationalDashboard')

describe('OperationalDashboard Refresh', () => {
	it('declares its own Refresh button', () => {
		expect(page.config.headerActions.some((action) => action.type === 'refresh')).toBe(true)
	})

	it('does not also offer Refresh in the Actions menu', () => {
		expect(page.config.showRefresh).toBe(false)
	})
})
