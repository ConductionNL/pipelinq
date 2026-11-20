// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The win probability on the Leads list, the lead page and the pipeline
 * board: each asks OpenRegister for `_extend[]=calculations`, because
 * `winProbability` is a `materialise: false` calculation, and each shows that
 * number as the win chance, while the lead score keeps its own label.
 *
 * @spec openspec/changes/lead-win-probability-recency/specs/lead-scoring-win-probability/spec.md#requirement-win-probability-is-surfaced-on-the-pipeline-list-and-deal-detail
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnIndexPage: {}, openRowTarget: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({ NcActionButton: {}, NcActions: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => ({ config: {} }) }))
vi.mock('../../src/components/leadScore/LeadScoreBadge.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/AssigneePickerDialog.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/PriorityPickerDialog.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/StagePickerDialog.vue', () => ({ default: {} }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'pieter', displayName: 'Pieter Jansen' }) }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: { ocs: { data: [] } } })) } }))
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (p) => p }))

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const page = (id) => manifest.pages.find((p) => p.id === id)
const widget = (pageId, widgetId) => page(pageId).config.widgets.find((w) => w.id === widgetId)

let card
let leadList

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => vars[key] ?? m)
	globalThis.n = (app, one, more, count) => (count === 1 ? one : more).replace('%n', count)
	card = (await import('../../src/views/pipeline/PipelineCard.vue')).default
	leadList = (await import('../../src/views/leads/LeadList.vue')).default
})

/**
 * The card's computed values over a lead in stage Voorstel (60%).
 *
 * @param {object} item The lead.
 * @return {object}
 */
function cardFor(item) {
	const ctx = { item, stages: [{ name: 'Voorstel', probability: 60 }], columnProperty: 'stage', entityType: 'lead', ownerName: '' }
	ctx.cardStage = card.computed.cardStage.call(ctx)
	return ctx
}

describe('the pipeline board card', () => {
	it('shows the decayed win chance of a lead untouched for 45 days, not its stage chance', () => {
		// probability 60, untouched 45 days: OpenRegister serves winProbability 30 (the 50% band).
		expect(card.computed.chance.call(cardFor({ stage: 'Voorstel', probability: 60, winProbability: 30 }))).toBe(30)
	})

	it('keeps a served win chance of zero', () => {
		expect(card.computed.chance.call(cardFor({ stage: 'Voorstel', probability: 0, winProbability: 0 }))).toBe(0)
	})

	it('falls back to the lead\'s own chance when the calculation is not served', () => {
		expect(card.computed.chance.call(cardFor({ stage: 'Voorstel', probability: 45 }))).toBe(45)
		expect(card.computed.chance.call(cardFor({ stage: 'Voorstel', probability: 45, winProbability: '' }))).toBe(45)
	})
})

describe('the Leads list', () => {
	it('reads the win chance from winProbability and asks for the calculations', () => {
		const columns = leadList.data.call({}).columns
		const winChance = columns.find((column) => column.label === 'Win chance')
		expect(winChance.key).toBe('winProbability')
		const source = readFileSync(resolve(__dirname, '../../src/views/leads/LeadList.vue'), 'utf8')
		expect(source).toMatch(/:extend="\[[^\]]*'calculations'[^\]]*\]"/)
	})

	it('does not call the lead score win chance', () => {
		const columns = leadList.data.call({}).columns
		expect(columns.find((column) => column.key === 'qualificationScore').label).toBe('Lead score')
		for (const column of page('Leads').config.columns) {
			if (typeof column === 'object' && column.label === 'Win chance') {
				expect(column).toMatchObject({ key: 'winProbability', widget: 'lead-probability' })
			}
		}
		expect(page('Leads').config.extend).toContain('calculations')
	})
})

describe('the lead page', () => {
	it('reads the record with the calculations', () => {
		expect(page('LeadDetail').config.extend).toEqual(['calculations'])
	})

	it('shows winProbability as the win chance in the Deal data and the side card', () => {
		const deal = widget('LeadDetail', 'lead-deal').content
		expect(deal.include).toContain('winProbability')
		expect(deal.include).not.toContain('qualificationScore')
		expect(deal.overrides.winProbability).toMatchObject({ label: 'Win chance', editable: false })
		expect(widget('LeadDetail', 'side-win-chance').content.fields[0].path).toBe('winProbability')
	})
})

describe('the lead lists on the client and contact pages', () => {
	it.each([['ClientDetail', 'client-leads'], ['ContactDetail', 'contact-deals']])('%s asks for the calculations its Win chance column reads', (pageId, widgetId) => {
		const content = widget(pageId, widgetId).content
		expect(content.extend).toEqual(['calculations'])
		const winChance = content.columns.find((column) => column.label === 'Win chance')
		expect(winChance.key).toBe('winProbability')
	})
})
