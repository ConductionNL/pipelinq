// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The block list rules of the email template editor (marketing-block-editor).
 *
 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
 */

import { describe, expect, it } from 'vitest'
import {
	FOOTER_TOKENS,
	mailBlockTypes,
	starterBlocks,
	withFooterLast,
} from '../../src/services/mailBlocks.js'

const types = (blocks) => blocks.map((b) => b.type)

describe('mail blocks', () => {
	it('starts a new template with a heading, a text block and the footer', () => {
		expect(types(starterBlocks())).toEqual(['heading', 'text', 'footer'])
	})

	it('puts one footer last whatever it is given', () => {
		const footer = { id: 'f', type: 'footer', props: {} }
		expect(
			types(withFooterLast([footer, { id: 'h', type: 'heading', props: {} }])),
		).toEqual(['heading', 'footer'])
		expect(types(withFooterLast(null))).toEqual(['footer'])
	})
})

describe('mail block types for the block editor', () => {
	const typesOf = mailBlockTypes((text) => `nl:${text}`)
	const byType = Object.fromEntries(typesOf.map((type) => [type.type, type]))

	it('offers every block but the footer in the palette, in order, translated', () => {
		expect(typesOf.filter((type) => type.addable !== false).map((type) => type.type)).toEqual([
			'heading', 'text', 'image', 'button', 'divider', 'spacer', 'articles',
		])
		expect(byType.heading.label).toBe('nl:Heading')
	})

	it('ends the footer text with both compliance tokens', () => {
		const text = byType.footer.fields.find((field) => field.key === 'text')
		expect(byType.footer.addable).toBe(false)
		expect(text.type).toBe('textarea')
		expect(text.fixedTokens.map((token) => token.token)).toEqual(FOOTER_TOKENS)
		expect(text.fixedTokens.map((token) => token.label)).toEqual(['nl:Your physical address', 'nl:The unsubscribe link'])
	})

	it('asks for the alternative text of an image', () => {
		const alt = byType.image.fields.find((field) => field.key === 'alt')
		expect(alt.required).toBe(true)
		expect(alt.requiredHint).toBe('nl:Describe the image for people who cannot see it.')
	})

	it('keeps the field keys the server renders', () => {
		expect(byType.button.fields.map((field) => field.key)).toEqual(['label', 'href', 'color'])
		expect(byType.spacer.fields[0]).toMatchObject({ key: 'height', type: 'number', min: 8, max: 96, default: 24 })
		expect(byType.heading.fields[1].options.map((option) => option.value)).toEqual([1, 2])
	})
})
