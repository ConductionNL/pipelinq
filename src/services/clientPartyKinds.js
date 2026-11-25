// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The kinds of party a client can be: the party kinds the administrator keeps
 * (pipelinq's party kind registry) that the acceptance `pipelinq:client:kind`
 * names, such as citizen and business. An administrator adds a kind to the
 * vocabulary and to that acceptance; no release of pipelinq is needed.
 *
 * @module services/clientPartyKinds
 * @spec openspec/changes/specify-capability-rows/specs/client-management/spec.md#requirement-a-client-records-what-kind-of-party-it-is
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The record type under which a client's kind is chosen (lib/Repair/SeedPartyKinds.php). */
export const CLIENT_KIND_RECORD_TYPE = 'pipelinq:client:kind'

const SHAPE_OF_TYPE = { person: 'naturalPerson', organization: 'organization' }

/**
 * The offered kinds as options: the kind's id and its label, active ones only.
 *
 * @param {Array<object>} kinds The kinds the registry answered.
 * @return {Array<{id: string, label: string, shapes: string[]}>} The options.
 * @spec openspec/changes/specify-capability-rows/specs/client-management/spec.md#requirement-a-client-records-what-kind-of-party-it-is
 */
export function kindOptions(kinds) {
	const options = []
	for (const kind of (Array.isArray(kinds) ? kinds : [])) {
		const id = kind?.id ?? kind?.uuid ?? kind?.['@self']?.id
		if (!id || kind.active === false) {
			continue
		}
		options.push({
			id: String(id),
			label: String(kind.label || kind.code || id),
			shapes: Array.isArray(kind.identityShapes) ? kind.identityShapes : [],
		})
	}
	return options
}

/**
 * Read the kinds a client is offered; none when the registry cannot be read.
 *
 * @return {Promise<Array<{id: string, label: string, shapes: string[]}>>} The options.
 * @spec openspec/changes/specify-capability-rows/specs/client-management/spec.md#requirement-a-client-records-what-kind-of-party-it-is
 */
export async function clientPartyKinds() {
	try {
		const { data } = await axios.get(generateUrl('/apps/pipelinq/api/party-kinds'), {
			params: { recordType: CLIENT_KIND_RECORD_TYPE },
		})
		return kindOptions(data?.kinds)
	} catch {
		return []
	}
}

/**
 * The kinds that fit a client of this type: a kind without identity shapes
 * fits any client, and the kind already chosen stays offered so it is never
 * dropped silently when the type changes.
 *
 * @param {Array<{id: string, shapes: string[]}>} options The offered kinds.
 * @param {string|null} type The client's type: person or organization.
 * @param {string|null} chosen The id of the kind already chosen.
 * @return {Array<{id: string, label: string, shapes: string[]}>} The kinds to offer.
 * @spec openspec/changes/specify-capability-rows/specs/client-management/spec.md#requirement-a-client-records-what-kind-of-party-it-is
 */
export function partyKindsForType(options, type, chosen) {
	const shape = SHAPE_OF_TYPE[type] ?? null
	return (Array.isArray(options) ? options : []).filter((option) => option.id === chosen
		|| shape === null
		|| option.shapes.length === 0
		|| option.shapes.includes(shape))
}
