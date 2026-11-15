/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * PqKlant's Berichten card says a closed WhatsApp session needs a template
 * with a comma, as the board writes it ("Gesloten, sjabloon vereist"): app
 * copy carries no em-dashes.
 *
 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(__dirname, '../..')
const source = readFileSync(resolve(root, 'src/views/messaging/MessagingConversationSection.vue'), 'utf8')
const nl = JSON.parse(readFileSync(resolve(root, 'l10n/nl.json'), 'utf8')).translations

describe('the messages card session line', () => {
	it('words a closed session with a comma, in English and Dutch', () => {
		expect(source).toContain('t(\'pipelinq\', \'Closed, template required\')')
		expect(nl['Closed, template required']).toBe('Gesloten, sjabloon vereist')
	})

	it('has no em-dash in a translated string of the card', () => {
		const strings = [...source.matchAll(/t\('pipelinq', '([^']*)'/g)].map((m) => m[1])
		expect(strings.filter((s) => s.includes('—'))).toEqual([])
	})
})
