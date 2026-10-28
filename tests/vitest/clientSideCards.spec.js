/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client page's side cards Afspraken, Projecten en facturering (a
 * resident), Verkoop, contracten en facturering (an organisation) and
 * Kennisbank, as PqKlant and PqOrganisatie draw them.
 *
 * @spec openspec/changes/client-side-cards-appointments-business-knowledge/specs/client-detail/spec.md#requirement-the-client-page-shows-appointments-business-and-the-knowledge-base-beside-it
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(__dirname, '../..')
const manifest = JSON.parse(readFileSync(resolve(root, 'src/manifest.json'), 'utf8'))
const nl = JSON.parse(readFileSync(resolve(root, 'l10n/nl.json'), 'utf8')).translations
const config = manifest.pages.find((p) => p.id === 'ClientDetail').config
const ids = config.sideColumn.map((card) => (typeof card === 'string' ? card : card.id))
const card = (id) => config.sideColumn.find((entry) => typeof entry === 'object' && entry.id === id)
const person = { field: 'type', op: 'eq', value: 'person' }
const organisation = { field: 'type', op: 'eq', value: 'organization' }

describe('ClientDetail side cards after Relations', () => {
	it('come in the boards\' order: appointments and business, the history, then the knowledge base', () => {
		const from = ids.indexOf('client-relationships')
		expect(ids.slice(from, from + 6)).toEqual(['client-relationships', 'side-appointments', 'side-business-person', 'side-business-organisation', 'side-history', 'side-knowledge'])
	})

	it('lists a resident\'s coming appointments as a linked line with status and handler', () => {
		const appointments = card('side-appointments')
		expect(appointments).toMatchObject({ type: 'record-list', title: 'Appointments', visibleWhen: person })
		expect(nl.Appointments).toBe('Afspraken')
		expect(appointments.content).toMatchObject({
			register: 'pipelinq',
			schema: 'appointmentBooking',
			filter: { customerId: '@objectId' },
			sort: { field: 'startAt', dir: 'asc' },
			title: '{subject}, {startAt|datetime}',
			meta: { parts: ['{status}', 'with {assignee|user}'] },
			rowRoute: 'BookingDetail',
		})
		expect(appointments.content.valueLabels.status.confirmed).toBe('Confirmed')
	})

	it('says what a resident and an organisation do business on, read from the client\'s business endpoint', () => {
		const resident = card('side-business-person')
		const business = card('side-business-organisation')
		expect(resident).toMatchObject({ type: 'facts', title: 'Projects and invoicing', visibleWhen: person })
		expect(business).toMatchObject({ type: 'facts', title: 'Sales, contracts and invoicing', visibleWhen: organisation })
		expect([nl['Projects and invoicing'], nl['Sales, contracts and invoicing']]).toEqual(['Projecten en facturering', 'Verkoop, contracten en facturering'])
		for (const entry of [resident, business]) {
			expect(entry.content.endpointSource).toEqual({ url: '/apps/pipelinq/api/clients/@objectId/business' })
			expect(entry.content.link).toMatchObject({ label: 'Further in Shillinq', href: '{shillinqUrl}', target: '_blank' })
		}
		expect(resident.content.fields.map((f) => f.path)).toEqual(['projectsLine', 'invoicesLine'])
		expect(resident.content.fields[1].joinPrevious).toBe(true)
		expect(business.content.fields.map((f) => f.path)).toEqual(['leadsLine', 'contractsLine', 'projectsLine', 'invoicesLine'])
	})

	it('searches the knowledge base in a side card', () => {
		const knowledge = card('side-knowledge')
		expect(knowledge).toMatchObject({ type: 'kb-search', title: 'Knowledge base' })
		expect(knowledge.visibleWhen).toBeUndefined()
		expect(knowledge.content).toMatchObject({ endpoint: '/apps/openregister/api/integrations/xwiki/search', queryParam: 'q', placeholder: 'Search the knowledge base' })
		expect(nl['Knowledge base']).toBe('Kennisbank')
	})

	it('has the client\'s business endpoint', () => {
		const routes = readFileSync(resolve(root, 'appinfo/routes.php'), 'utf8')
		expect(routes).toContain("['name' => 'clientBusiness#index', 'url' => '/api/clients/{id}/business', 'verb' => 'GET']")
	})
})
