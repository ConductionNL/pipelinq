// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// The fields of a contact capture proposal, and how they fill the Clients and
// Contacts create forms. Pure, so the mapping is tested without a dialog.
//
// @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001

/**
 * The proposed fields in the order the dialog shows them, each with its
 * English label (translated where it is drawn).
 */
export const CAPTURE_FIELDS = [
	{ key: 'name', label: 'Name' },
	{ key: 'organisation', label: 'Organisation' },
	{ key: 'jobTitle', label: 'Job title' },
	{ key: 'email', label: 'Email' },
	{ key: 'phone', label: 'Phone' },
	{ key: 'website', label: 'Website' },
	{ key: 'street', label: 'Street' },
	{ key: 'postalCode', label: 'Postal code' },
	{ key: 'city', label: 'City' },
	{ key: 'kvkNumber', label: 'KvK number' },
]

/**
 * Every proposed field, empty where the proposal has none.
 *
 * @param {object} [fields] The proposal's fields.
 * @return {object} One string per CAPTURE_FIELDS key.
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001
 */
export function captureForm(fields = {}) {
	const form = {}
	for (const { key } of CAPTURE_FIELDS) {
		const value = fields?.[key]
		form[key] = typeof value === 'string' ? value : ''
	}
	return form
}

/**
 * The street, postcode and city as the one address line the client form has.
 *
 * @param {object} form The capture form.
 * @return {string} The address, or ''.
 */
function addressLine(form) {
	const place = [form.postalCode, form.city].map((part) => part.trim()).filter(Boolean).join(' ')
	return [form.street.trim(), place].filter(Boolean).join(', ')
}

/**
 * The create-form values for a page's schema. Only non-empty values are
 * returned, so the form keeps its own defaults for the rest.
 *
 * A client with an organisation is that organisation, and the person's name,
 * job title and KvK number go into its notes, since the client form has no
 * field for them. A contact takes the name, the job title as its role, the
 * email and the phone; the client it belongs to is picked in the form.
 *
 * @param {string} schema 'client' or 'contact'.
 * @param {object} form The capture form.
 * @param {(text: string, vars?: object) => string} translate Translates a note label.
 * @return {object} The values to prefill.
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#scenario-a-sales-rep-pastes-an-email-signature
 */
export function createValues(schema, form, translate = (text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => vars?.[k] ?? m)) {
	const f = captureForm(form)
	const values = {}
	const put = (key, value) => {
		if (typeof value === 'string' && value.trim() !== '') {
			values[key] = value.trim()
		}
	}

	if (schema === 'client') {
		const organisation = f.organisation.trim()
		values.type = organisation ? 'organization' : 'person'
		put('name', organisation || f.name)
		put('email', f.email)
		put('phone', f.phone)
		put('website', f.website)
		put('address', addressLine(f))
		const notes = []
		if (organisation && f.name.trim()) {
			notes.push(translate('Contact person: {name}', { name: f.name.trim() }))
		}
		if (f.jobTitle.trim()) {
			notes.push(translate('Job title: {jobTitle}', { jobTitle: f.jobTitle.trim() }))
		}
		if (f.kvkNumber.trim()) {
			notes.push(translate('KvK number: {kvkNumber}', { kvkNumber: f.kvkNumber.trim() }))
		}
		put('notes', notes.join('\n'))
		return values
	}

	put('name', f.name)
	put('role', f.jobTitle)
	put('email', f.email)
	put('phone', f.phone)
	return values
}
