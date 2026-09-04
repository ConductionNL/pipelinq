// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Curated segment-rule-builder field options for the "contact" and
// "customer" audiences, shared by SegmentForm.vue.
//
// Kept as static lists rather than a live schema-introspection endpoint --
// that is a materially larger surface than a UI-repair change warrants; see
// DEFERRED_QUESTIONS in openspec/changes/marketing-segments-ui-repair/proposal.md.
//
// @spec openspec/changes/marketing-segments-ui-repair/specs/marketing-ui/spec.md#requirement-segment-builder-ui-composes-rule-trees

/**
 * Dotted array-of-object field options shared by both audiences -- `contact`
 * and `client` both carry `emails[]`/`phones[]`/`socialProfiles[]`
 * (contact-channel-details, lib/Settings/register.d/
 * 16-contact-channel-details.json). SegmentService resolves a dotted
 * `arrayProp.subProp` field against the array property's `items.properties`
 * sub-schema and evaluates it as "any element matches" -- see
 * SegmentService::resolveFieldType()/evaluateProjectedLeaf().
 *
 * @type {Array<{value:string,label:string,type:string}>}
 *
 * @spec openspec/changes/contact-channel-details/specs/marketing-segmentation/spec.md#requirement-rule-fields-reach-into-array-of-object-properties
 */
export const CHANNEL_FIELD_OPTIONS = [
	{ value: 'emails.kind', label: 'Email kind', type: 'string' },
	{ value: 'phones.kind', label: 'Phone kind', type: 'string' },
	{ value: 'socialProfiles.network', label: 'Social network', type: 'string' },
]

/**
 * Curated field options for the "contact" audience, grounded in the real
 * `contact` schema properties (lib/Settings/pipelinq_register.json).
 *
 * @type {Array<{value:string,label:string,type:string}>}
 */
export const CONTACT_FIELD_OPTIONS = [
	{ value: 'name', label: 'Name', type: 'string' },
	{ value: 'email', label: 'Email', type: 'string' },
	{ value: 'phone', label: 'Phone', type: 'string' },
	{ value: 'role', label: 'Role', type: 'string' },
	{ value: 'marketingConsent', label: 'Marketing consent', type: 'boolean' },
	{ value: 'doNotContact', label: 'Do not contact', type: 'boolean' },
	...CHANNEL_FIELD_OPTIONS,
]

/**
 * Curated field options for the "customer" audience (the `client` schema).
 *
 * @type {Array<{value:string,label:string,type:string}>}
 */
export const CUSTOMER_FIELD_OPTIONS = [
	{ value: 'name', label: 'Name', type: 'string' },
	{ value: 'type', label: 'Organisation type', type: 'string' },
	{ value: 'email', label: 'Email', type: 'string' },
	{ value: 'phone', label: 'Phone', type: 'string' },
	{ value: 'address', label: 'Address', type: 'string' },
	{ value: 'website', label: 'Website', type: 'string' },
	{ value: 'industry', label: 'Industry', type: 'string' },
	...CHANNEL_FIELD_OPTIONS,
]

/**
 * Resolve the field options for a segment's audience.
 *
 * @param {string} entityType 'contact' or 'customer'.
 * @return {Array<{value:string,label:string,type:string}>} The field options.
 *
 * @spec openspec/changes/contact-channel-details/specs/marketing-segmentation/spec.md#requirement-rule-fields-reach-into-array-of-object-properties
 */
export function fieldOptionsFor(entityType) {
	return entityType === 'customer' ? CUSTOMER_FIELD_OPTIONS : CONTACT_FIELD_OPTIONS
}
