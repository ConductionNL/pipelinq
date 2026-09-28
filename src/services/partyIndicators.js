// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Party indicators: the warnings a handler sees on a client or contact.
 *
 * Reads the live indicators through the pipelinq party leaf (which applies the
 * validity period and the vocabulary), records an acknowledgement through the
 * leaf, and adds a new indicator value as an OpenRegister object. Pure helpers
 * (ordering, acknowledgement state, the value payload) are exported for tests.
 *
 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/contactmomenten/spec.md#requirement-the-contact-moment-panel-shall-show-the-partys-indicators-req-cmi-001
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const SEVERITY_RANK = { critical: 0, warning: 1, info: 2 }

/**
 * Order indicators loudest first: critical, then warning, then info, and
 * within one severity by label.
 *
 * @param {Array<object>} indicators The resolved indicators.
 * @return {Array<object>} A new, sorted array.
 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/contactmomenten/spec.md#requirement-the-contact-moment-panel-shall-show-the-partys-indicators-req-cmi-001
 */
export function sortBySeverity(indicators) {
	const rank = (i) => SEVERITY_RANK[i?.severity] ?? SEVERITY_RANK.warning
	return [...(indicators || [])].sort(
		(a, b) =>
			rank(a) - rank(b)
			|| String(a?.label || '').localeCompare(String(b?.label || '')),
	)
}

/**
 * Whether a handler still has to confirm they have seen this indicator.
 *
 * @param {object} indicator A resolved indicator.
 * @return {boolean} True when an acknowledgement is required and missing.
 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/contactmomenten/spec.md#requirement-the-contact-moment-panel-shall-show-the-partys-indicators-req-cmi-001
 */
export function needsAcknowledgement(indicator) {
	return indicator?.requiresAcknowledgement === true && !indicator?.acknowledgedAt
}

/**
 * Build the partyIndicatorValue object a new warning is stored as.
 *
 * @param {string} partyId The client or contact uuid.
 * @param {object} form The form: { indicator, validFrom, validUntil, source }.
 * @return {object} The object payload.
 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md
 */
export function buildIndicatorValue(partyId, form) {
	const value = {
		party: partyId,
		indicator: String(form?.indicator || ''),
		validFrom: form?.validFrom || new Date().toISOString().slice(0, 10),
	}
	if (form?.validUntil) {
		value.validUntil = form.validUntil
	}
	if (form?.source) {
		value.source = String(form.source)
	}
	return value
}

/**
 * The live indicators on a party, loudest first.
 *
 * @param {string} partyId The client or contact uuid.
 * @return {Promise<Array<object>>} The indicators.
 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-a-consuming-app-shall-read-party-fields-and-indicators-through-a-leaf-req-pfi-007
 */
export async function fetchPartyIndicators(partyId) {
	const response = await axios.get(
		generateUrl('/apps/pipelinq/api/leaves/party/{partyId}', { partyId }),
	)
	return sortBySeverity(response?.data?.indicators || [])
}

/**
 * Record that the current user has seen an indicator.
 *
 * @param {string} valueId The indicator value uuid.
 * @return {Promise<object>} The updated value.
 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/contactmomenten/spec.md#requirement-the-contact-moment-panel-shall-show-the-partys-indicators-req-cmi-001
 */
export async function acknowledgeIndicator(valueId) {
	const response = await axios.post(
		generateUrl(
			'/apps/pipelinq/api/party-indicator-values/{valueId}/acknowledge',
			{ valueId },
		),
	)
	return response?.data?.indicatorValue || {}
}

/**
 * The indicators a handler may set: the active entries of the vocabulary.
 *
 * @return {Promise<Array<object>>} The declared indicators.
 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md
 */
export async function fetchIndicatorVocabulary() {
	const response = await axios.get(
		generateUrl(
			'/apps/openregister/api/objects/pipelinq/partyIndicator?_limit=200',
		),
	)
	const rows = response?.data?.results || []
	return rows.filter((row) => row?.code && row?.active !== false)
}

/**
 * Store a new indicator value on a party.
 *
 * @param {string} partyId The client or contact uuid.
 * @param {object} form The form: { indicator, validFrom, validUntil, source }.
 * @return {Promise<object>} The saved object.
 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md
 */
export async function addIndicatorValue(partyId, form) {
	const response = await axios.post(
		generateUrl('/apps/openregister/api/objects/pipelinq/partyIndicatorValue'),
		buildIndicatorValue(partyId, form),
	)
	return response?.data || {}
}
