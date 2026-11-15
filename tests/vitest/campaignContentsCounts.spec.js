/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * PqCampagne's "In deze campagne": newsletters, social posts and leads are
 * counted per campaign and named in the line ("2 nieuwsbrieven").
 *
 * @spec openspec/changes/campaign-contents-counts/specs/marketing-campaigns/spec.md#requirement-a-campaign-names-how-much-it-holds
 */
import { describe, expect, it } from 'vitest'
import nl from '../../l10n/nl.json'
import fragment from '../../src/manifest.d/78-marketing-campaigns.json'

const widget = fragment.pages.find((page) => page.id === 'CampaignDetail').config.widgets.find((w) => w.id === 'campaign-contents')

describe('the campaign contents card', () => {
	it('is a menu of counted lines, each scoped to the campaign', () => {
		expect(widget.type).toBe('menu')
		const counted = widget.content.items.filter((item) => item.countFrom)
		expect(counted.map((item) => item.countFrom.schema)).toEqual(['blast', 'socialPost', 'lead'])
		for (const item of counted) {
			expect(item.countFrom.filter).toEqual({ campaignId: '@objectId' })
			expect(nl.translations[item.countLabel.one]).toBeTruthy()
			expect(nl.translations[item.countLabel.other]).toBeTruthy()
		}
	})

	it('shows the article line only while the campaign has page content', () => {
		const article = widget.content.items.find((item) => item.label === '1 article, the content of the page')
		expect(article.visibleIf).toEqual({ 'object.articleBody': { truthy: true } })
	})

	it('reads as the board in Dutch', () => {
		expect(nl.translations['{count} newsletters'].replace('{count}', 2)).toBe('2 nieuwsbrieven')
		expect(nl.translations['1 article, the content of the page']).toBe('1 artikel, de inhoud van de pagina')
	})
})
