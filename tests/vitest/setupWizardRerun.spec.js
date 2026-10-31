/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Running the first-time setup again from the admin page: the admin shell's
 * own "Run setup wizard" action, with the manifest's setup steps.
 *
 * @spec openspec/changes/pl-abstract-admin-sections/specs/admin-settings/spec.md#requirement-the-setup-wizard-opens-from-the-admin-shell
 */
import { readFileSync } from 'fs'
import { resolve } from 'path'
import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'

const source = readFileSync(
	resolve(__dirname, '../../src/views/settings/Settings.vue'),
	'utf8',
)

describe('run the setup wizard again', () => {
	it('asks the admin shell for its setup action, for administrators', () => {
		expect(source).toMatch(/<CnAdminSettingsShell[\s\S]*?:showSetup="isAdmin"[\s\S]*?:setupSteps="setupSteps"/)
	})

	it('passes the manifest\'s setup steps', () => {
		expect(manifest.setup.steps.length).toBeGreaterThan(0)
		expect(source).toMatch(/setupSteps: \(manifest\.setup && manifest\.setup\.steps\) \|\| \[\]/)
	})

	// setup-wizard-close-on-server: the close is recorded on the server and in
	// localStorage. The admin page must not read either, or it could never reopen.
	it('does not read whether the wizard was closed before', () => {
		expect(source).not.toMatch(/dismissed|localStorage|setup\/status/)
	})

	it('has no setup wizard section of its own any more', () => {
		expect(source).not.toMatch(/SetupWizardSection/)
	})
})
