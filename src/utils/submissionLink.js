// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * Linking a ticket a website form made to a client and a contact person
 * (PqAanvraagOmzetten, decision 179): what the visitor typed, the plan the
 * colleague's choices make, and running it through injected writes so the
 * dialog stays thin and the order is testable.
 *
 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-a-colleague-links-a-submission-to-a-client-and-a-contact-in-one-step
 */

/**
 * A trimmed string, '' for anything else.
 *
 * @param {unknown} value The value.
 * @return {string} The text.
 */
function text(value) {
	return typeof value === 'string' ? value.trim() : ''
}

/**
 * What the visitor typed on the form, as the ticket holds it.
 *
 * @param {object} ticket The ticket.
 * @return {{name: string, email: string, phone: string, organisation: string, form: string, page: string}} The party.
 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-a-colleague-links-a-submission-to-a-client-and-a-contact-in-one-step
 */
export function submittedParty(ticket) {
	return {
		name: text(ticket?.reporterName),
		email: text(ticket?.reporterEmail).toLowerCase(),
		phone: text(ticket?.reporterPhone),
		organisation: text(ticket?.reporterOrganisation),
		form: text(ticket?.source),
		page: text(ticket?.sourceUrl),
	}
}

/**
 * One side of the plan: an existing record by id, or the record to create.
 *
 * @param {{mode: string, id?: string}} choice The colleague's choice.
 * @param {object} create What to create when the choice is new.
 * @param {string} what The side's name, for the refusal.
 * @return {{id: string}|{create: object}} The step.
 */
function step(choice, create, what) {
	if (choice?.mode === 'existing') {
		if (!choice.id) {
			throw new Error(`No existing ${what} chosen.`)
		}
		return { id: String(choice.id) }
	}
	return { create }
}

/**
 * The plan the colleague's choices make.
 *
 * A new client is named after the typed organisation, or after the person
 * when they named none (then a person client). A new contact carries the typed
 * name, e-mail and phone. The lead, when asked, takes the ticket's title,
 * description and form.
 *
 * @param {object} options The choices.
 * @param {object} options.ticket The ticket.
 * @param {{mode: string, id?: string}} options.client New or existing client.
 * @param {{mode: string, id?: string}} options.contact New or existing contact.
 * @param {boolean} [options.lead] Also create a lead.
 * @return {{client: object, contact: object, lead: object|null}} The plan.
 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-a-colleague-links-a-submission-to-a-client-and-a-contact-in-one-step
 */
export function linkPlan({ ticket, client, contact, lead = false }) {
	const party = submittedParty(ticket)
	const personName = party.name || party.email
	const newClient = party.organisation
		? { name: party.organisation, type: 'organization' }
		: { name: personName, type: 'person' }
	if (party.email) {
		newClient.email = party.email
	}
	const newContact = { name: personName }
	if (party.email) {
		newContact.email = party.email
	}
	if (party.phone) {
		newContact.phone = party.phone
	}
	return {
		client: step(client, newClient, 'client'),
		contact: step(contact, newContact, 'contact'),
		lead: lead
			? { title: text(ticket?.title) || personName, description: text(ticket?.description), source: party.form || 'website' }
			: null,
	}
}

/**
 * Run a plan: create what is new, link the ticket, then make the lead.
 *
 * The ticket is linked before the lead is made, so a failing lead never leaves
 * the submission unlinked. Without a default pipeline the lead is skipped and
 * the answer says so.
 *
 * @param {object} plan The plan from linkPlan().
 * @param {string} ticketId The ticket's id.
 * @param {object} api The writes.
 * @param {(type: string, data: object) => Promise<object>} api.createParty Creates a client or contact (contact-first).
 * @param {(id: string, data: object) => Promise<void>} api.patchTicket Writes fields on the ticket.
 * @param {() => Promise<string>} api.defaultPipeline The default pipeline's id, '' when there is none.
 * @param {(data: object) => Promise<object>} api.createLead Creates a lead.
 * @return {Promise<{client: string, contact: string, lead: string, leadSkipped?: boolean}>} The ids.
 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-a-colleague-links-a-submission-to-a-client-and-a-contact-in-one-step
 */
export async function runLinkPlan(plan, ticketId, api) {
	const resolveStep = async (type, entry) => {
		if (entry.id) {
			return entry.id
		}
		const created = await api.createParty(type, entry.create)
		const id = created?.id ?? created?.['@self']?.id
		if (!id) {
			throw new Error(`The ${type} could not be created.`)
		}
		return String(id)
	}
	const client = await resolveStep('client', plan.client)
	const contact = await resolveStep('contact', plan.contact)
	await api.patchTicket(ticketId, { client, contact })

	if (!plan.lead) {
		return { client, contact, lead: '' }
	}
	const pipeline = await api.defaultPipeline()
	if (!pipeline) {
		return { client, contact, lead: '', leadSkipped: true }
	}
	const lead = await api.createLead({ ...plan.lead, client, contact, pipeline })
	return { client, contact, lead: String(lead?.id ?? lead?.['@self']?.id ?? '') }
}
