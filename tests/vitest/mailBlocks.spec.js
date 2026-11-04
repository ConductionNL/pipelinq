// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The block list rules of the email template editor (marketing-block-editor).
 *
 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
 */

import { describe, expect, it } from 'vitest'
import { starterBlocks, withFooterLast } from '../../src/services/mailBlocks.js'

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
