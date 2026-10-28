/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The search insight page as board PqZoekinzicht draws it: one page with the
 * search queries, the keyword proposals, the competitor watches and the
 * connection audit as titled sections.
 *
 * @spec openspec/changes/search-insight-hub/specs/marketing/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

function read(file) {
	return readFileSync(resolve(__dirname, '../../', file), 'utf8')
}

const hub = read('src/views/marketing/SearchQueries.vue')
const keywords = read('src/views/marketing/KeywordIntelligence.vue')
const competitors = read('src/views/marketing/CompetitorWatches.vue')
const nl = JSON.parse(read('l10n/nl.json')).translations

describe('the search insight page header', () => {
	it('is a CnPageHeader titled Search insight with Download and buildiq', () => {
		expect(hub).toMatch(/<CnPageHeader[\s\S]*?'Search insight'/)
		expect(hub).toMatch(/<template #extra>[\s\S]*?'Download'[\s\S]*?<CnBuildiqEditButton/)
		expect(nl['Search insight']).toBe('Zoekinzicht')
	})

	it('picks the period in a segmented control', () => {
		expect(hub).toMatch(/<CnSegmentedControl/)
		expect(hub).not.toMatch(/<NcSelect/)
	})
})

describe('the four sections', () => {
	it('draws search queries, keywords, competitors and the connection audit in that order', () => {
		const order = ['Search queries', 'Keywords', 'Competitors', 'Connection audit']
			.map((title) => hub.indexOf(`t('pipelinq', '${title}')`))
		expect(order.every((at) => at > 0)).toBe(true)
		expect([...order].sort((a, b) => a - b)).toEqual(order)
	})

	it('embeds the keyword and competitor views without their own headers', () => {
		expect(hub).toMatch(/<KeywordIntelligence\s[^>]*embedded[^>]*:days=/)
		expect(hub).toMatch(/<CompetitorWatches\s[^>]*embedded/)
		expect(keywords).toMatch(/<header v-if="!embedded"/)
		expect(competitors).toMatch(/<header v-if="!embedded"/)
	})

	it('re-runs the connection audit from its section', () => {
		expect(hub).toMatch(/CONNECTION_AUDIT_PATH[\s\S]*?refresh: 'true'/)
		expect(hub).toMatch(/auditVerdict\(row\.weFollowThem\)/)
		expect(hub).toMatch(/'Check again'/)
	})
})
