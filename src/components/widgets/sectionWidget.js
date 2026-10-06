/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Turn a self-fetching body section into a detail-page grid widget.
 *
 * A body section (`config.bodyWidgets`, kind 'section') renders below the
 * grid, in a tail the reader has to scroll to. A grid widget sits in the
 * layout and can be a tab of a `tabs` widget. The detail page hands every
 * custom widget type the same props: `objectId`, `objectData` and the
 * widget's `content`. This factory maps those onto the props the wrapped
 * section already takes, so the section itself does not change.
 *
 * @spec openspec/changes/detail-pages-read-at-a-glance/specs/client-management/spec.md
 */

import { h } from 'vue'

/**
 * Build a grid widget around a section component.
 *
 * @param {string} name The widget component name.
 * @param {object} section The section component to render.
 * @param {(ctx: {objectId: string, objectData: object, content: object}) => object} mapProps
 *   The section's props from the page context.
 * @return {object} A Vue component.
 *
 * @spec openspec/changes/detail-pages-read-at-a-glance/specs/client-management/spec.md
 */
export function sectionWidget(name, section, mapProps) {
	return {
		name,
		// The host also passes register, schema, store and the content keys;
		// none of them belong on the section's root element.
		inheritAttrs: false,
		props: {
			objectId: { type: [String, Number], default: '' },
			objectData: { type: Object, default: null },
			content: { type: Object, default: () => ({}) },
		},
		render() {
			const objectId = String(this.objectId || this.objectData?.id || '')
			if (!objectId) {
				return null
			}
			return h(
				section,
				mapProps({
					objectId,
					objectData: this.objectData || {},
					content: this.content || {},
				}),
			)
		},
	}
}
