/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * PqAanvragen as pl2 measured it: the status reads as a coloured pill, the
 * lenses carry their counts, and a submission is not added by hand.
 *
 * @spec openspec/changes/website-submissions-link-to-client/specs/unify-ticket-supertype/spec.md#requirement-a-colleague-links-a-submission-to-a-client-and-a-contact-in-one-step
 */
import { describe, expect, it } from 'vitest'
import fragment from '../../src/manifest.d/26-website-submissions.json'

const config = fragment.pages.find((p) => p.id === 'WebsiteSubmissions').config

describe('the website submissions page', () => {
	it('draws the status as a pill: new in the primary tone, in progress in warning', () => {
		const status = config.columns.find((c) => c.key === 'status')
		expect(status.widget).toBe('badge')
		expect(status.widgetProps.colorMap).toEqual(expect.objectContaining({ new: 'primary', in_progress: 'warning', rejected: 'default' }))
	})

	it('counts every lens and offers no Add', () => {
		expect(config.quickFilters.every((lens) => lens.showCount === true)).toBe(true)
		expect(config.showAdd).toBe(false)
	})
})
