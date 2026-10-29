/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The queue's list card and tiles as board PqWachtrij draws them: the kind
 * lenses open the card with "Kind, then channel" and the channel strip, the
 * rows have no column heads, and the third tile counts the tickets within
 * their deadline out of all waiting ones.
 *
 * @spec openspec/changes/queue-list-card-board/specs/queue/spec.md#requirement-the-queue-list-card-opens-with-its-lenses
 * @spec openspec/changes/queue-list-card-board/specs/queue/spec.md#requirement-the-queue-counts-the-tickets-within-their-deadline
 */
import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { applyMenuModules } from '../../src/utils/menuModules.js'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const fragments = fs.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json')).sort()
	.map((name) => readJson('src', 'manifest.d', name))
const built = buildProfiledManifest(buildManifest, readJson('src', 'manifest.json'), fragments, applyMenuModules(readJson('src', 'menu-layout.simple.json'), []))
const simple = built.pages.find((page) => page.id === 'Queue').config
const l10n = (lang) => readJson('l10n', `${lang}.json`).translations

describe('the queue list card (PqWachtrij)', () => {
	it('opens the card with the lenses, the caption and the channel strip', () => {
		expect(simple.quickFilterPlacement).toBe('card')
		expect(simple.quickFilterCaption).toBe('Kind, then channel')
		expect(simple.filterStrip.field).toBe('channel')
	})

	it('draws rows without column heads', () => {
		expect(simple.hideTableHeader).toBe(true)
	})
})

describe('the queue tiles (PqWachtrij)', () => {
	it('reads Unassigned, Longest waiting, Within the deadline', () => {
		expect(simple.statRow.map((tile) => tile.id)).toEqual(['queue-unassigned', 'queue-longest-waiting', 'queue-within-deadline'])
	})

	it('counts the waiting tickets not past their deadline, out of all waiting ones', () => {
		const tile = simple.statRow.find((t) => t.id === 'queue-within-deadline')
		const unassigned = simple.statRow.find((t) => t.id === 'queue-unassigned').content.source.filter
		const { content } = tile
		expect(content.label).toBe('Within the deadline')
		expect(content.caption).toBe('take the red ones first')
		expect(content.source.kind).toBe('computed')
		expect(content.source.formula).toBe('A - B')
		expect(content.source.parts.A.filter).toEqual(unassigned)
		expect(content.source.parts.B.filter).toEqual({ ...unassigned, 'slaDeadline[lt]': '@now' })
		expect(content.limitSource.filter).toEqual(unassigned)
		expect(content.limitStyle).toBe('of')
	})

	it('has its caption in English and Dutch', () => {
		expect(l10n('en')['take the red ones first']).toBe('take the red ones first')
		expect(l10n('nl')['take the red ones first']).toBe('pak de rode eerst')
	})
})
