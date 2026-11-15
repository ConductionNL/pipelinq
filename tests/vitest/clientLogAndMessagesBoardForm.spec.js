/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PqKlant / PqOrganisatie "Berichten" and "Contactmoment registreren" in the
 * board look: labels 14/600 above 40px fields, 40px buttons (nc-vue
 * `cn-board-form`), the Berichten title a 17/700 card title. pl6 drew the
 * labels inside 34px fields and the Berichten title 24/600.
 *
 * @spec openspec/changes/client-log-and-messages-board-form/specs/client-detail/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (file) => readFileSync(resolve(__dirname, '../../src', file), 'utf8')
const quickLog = read('components/ContactmomentQuickLog.vue')
const messages = read('views/messaging/MessagingConversationSection.vue')
const styleOf = (source) => source.split('<style')[1]

describe('the client page log form and messages card in the board look', () => {
	it('opts the inline log form into the board form fields', () => {
		expect(quickLog).toContain(':class="{ \'cn-board-form\': inline }"')
	})

	it('draws the log form\'s own labels 14/600 in the main ink, 6px above the field', () => {
		expect(styleOf(quickLog)).toMatch(/\.cn-look-board \.cn-board-form \.form-group label \{[^}]*margin-bottom: 6px;[^}]*color: var\(--color-main-text\);[^}]*font-size: 14px;[^}]*font-weight: 600;/)
	})

	it('opts the messages card into the board form fields', () => {
		expect(messages).toContain('<section class="messaging-conversation cn-board-form">')
	})

	it('draws the Berichten title as a 17/700 card title', () => {
		expect(styleOf(messages)).toMatch(/\.cn-look-board \.messaging-conversation h3 \{[^}]*font-size: 17px;[^}]*font-weight: 700;[^}]*line-height: normal;/)
	})
})
