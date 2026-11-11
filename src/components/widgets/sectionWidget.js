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

import { CnDetailCard } from '@conduction/nextcloud-vue'
import { computed, h } from 'vue'

/**
 * Build a grid widget around a section component.
 *
 * @param {string} name The widget component name.
 * @param {object} section The section component to render.
 * @param {(ctx: {objectId: string, objectData: object, content: object}) => object} mapProps
 *   The section's props from the page context.
 * @param {{card?: boolean, context?: boolean}} [options] `card` wraps the section in a titled
 *   card (title from `content.title` and the line beside it from `content.subtitle`, both through the
 *   page's translate), for a widget that sits in the grid on
 *   its own rather than inside a tab, which already names it; an instance under a heading of its own (a
 *   `sections` widget) sets `content.card: false` to draw the section bare. `context` provides
 *   `cnSectionContext` (`{ object, objectId }`) as a body section gets it, for a
 *   section that reads the page's record from there.
 * @return {object} A Vue component.
 *
 * @spec openspec/changes/detail-pages-read-at-a-glance/specs/client-management/spec.md
 */
export function sectionWidget(name, section, mapProps, options = {}) {
	return {
		name,
		// The host also passes register, schema, store and the content keys;
		// none of them belong on the section's root element.
		inheritAttrs: false,
		inject: {
			// The page's translate, so a card title declared in the manifest
			// (English source) reads in the user's language.
			cnTranslate: { default: null },
		},
		provide() {
			if (!options.context) {
				return {}
			}
			return {
				cnSectionContext: computed(() => ({
					object: this.objectData,
					objectId: widgetObjectId(this),
				})),
			}
		},
		emits: ['header'],
		props: {
			objectId: { type: [String, Number], default: '' },
			objectData: { type: Object, default: null },
			content: { type: Object, default: () => ({}) },
		},
		methods: {
			/**
			 * @param {string} objectId The page's object id.
			 * @return {object} The section's props, with the onHeader relay.
			 */
			sectionProps(objectId) {
				return {
					...mapProps({
						objectId,
						objectData: this.objectData || {},
						content: this.content || {},
					}),
					// The title row a section reports goes on to the card the detail host draws.
					onHeader: (header) => this.$emit('header', header),
				}
			},

			/**
			 * @return {boolean} Whether the section is drawn without a card of its own.
			 */
			isBare() {
				return !options.card || this.content?.card === false
			},
		},
		render() {
			const objectId = widgetObjectId(this)
			if (!objectId) {
				return null
			}
			const inner = h(section, this.sectionProps(objectId))
			if (this.isBare()) {
				return inner
			}
			return h(
				CnDetailCard,
				cardHeading(this.content, pageTranslate(this.cnTranslate)),
				{ default: () => inner },
			)
		},
	}
}

/**
 * @param {{objectId: (string|number), objectData: ?object}} widget The widget.
 * @return {string} The page's object id, from the prop or the object; '' without one.
 */
function widgetObjectId(widget) {
	return String(widget.objectId || widget.objectData?.id || '')
}

/**
 * @param {?((key: string) => string)} cnTranslate The page's translate, when it provides one.
 * @return {(key: string) => string} The translate to use; the key itself without one.
 */
function pageTranslate(cnTranslate) {
	return typeof cnTranslate === 'function'
		? cnTranslate
		: (key) => key
}

/**
 * @param {?object} content The widget's content.
 * @param {(key: string) => string} translate The page's translate.
 * @return {{title: string, subtitle: string}} The card's title and the line beside it, translated.
 */
function cardHeading(content, translate) {
	const title = content?.title || ''
	const subtitle = content?.subtitle || ''
	return { title: title ? translate(title) : '', subtitle: subtitle ? translate(subtitle) : '' }
}
