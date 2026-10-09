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
 */
export function starterBlocks() {
	return [newBlock('heading'), newBlock('text'), newBlock('footer')]
}

/**
 * The list with exactly one footer, last.
 *
 * @param {Array<object>} blocks The blocks.
 * @return {Array<object>} The blocks, footer last.
 */
export function withFooterLast(blocks) {
	const list = Array.isArray(blocks)
		? blocks.filter((b) => b && typeof b === 'object')
		: []
	const footer = list.find((b) => b.type === 'footer') || newBlock('footer')
	return [...list.filter((b) => b.type !== 'footer'), footer]
}

/**
 * Move a block up (-1) or down (+1); the footer and the slot above it stay put.
 *
 * @param {Array<object>} blocks The blocks.
 * @param {number} index The block to move.
 * @param {number} delta -1 or +1.
 * @return {Array<object>} The new list (the same list when the move is not possible).
 */
export function moveBlock(blocks, index, delta) {
	const target = index + delta
	const lastMovable = blocks.length - 2
	if (blocks[index]?.type === 'footer' || target < 0 || target > lastMovable) {
		return blocks
	}
	const next = blocks.slice()
	const [moved] = next.splice(index, 1)
	next.splice(target, 0, moved)
	return next
}

/**
 * Remove a block; the footer cannot be removed.
 *
 * @param {Array<object>} blocks The blocks.
 * @param {number} index The block to remove.
 * @return {Array<object>} The new list.
 */
export function removeBlock(blocks, index) {
	if (blocks[index]?.type === 'footer') {
		return blocks
	}
	return blocks.filter((_b, i) => i !== index)
}

/**
 * Insert a new block of a type just above the footer.
 *
 * @param {Array<object>} blocks The blocks.
 * @param {string} type The block type.
 * @return {Array<object>} The new list.
 */
export function addBlock(blocks, type) {
	const list = withFooterLast(blocks)
	list.splice(list.length - 1, 0, newBlock(type))
	return list
}
