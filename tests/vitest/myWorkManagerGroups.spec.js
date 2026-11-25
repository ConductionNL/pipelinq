/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin setting that names the team manager groups for My work's team
 * scope: declared as a settings section, saved in the app config, mounted
 * for administrators only.
 *
 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import nl from '../../l10n/nl.json'
import sections from '../../src/views/settings/adminSections.json'

const settingsPage = readFileSync(resolve(__dirname, '../../src/views/settings/Settings.vue'), 'utf8')
const settingsService = readFileSync(resolve(__dirname, '../../lib/Service/SettingsService.php'), 'utf8')

describe('team manager groups setting', () => {
	it('is a text field in the My work section, saved in the app config', () => {
		const section = sections.myWork
		expect(section.valuesPath).toBe('config')
		expect(section.url).toBeUndefined()
		const field = section.fields.find((f) => f.key === 'my_work_manager_groups')
		expect(field).toMatchObject({ label: 'Team manager groups' })
		expect(field.type ?? 'text').toBe('text')
		expect(nl.translations[field.hint]).toBeTruthy()
	})

	it('has an empty default, so nobody has a team scope until an admin names a group', () => {
		expect(settingsService).toMatch(/'my_work_manager_groups' => ''/)
	})

	it('is mounted on the admin page for administrators only', () => {
		expect(settingsPage).toMatch(/v-if="isAdmin"\s+v-bind="sections\.myWork"/)
	})
})
