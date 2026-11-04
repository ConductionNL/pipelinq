// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// The block list of an email template in Blocks mode (marketing-block-editor).
// Pure functions, so the editor's rules (the footer is always there and last,
// moves stay inside the list) are tested without mounting anything. The
// server renders the HTML (lib/Service/Marketing/MailBlockRenderer.php); these
// only shape the list it receives.
//
// @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001

/** The block types a marketer can add; the footer is always present. */
export const ADDABLE_TYPES = [
	'heading',
	'text',
	'image',
	'button',
	'divider',
	'spacer',
	'articles',
]

/** The footer tokens the compliance check needs. */
export const FOOTER_TOKENS = ['{{physical_address}}', '{{unsubscribe_link}}']

let counter = 0

/**
 * A new block of a type, with empty content.
 *
 * @param {string} type The block type.
 * @return {{id: string, type: string, props: object}} The block.
 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
 */
export function newBlock(type) {
	counter += 1
	const id = `${type}-${Date.now().toString(36)}-${counter}`
	const props =
		{
			heading: { text: '', level: 2 },
			text: { markdown: '' },
			image: { src: '', alt: '', href: '' },
			button: { label: '', href: '', color: '' },
			divider: {},
			spacer: { height: 24 },
			articles: {},
			footer: { text: '' },
		}[type] || {}
	return { id, type, props: { ...props } }
}

/**
 * The blocks a new email template starts with: a heading, a text block and the footer.
 *
 * @return {Array<object>} The blocks.
 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
 */
export function starterBlocks() {
	return [newBlock('heading'), newBlock('text'), newBlock('footer')]
}

/**
 * The list with exactly one footer, last.
 *
 * @param {Array<object>} blocks The blocks.
 * @return {Array<object>} The blocks, footer last.
 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
 */
export function withFooterLast(blocks) {
	const list = Array.isArray(blocks)
		? blocks.filter((b) => b && typeof b === 'object')
		: []
	const footer = list.find((b) => b.type === 'footer') || newBlock('footer')
	return [...list.filter((b) => b.type !== 'footer'), footer]
}
