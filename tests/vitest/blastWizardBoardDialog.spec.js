// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The new-newsletter wizard is the board dialog of PqNieuwsbriefWizard: the
 * step over the title ("Nieuwe nieuwsbrief, stap 6 van 6") and what it asks
 * under it, 720 wide, with Annuleren, Terug and Blast aanmaken.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: { name: 'CnDialog' } }))
vi.mock('@nextcloud/vue', () => Object.fromEntries(['NcButton', 'NcCheckboxRadioSwitch', 'NcDateTimePickerNative', 'NcLoadingIcon', 'NcModal', 'NcNoteCard', 'NcSelect', 'NcTextField'].map((name) => [name, { name }])))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showInfo: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

const { default: Wizard } = await import('../../src/dialogs/BlastWizardDialog.vue')
const t = (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_all, key) => vars[key] ?? `{${key}}`)
const source = readFileSync(resolve(__dirname, '../../src/dialogs/BlastWizardDialog.vue'), 'utf8')

describe('the new-newsletter wizard as drawn', () => {
	it('is the board dialog at the wizard width, its step over the title', () => {
		expect(source).toMatch(/<CnDialog[\s\S]*?width="wizard"/)
		expect(source).toContain("'New newsletter, step {step} of {total}'")
		expect(source).not.toContain('<NcDialog')
	})

	it('says under the title which step this is and what it asks', () => {
		const steps = [{ label: 'Basics' }, { label: 'Audience' }, { label: 'Content' }, { label: 'Delivery' }, { label: 'A/B test' }, { label: 'Review' }]
		const subtitle = (currentStep) => Wizard.computed.stepSubtitle.call({ t, steps, currentStep, isLastStep: currentStep === steps.length - 1 })
		expect(subtitle(0)).toBe('Step 1 of 6: Basics')
		expect(subtitle(5)).toBe('Step 6 of 6: check everything before the blast is created')
	})
})
