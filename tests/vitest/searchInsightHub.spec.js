/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The search insight page as board PqZoekinzicht draws it: one page with the
 * search queries, the keyword proposals, the competitor watches and the
 * connection audit as titled sections.
 *
 * @spec openspec/specs/marketing/spec.md
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

describe('the sections as board PqZoekinzicht draws them (pl3 10, 11, 13-24)', () => {
	it('draws the table heads on white with the sorted label in the muted ink', () => {
		expect(hub).toMatch(/--cn-board-table-head-bg: var\(--color-main-background\)/)
		expect(hub).toMatch(/th\[aria-sort\] \.cn-table-header__sort\)[\s\S]*?color: inherit/)
	})

	it('draws the competitor watches as one bordered table without a sub-heading when embedded', () => {
		expect(competitors).toMatch(/<h3 v-if="!embedded">\{\{ t\('pipelinq', 'Watches'\) \}\}<\/h3>/)
		expect(competitors).toMatch(/\.competitors--embedded[\s\S]*?\.competitors__scroll[\s\S]*?border: 1px solid var\(--color-border\)/)
	})

	it('shows the outcome as a status pill and Run now as a secondary button', () => {
		expect(competitors).toMatch(/<CnStatusBadge[\s\S]*?:variant="outcomeVariant\(watch\)"[\s\S]*?:label="outcomeLabel\(watch\)"/)
		expect(competitors).toMatch(/variant="secondary"[\s\S]*?'Run now'/)
		expect(competitors).not.toMatch(/variant="tertiary"[\s\S]{0,80}'Run now'/)
	})

	it('names only the kind a watch follows when embedded, and keeps the target on the full page', () => {
		expect(competitors).toMatch(/<span v-if="!embedded" class="competitors__target"/)
	})

	it('sums up what changed in one line when embedded', () => {
		expect(competitors).toMatch(/v-if="embedded"[\s\S]*?class="competitors__changed"/)
		expect(nl['{competitor} published "{title}", relevance {score}']).toBe('{competitor} publiceerde "{title}", relevantie {score}')
	})

	it('drops the per-page table of a two-pages finding when embedded', () => {
		expect(keywords).toMatch(/<div v-if="!embedded" class="keyword-intel__scroll">\s*<table class="keyword-intel__table">\s*<thead>\s*<tr>\s*<th scope="col">\s*\{\{ t\('pipelinq', 'Page'\) \}\}/)
	})

	it('draws Check again as a tonal pill', () => {
		expect(hub).toMatch(/class="cn-button--tonal"[\s\S]*?'Check again'/)
	})
})
