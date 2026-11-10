/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The values a page host hands a detail section through the injected
 * `cnSectionContext`: a ref (its `.value`) or a plain object.
 */

/**
 * @param {unknown} ctx The injected section context.
 * @return {object|null|undefined} Its values, unwrapped when it is a ref.
 * @spec exclude id-resolution plumbing shared with every body section, no behaviour of its own.
 */
export function sectionValues(ctx) {
	return ctx && typeof ctx === 'object' && 'value' in ctx ? ctx.value : ctx
}
