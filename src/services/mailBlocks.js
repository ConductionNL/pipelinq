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

/** The footer tokens the compliance check needs. */
export const FOOTER_TOKENS = ['{{physical_address}}', '{{unsubscribe_link}}']

/**
 * The block types as CnBlockListEditor takes them: label, the fields of the
 * properties panel and the footer's fixed tokens, in the reader's language.
 *
 * @param {(text: string) => string} translate Translates an English source text.
 * @param {string} [buttonColor] The default button colour.
 * @return {Array<object>} The types, in palette order, the footer last.
 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
 */
export function mailBlockTypes(translate, buttonColor = '#00679e') {
	const tr = translate
	return [
		{
			type: 'heading',
			label: tr('Heading'),
			fields: [
				{ key: 'text', label: tr('Heading text') },
				{
					key: 'level',
					label: tr('Size'),
					type: 'select',
					default: 2,
					options: [
						{ value: 1, label: tr('Large') },
						{ value: 2, label: tr('Medium') },
					],
				},
			],
		},
		{
			type: 'text',
			label: tr('Text'),
			fields: [
				{
					key: 'markdown',
					label: tr('Text'),
					type: 'markdown',
					helperText: tr('Bold, italic, links and lists are kept. Anything else is sent as plain text.'),
				},
			],
		},
		{
			type: 'image',
			label: tr('Image'),
			summary: ['alt', 'src'],
			fields: [
				{ key: 'src', label: tr('Image address (https://)'), type: 'url' },
				{
					key: 'alt',
					label: tr('Alternative text'),
					required: true,
					requiredHint: tr('Describe the image for people who cannot see it.'),
				},
				{ key: 'href', label: tr('Link (optional)'), type: 'url' },
			],
		},
		{
			type: 'button',
			label: tr('Button'),
			fields: [
				{ key: 'label', label: tr('Button text') },
				{ key: 'href', label: tr('Link (https://)'), type: 'url' },
				{ key: 'color', label: tr('Colour'), type: 'color', default: buttonColor },
			],
		},
		{ type: 'divider', label: tr('Divider'), hint: tr('This block has nothing to set.') },
		{
			type: 'spacer',
			label: tr('Spacer'),
			fields: [{ key: 'height', label: tr('Height in pixels'), type: 'number', min: 8, max: 96, default: 24 }],
		},
		{ type: 'articles', label: tr('Articles'), hint: tr('The articles picked below appear here.') },
		{
			type: 'footer',
			label: tr('Footer'),
			addable: false,
			fields: [
				{
					key: 'text',
					label: tr('Footer text'),
					type: 'textarea',
					rows: 3,
					fixedTokensHint: tr('The footer always ends with these two, so the mail can be sent:'),
					fixedTokens: [
						{ token: FOOTER_TOKENS[0], label: tr('Your physical address') },
						{ token: FOOTER_TOKENS[1], label: tr('The unsubscribe link') },
					],
				},
			],
		},
	]
}

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
