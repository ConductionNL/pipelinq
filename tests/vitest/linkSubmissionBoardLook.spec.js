/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The link-to-a-client dialog as board PqAanvraagOmzetten: 720 wide, the
 * submission as a grey card with its facts in columns (label over value,
 * the message across the card, the page without its scheme), and the
 * client, contact person and lead choices as bordered sections.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { name: 'CnDialog', render: () => null },
	CnPartyPicker: { name: 'CnPartyPicker', render: () => null },
}))
vi.mock('@nextcloud/vue', () => {
	const shell = (name) => ({ name, render: () => null })
	return {
		NcButton: shell('NcButton'),
		NcCheckboxRadioSwitch: shell('NcCheckboxRadioSwitch'),
		NcLoadingIcon: shell('NcLoadingIcon'),
		NcNoteCard: shell('NcNoteCard'),
	}
})
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showWarning: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('../../src/services/contactSyncApi.js', () => ({ createWithContact: vi.fn() }))

const source = readFileSync(resolve(__dirname, '../../src/modals/LinkSubmissionToClientModal.vue'), 'utf8')
const style = source.slice(source.indexOf('<style'))

describe('LinkSubmissionToClientModal as board PqAanvraagOmzetten', () => {
	it('opens at the wizard width (720)', () => {
		expect(source).toMatch(/<CnDialog[\s\S]*?width="wizard"/)
	})

	it('shows the page without its scheme', async () => {
		const { readablePage } = await import('../../src/modals/LinkSubmissionToClientModal.vue')
		expect(readablePage('https://zuiddrecht.nl/contact')).toBe('zuiddrecht.nl/contact')
		expect(readablePage('http://zuiddrecht.nl/')).toBe('zuiddrecht.nl')
		expect(readablePage('/contact')).toBe('/contact')
		expect(readablePage('')).toBe('')
	})

	it('draws each fact as a label over its value, the message across the card', () => {
		expect(source).toMatch(/<div[\s\S]*?v-for="row in sentRows"[\s\S]*?class="link-submission__fact"[\s\S]*?link-submission__fact--wide[\s\S]*?<dt>[\s\S]*?<dd>/)
		expect(style).toMatch(/\.link-submission__sent dl \{[^}]*grid-template-columns: repeat\(auto-fit, minmax\(190px, 1fr\)\)/)
		expect(style).toMatch(/\.link-submission__fact--wide \{[^}]*grid-column: 1 \/ -1/)
	})

	it('draws the submission as a grey card with an uppercase eyebrow', () => {
		expect(style).toMatch(/\.link-submission__sent \{[^}]*border-radius: 10px[^}]*background: var\(--color-background-hover/)
		expect(style).toMatch(/\.link-submission__sent h3 \{[^}]*text-transform: uppercase/)
	})

	it('draws each choice as a bordered section', () => {
		expect(style).toMatch(/\.link-submission__choice \{[^}]*border: 1px solid var\(--color-border[^}]*border-radius: 10px/)
	})
})
