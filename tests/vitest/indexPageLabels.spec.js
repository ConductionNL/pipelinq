/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The texts of an index page the library prints as written are translated
 * once, in place, before the manifest reaches CnAppRoot.
 *
 * @spec openspec/changes/round6-board-look/specs/board-look/spec.md
 */
import { describe, expect, it } from 'vitest'
import { translateIndexPageLabels } from '../../src/utils/indexPageLabels.js'

const translate = (text) => `[${text}]`

describe('translateIndexPageLabels', () => {
	it('translates the page texts and the chip and button labels of index pages only', () => {
		const manifest = {
			pages: [
				null,
				{ type: 'index' },
				{ type: 'index', config: 'nope' },
				{ type: 'detail', config: { countText: 'Count' } },
				{
					type: 'index',
					config: {
						countText: '{shown} of {total}',
						footerNote: '',
						bulkHint: 7,
						title: 'Untouched',
						quickFilters: [{ label: 'Mine' }, { label: '' }, null, { label: 3 }, {}],
						headerButtons: 'nope',
					},
				},
				{ type: 'index', config: { headerButtons: [{ label: 'New' }], quickFilters: null } },
			],
		}
		const out = translateIndexPageLabels(manifest, translate)
		expect(out).toBe(manifest)
		expect(manifest.pages[3].config.countText).toBe('Count')
		expect(manifest.pages[4].config).toEqual({
			countText: '[{shown} of {total}]',
			footerNote: '',
			bulkHint: 7,
			title: 'Untouched',
			quickFilters: [{ label: '[Mine]' }, { label: '' }, null, { label: 3 }, {}],
			headerButtons: 'nope',
		})
		expect(manifest.pages[5].config.headerButtons).toEqual([{ label: '[New]' }])
	})

	it('passes a manifest without pages through', () => {
		expect(translateIndexPageLabels(null, translate)).toBe(null)
		expect(translateIndexPageLabels({}, translate)).toEqual({})
	})
})
