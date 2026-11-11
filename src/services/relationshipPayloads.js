/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The relationship objects a save writes: a new relationship goes in as a
 * pair (it and its inverse), an edit rewrites the relationship and, when it
 * has one, its inverse. Also the name of the entity at the other end.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-15
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * The inverse type and category of a relationship type; a type the list
 * does not know is its own inverse and has no category.
 *
 * @param {Array<{value: string, inverse: string, category: string}>} types The known relationship types.
 * @param {string} type The chosen type.
 * @return {{inverseType: string, category: string}} The kind.
 */
export function relationshipKind(types, type) {
	const typeObj = types.find((option) => option.value === type)
	return {
		inverseType: typeObj ? typeObj.inverse : type,
		category: typeObj ? typeObj.category : '',
	}
}

/**
 * The objects an edit writes: the relationship, then its inverse when it has one.
 *
 * @param {{id: string, _inverseId: (string|undefined)}} editing The relationship being edited.
 * @param {{form: object, kind: {inverseType: string, category: string}}} draft The form and the type's kind.
 * @return {Array<object>} The objects to save, in order.
 */
export function editedRelationshipPair(editing, draft) {
	const pair = [{ id: editing.id, ...forwardTypes(draft), ...relationshipDetails(draft.form) }]
	if (editing._inverseId) {
		pair.push({ id: editing._inverseId, ...inverseTypes(draft), ...relationshipDetails(draft.form) })
	}
	return pair
}

/**
 * The objects a new relationship writes: from this entity to the other, then the inverse.
 *
 * @param {{from: {id: string, type: string}, to: {id: string, type: string}}} link The two entities.
 * @param {{form: object, kind: {inverseType: string, category: string}}} draft The form and the type's kind.
 * @return {Array<object>} The objects to save, in order.
 */
export function newRelationshipPair(link, draft) {
	return [
		{ ...relationshipEnds(link.from, link.to), ...forwardTypes(draft), ...relationshipDetails(draft.form) },
		{ ...relationshipEnds(link.to, link.from), ...inverseTypes(draft), ...relationshipDetails(draft.form) },
	]
}

/**
 * The display name of a linked entity: its name, its id when it has none,
 * "[Deleted]" when it is gone, and its id when it cannot be read.
 *
 * @param {object} objectStore The object store.
 * @param {{id: string, type: string}} ref The entity.
 * @return {Promise<string>} The name.
 */
export async function linkedEntityName(objectStore, ref) {
	try {
		const entity = await objectStore.fetchObject(ref.type, ref.id)
		if (entity) {
			return entity.name || ref.id
		}
		return t('pipelinq', '[Deleted]')
	} catch {
		return ref.id
	}
}

/**
 * @param {{id: string, type: string}} from The entity the relationship starts at.
 * @param {{id: string, type: string}} to The entity it points to.
 * @return {object} The end fields.
 */
function relationshipEnds(from, to) {
	return {
		fromContact: from.id,
		toContact: to.id,
		fromType: from.type,
		toType: to.type,
	}
}

/**
 * @param {{form: object, kind: object}} draft The form and the type's kind.
 * @return {object} The type fields of the relationship as chosen.
 */
function forwardTypes(draft) {
	return {
		type: draft.form.type,
		inverseType: draft.kind.inverseType,
		category: draft.kind.category,
	}
}

/**
 * @param {{form: object, kind: object}} draft The form and the type's kind.
 * @return {object} The type fields of its inverse.
 */
function inverseTypes(draft) {
	return {
		type: draft.kind.inverseType,
		inverseType: draft.form.type,
		category: draft.kind.category,
	}
}

/**
 * @param {object} form The relationship form.
 * @return {object} The notes, the period (empty as null) and the strength.
 */
function relationshipDetails(form) {
	return {
		notes: form.notes,
		startDate: form.startDate || null,
		endDate: form.endDate || null,
		strength: form.strength,
	}
}
