// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Client for pipelinq's quote endpoints (products-quote-from-lead).
 *
 * The browser talks only to pipelinq. pipelinq reads the lead as the user,
 * filinq renders the PDF and files a copy in the user's Files, pipelinq mails
 * it and logs the contact moment.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Who a quote for this lead would go to.
 *
 * @param {string} leadId The lead.
 * @return {Promise<?{email: string, name: string, source: string}>} The recipient, or null without an email address.
 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
 */
export async function fetchQuoteRecipient(leadId) {
	const { data } = await axios.get(
		generateUrl(`/apps/pipelinq/api/leads/${encodeURIComponent(leadId)}/quotes/recipient`),
	)
	return data?.recipient || null
}

/**
 * Send a quote from the lead's lines.
 *
 * @param {string} leadId The lead.
 * @param {{templateId: string, validUntil?: string}} input The template and the last day the quote holds (Y-m-d).
 * @return {Promise<{number: string, validUntil: string, recipient: object, fileId: ?number, path: ?string, contactMomentId: ?string}>}
 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
 */
export async function sendQuote(leadId, input) {
	const body = { templateId: input.templateId }
	if (input.validUntil) body.validUntil = input.validUntil
	const { data } = await axios.post(
		generateUrl(`/apps/pipelinq/api/leads/${encodeURIComponent(leadId)}/quotes`),
		body,
	)
	return data
}

/**
 * A date as Y-m-d in local time, the form the endpoint reads.
 *
 * @param {Date} date The date.
 * @return {string} The date, e.g. "2026-12-17".
 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
 */
export function isoDate(date) {
	const month = String(date.getMonth() + 1).padStart(2, '0')
	const day = String(date.getDate()).padStart(2, '0')
	return `${date.getFullYear()}-${month}-${day}`
}

/**
 * The default validity: thirty days from the given day.
 *
 * @param {Date} [today] Today.
 * @return {Date} The last day the quote holds.
 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
 */
export function defaultValidUntil(today = new Date()) {
	const date = new Date(today.getFullYear(), today.getMonth(), today.getDate())
	date.setDate(date.getDate() + 30)
	return date
}
