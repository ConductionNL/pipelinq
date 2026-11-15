// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The kanban's lead cards in the board look (board PqPipeline): the chance
 * in the stage's tint, the days in the stage and the owner's initials.
 *
 * @spec openspec/specs/pipeline/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeAll, describe, expect, it, vi } from 'vitest'

const lookups = vi.hoisted(() => ({ count: 0 }))

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({ NcActionButton: {}, NcActions: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => ({ config: {} }) }))
vi.mock('../../src/components/leadScore/LeadScoreBadge.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/AssigneePickerDialog.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/PriorityPickerDialog.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/StagePickerDialog.vue', () => ({ default: {} }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'pieter', displayName: 'Pieter Jansen' }) }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(() => { lookups.count++; return Promise.resolve({ data: { ocs: { data: [] } } }) }) } }))
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (p) => p }))

let card
let names

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => vars[key] ?? m)
	globalThis.n = (app, one, more, count) => (count === 1 ? one : more).replace('%n', count)
	card = (await import('../../src/views/pipeline/PipelineCard.vue')).default
	names = await import('../../src/services/userDisplayName.js')
})

const stages = [
	{ name: 'Nieuw', probability: 10, color: '#3669a5' },
	{ name: 'Benaderd', probability: 30, color: '#6b5bb5' },
]

/**
 * The card's computed values over a lead.
 *
 * @param {object} item The lead.
 * @return {object}
 */
function cardFor(item) {
	const ctx = { item, stages, columnProperty: 'stage', entityType: 'lead', ownerName: '' }
	ctx.cardStage = card.computed.cardStage.call(ctx)
	return ctx
}

describe('the board look lead card', () => {
	it('shows the lead\'s own chance, else its stage\'s, in the stage\'s colour', () => {
		expect(card.computed.chance.call(cardFor({ stage: 'Benaderd', probability: 45 }))).toBe(45)
		const fromStage = cardFor({ stage: 'Benaderd' })
		expect(card.computed.chance.call(fromStage)).toBe(30)
		expect(card.computed.stageColor.call(fromStage)).toBe('#6b5bb5')
		expect(card.computed.chance.call({ ...cardFor({ stage: 'Nieuw' }), entityType: 'request' })).toBeNull()
	})

	it('names the owner by the initials of the display name', () => {
		expect(names.initialsOf('Pieter Jansen')).toBe('PJ')
		expect(names.initialsOf('fatma')).toBe('F')
		expect(names.initialsOf('')).toBe('')
		expect(card.computed.ownerInitials.call({ ownerName: 'Joris Bakker', item: { assignee: 'joris' } })).toBe('JB')
	})

	it('asks a display name once per user, however many cards show it', async () => {
		const before = lookups.count
		const all = await Promise.all([names.cachedUserDisplayName('joris'), names.cachedUserDisplayName('joris'), names.cachedUserDisplayName('joris')])
		expect(all).toEqual(['joris', 'joris', 'joris'])
		expect(lookups.count - before).toBe(1)
		expect(await names.cachedUserDisplayName('pieter')).toBe('Pieter Jansen')
	})

	it('draws the board card and the board columns only in the board look', () => {
		const cardSource = readFileSync(resolve(__dirname, '../../src/views/pipeline/PipelineCard.vue'), 'utf8')
		expect(cardSource).toMatch(/<template v-if="boardLook">[\s\S]*pipeline-card-chance[\s\S]*pipeline-card-owner/)
		const boardSource = readFileSync(resolve(__dirname, '../../src/views/pipeline/PipelineBoard.vue'), 'utf8')
		expect(boardSource).toContain(':boardLook="isBoard"')
		expect(boardSource).toMatch(/\.cn-look-board \.kanban-column \{[^}]*width: 240px/)
	})
})
