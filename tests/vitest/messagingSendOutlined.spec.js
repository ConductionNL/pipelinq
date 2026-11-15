/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * PqKlant and PqOrganisatie draw "Bericht versturen" in the Berichten card as
 * an outlined button, not a primary one: the card's only primary action is
 * the contact moment form's Save beside it.
 *
 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.2
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = readFileSync(resolve(__dirname, '../../src/views/messaging/MessagingConversationSection.vue'), 'utf8')
const head = source.slice(source.indexOf('<header class="messaging-conversation__head">'), source.indexOf('</header>'))

describe('the messages card header', () => {
	it('draws Send message as a secondary button', () => {
		expect(head).toContain("{{ t('pipelinq', 'Send message') }}")
		expect(head).toMatch(/<NcButton\s+variant="secondary"/)
	})
})
