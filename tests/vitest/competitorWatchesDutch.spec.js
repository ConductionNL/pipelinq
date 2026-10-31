// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The competitors page speaks Dutch: its own texts, the watch kind and
 * schedule by their label, and the last run and seen-at moments as a date in
 * the reader's locale. Stored values stay as they are; an unknown value shows
 * as stored.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import {
	enumLabel,
	WATCH_KIND_LABELS,
	WATCH_SCHEDULE_LABELS,
} from '../../src/utils/enumLabels.js'

const ROOT = path.resolve(__dirname, '../..')
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

const EN = readJson('l10n', 'en.json').translations
const NL = readJson('l10n', 'nl.json').translations
const watchSchemas = readJson(
	'lib',
	'Settings',
	'register.d',
	'97-marketing-search-intelligence.json',
).components.schemas

const axiosGet = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: { get: axiosGet, post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

/**
 * A stub that renders its text props and default slot.
 *
 * @param {string} name The component name.
 * @param {string} tag The element to render.
 * @return {object} The stub.
 */
function stub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['name', 'description'],
		render() {
			return h(tag, { 'data-stub': name }, [
				this.name,
				this.description,
				this.$slots.default?.(),
			])
		},
	}
}

vi.mock('@conduction/nextcloud-vue', () => ({
	CnStatusBadge: {
		name: 'CnStatusBadge',
		props: ['label', 'variant'],
		render() {
			return h('span', { 'data-stub': 'CnStatusBadge', 'data-variant': this.variant }, this.label)
		},
	},
}))

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcEmptyContent: stub('NcEmptyContent'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard'),
}))

/**
 * Translate through the Dutch catalogue, as the browser does for a Dutch reader.
 *
 * @param {string} app The app id.
 * @param {string} text The English source string.
 * @param {object} vars Placeholder values.
 * @return {string} The Dutch text, or the source when it has none.
 */
function translate(app, text, vars = {}) {
	return String(NL[text] || text).replace(
		/\{(\w+)\}/g,
		(whole, key) => vars[key] ?? whole,
	)
}
globalThis.t = translate

const { default: CompetitorWatches } =
	await import('../../src/views/marketing/CompetitorWatches.vue')

const SOURCE = fs.readFileSync(
	path.join(ROOT, 'src', 'views', 'marketing', 'CompetitorWatches.vue'),
	'utf8',
)

describe('competitor watch labels', () => {
	it('match the register labels, value for value', () => {
		const watch = watchSchemas.competitorWatch.properties
		expect(WATCH_KIND_LABELS).toEqual(watch.kind['x-enum-labels'])
		expect(Object.keys(WATCH_KIND_LABELS).sort()).toEqual(
			[...watch.kind.enum].sort(),
		)
		expect(WATCH_KIND_LABELS).toEqual(
			watchSchemas.watchEvent.properties.kind['x-enum-labels'],
		)
		expect(WATCH_SCHEDULE_LABELS).toEqual(watch.schedule['x-enum-labels'])
		expect(Object.keys(WATCH_SCHEDULE_LABELS).sort()).toEqual(
			[...watch.schedule.enum].sort(),
		)
	})

	it('has a Dutch text for every string the page prints', () => {
		const literals = [
			...SOURCE.matchAll(/t\(\s*'pipelinq',\s*'((?:[^'\\]|\\.)*)'/g),
		].map((m) => m[1].replace(/\\'/g, "'"))
		// The control: an empty list would make the loop below vacuous.
		expect(literals.length).toBeGreaterThan(10)
		const labels = [
			...Object.values(WATCH_KIND_LABELS),
			...Object.values(WATCH_SCHEDULE_LABELS),
			'No egress source is configured for competitor watches, so nothing is read.',
		]
		for (const text of [...literals, ...labels]) {
			expect(EN[text], text).toBe(text)
			expect(NL[text], text).toBeTruthy()
		}
	})

	it('shows an unknown value as stored', () => {
		expect(enumLabel(WATCH_KIND_LABELS, 'mastodon')).toBe('mastodon')
		expect(enumLabel(WATCH_SCHEDULE_LABELS, 'monthly')).toBe('monthly')
	})
})

describe('the competitors page in Dutch', () => {
	it('labels the values and formats the moments', async () => {
		axiosGet.mockResolvedValue({
			data: {
				configured: false,
				reason: 'No egress source is configured for competitor watches, so nothing is read.',
				competitors: [{ id: 'c1', name: 'Voorbeeld B.V.' }],
				watches: [
					{
						id: 'w1',
						competitorId: 'c1',
						kind: 'rss',
						target: 'https://example.org/feed.xml',
						schedule: 'daily',
						lastRunAt: '2026-03-01T09:00:00+00:00',
						lastOutcome: 'ok',
					},
					{
						id: 'w2',
						competitorId: 'c1',
						kind: 'mastodon',
						target: 'x',
						schedule: 'monthly',
					},
				],
				events: [
					{
						id: 'e1',
						competitorId: 'c1',
						kind: 'sitemap',
						url: 'https://example.org/a',
						title: 'A',
						seenAt: '2026-03-02T09:00:00+00:00',
					},
				],
			},
		})
		const wrapper = mount(CompetitorWatches, {
			global: { mocks: { t: translate } },
		})
		await flushPromises()

		const text = wrapper.text()
		expect(text).toContain('Er is geen uitgaande bron ingesteld')
		expect(text).toContain('Wat we volgen')
		expect(text).not.toContain('Run now')

		const rows = wrapper
			.findAll('tbody tr')
			.map((row) =>
				row.findAll('td').map((td) => td.text().replace(/\s+/g, ' ')),
			)
		expect(rows[0][1]).toBe('Feedhttps://example.org/feed.xml')
		expect(rows[0][2]).toBe('Elke dag')
		expect(rows[0][3]).not.toContain('2026-03-01T')
		expect(rows[0][3]).toMatch(/2026/)
		expect(rows[0][5]).toBe('Nu uitvoeren')
		expect(rows[1][1]).toBe('mastodonx')
		expect(rows[1][2]).toBe('monthly')
		expect(rows[1][3]).toBe('Nooit')
		expect(rows[1][4]).toBe('Nog niet uitgevoerd')

		const meta = wrapper
			.get('.competitors__event-meta')
			.findAll('span')
			.map((s) => s.text())
		expect(meta[1]).toBe('Sitemap')
		expect(meta[2]).not.toContain('T09:00')
		expect(meta[2]).toMatch(/2026/)
		expect(wrapper.get('.competitors__relevance').text()).toBe('Geen score')
	})
})

describe('the competitors section of the search insight page', () => {
	it('pills the outcome, names only the kind and sums up what changed in one line', async () => {
		axiosGet.mockResolvedValue({
			data: {
				configured: true,
				competitors: [{ id: 'c1', name: 'Gemeente Dordrecht' }],
				watches: [
					{ id: 'w1', competitorId: 'c1', kind: 'rss', target: 'https://example.org/feed.xml', schedule: 'daily', lastOutcome: 'ok' },
					{ id: 'w2', competitorId: 'c1', kind: 'sitemap', target: 'https://example.org/s.xml', schedule: 'weekly', lastOutcome: 'unavailable' },
				],
				events: [
					{ id: 'e1', competitorId: 'c1', kind: 'rss', url: 'https://example.org/a', title: 'Gratis compostbak voor inwoners', relevanceScore: 82 },
				],
			},
		})
		const wrapper = mount(CompetitorWatches, {
			props: { embedded: true },
			global: { mocks: { t: translate } },
		})
		await flushPromises()

		const badges = wrapper.findAll('[data-stub="CnStatusBadge"]')
		expect(badges.map((b) => b.attributes('data-variant'))).toEqual(['success', 'error'])
		expect(badges[0].text()).toBe('Gelezen')
		const firstRow = wrapper.findAll('tbody tr')[0].findAll('td').map((td) => td.text())
		expect(firstRow[1]).toBe('Feed')
		expect(wrapper.find('h3').exists()).toBe(false)
		expect(wrapper.find('[data-testid="competitors-events"]').exists()).toBe(false)
		const changed = wrapper.get('[data-testid="competitors-changed"]').findAll('span').map((span) => span.text())
		expect(changed).toEqual([NL['What changed'], 'Gemeente Dordrecht publiceerde "Gratis compostbak voor inwoners", relevantie 82'])
	})
})
