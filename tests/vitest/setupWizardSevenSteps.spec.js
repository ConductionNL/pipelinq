/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The setup wizard as board PqInstellen draws it: seven steps (Welkom,
 * Voorbeeldgegevens, Valuta, Organisatie, Kassa, Integraties, Klaar), the
 * field steps with "(optioneel)" labels, "Deze stap overslaan" and the note
 * that each field is saved as you continue.
 *
 * @spec openspec/changes/setup-wizard-seven-steps/specs/first-time-setup/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const steps = manifest.setup.steps
const step = (id) => steps.find((s) => s.id === id)

describe('the setup wizard', () => {
	it('has the board\'s seven steps in order', () => {
		expect(steps.map((s) => s.id)).toEqual(['welcome', 'demo-data', 'currency', 'organisation', 'pos', 'integrations', 'done'])
	})

	it('marks the field steps optional, skippable and saved as you continue', () => {
		for (const id of ['organisation', 'pos', 'integrations']) {
			expect(step(id).type).toBe('config-fields')
			expect(step(id).optionalLabels).toBe(true)
			expect(step(id).skippable).toBe(true)
			expect(step(id).note).toMatch(/^Saved per field as you continue\./)
		}
	})

	it('asks the till and the integrations for settings the app reads', () => {
		expect(Object.keys(step('pos').schema.properties)).toEqual(['receipt_printer_host', 'receipt_printer_port', 'receipt_email_sender'])
		expect(Object.keys(step('integrations').schema.properties)).toEqual(['shillinq_journal_webhook_url', 'billing_handoff_manager_group'])
	})
})
