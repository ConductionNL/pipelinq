/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The call wrap-up dialog (board PqGesprekAfronden): the outcome choices read
 * in Dutch, the subject is not marked invalid before it is touched, and the
 * facts list keeps its labels beside their values.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: { name: 'CnDialog', render: () => null } }))
vi.mock('@nextcloud/vue', () => {
	const shell = (name) => ({ name, render: () => null })
	return {
		NcButton: shell('NcButton'),
		NcCheckboxRadioSwitch: shell('NcCheckboxRadioSwitch'),
		NcSelect: shell('NcSelect'),
		NcTextArea: shell('NcTextArea'),
		NcTextField: shell('NcTextField'),
	}
})

const root = resolve(__dirname, '../..')
const source = readFileSync(resolve(root, 'src/modals/CtiDispositionModal.vue'), 'utf8')
const nl = JSON.parse(readFileSync(resolve(root, 'l10n/nl.json'), 'utf8')).translations

describe('CtiDispositionModal as board PqGesprekAfronden', () => {
	it('names the six outcomes with the board words in Dutch', async () => {
		const { OUTCOMES } = await import('../../src/modals/CtiDispositionModal.vue')
		expect(OUTCOMES.map((o) => o.value)).toEqual(['resolved', 'callback', 'escalated', 'wrong-number', 'no-answer', 'abandoned'])
		expect(OUTCOMES.map((o) => nl[o.label])).toEqual(['Opgelost', 'Terugbellen', 'Geëscaleerd', 'Verkeerd nummer', 'Geen gehoor', 'Opgehangen'])
	})

	it('translates each outcome through a literal t() call, so the catalogue finds it', async () => {
		const { OUTCOMES } = await import('../../src/modals/CtiDispositionModal.vue')
		for (const o of OUTCOMES) {
			expect(source).toContain(`t('pipelinq', '${o.label}')`)
		}
	})

	it('does not mark the subject invalid before it is touched', () => {
		const field = source.match(/<NcTextField[\s\S]*?id="cti-disposition-subject"[\s\S]*?\/>/)[0]
		expect(field).not.toMatch(/^\s*required\s*$/m)
		expect(field).toContain('aria-required="true"')
		expect(field).toContain(':error="subjectTouched && !form.subject.trim()"')
	})

	it('undoes the core dt/dd box on the facts list', () => {
		const dt = source.match(/\.cti-disposition__facts dt \{[\s\S]*?\}/)[0]
		expect(dt).toContain('width: auto')
		expect(dt).toContain('padding: 0')
		expect(dt).toContain('text-align: start')
		const dd = source.match(/\.cti-disposition__facts dd \{[\s\S]*?\}/)[0]
		expect(dd).toContain('padding: 0')
	})
})
