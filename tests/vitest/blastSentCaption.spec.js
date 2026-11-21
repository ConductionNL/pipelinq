/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The "Sent this month" tile's caption counts the messages handed to the
 * provider this month ("3.912 berichten" on board PqNieuwsbrieven), one
 * short line instead of a sentence that wraps.
 *
 * @spec openspec/specs/marketing-ui/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '..', '..')
const fragment = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.d/75-marketing-blasts.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations

describe('newsletter list: sent this month', () => {
	const blasts = fragment.pages.find((p) => p.id === 'Blasts')
	const tile = blasts.config.statRow.find((s) => s.id === 'blasts-sent-month')

	it('captions the tile with the month\'s message count', () => {
		expect(tile.content.caption).toBe('{count} messages')
		expect(nl['{count} messages']).toBe('{count} berichten')
	})

	it('counts the messages sent this month, over the tile\'s own filter', () => {
		expect(tile.content.captionSource).toEqual({
			register: 'pipelinq',
			schema: 'blast',
			metric: 'sum',
			field: 'totals.sent',
			filter: tile.content.source.filter,
		})
	})
})
