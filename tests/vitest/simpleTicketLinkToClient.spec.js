/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * PqAanvraagOmzetten: in the simple menu the ticket page offers "Aan een klant
 * koppelen" while the ticket has no client, as the full profile does.
 *
 * @spec openspec/changes/website-submissions-link-to-client/specs/unify-ticket-supertype/spec.md#requirement-a-colleague-links-a-submission-to-a-client-and-a-contact-in-one-step
 */
import { describe, expect, it } from 'vitest'
import simple from '../../src/menu-layout.simple.json'

const page = simple.pages.find((p) => p.id === 'TicketDetail')

describe('the simple ticket page', () => {
	it('offers Link to a client while the ticket has no client', () => {
		const action = page.config.headerActions.find((a) => a.id === 'link-to-client')
		expect(action).toEqual(expect.objectContaining({
			type: 'open-modal',
			target: 'LinkSubmissionToClientModal',
			props: { ticketId: '@objectId' },
			visibleWhen: { field: 'client', op: 'empty' },
		}))
	})
})
