/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The queue is a default view on All tickets, not a page of its own, in both
 * structure profiles.
 *
 * @spec openspec/changes/queue-as-default-view/specs/retire-queue-concept/spec.md
 */
import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { applyMenuModules } from '../../src/utils/menuModules.js'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
const build = (file) => buildProfiledManifest(buildManifest, readJson('src', 'manifest.json'), fragments, file)
const profiles = {
	full: () => build(readJson('src', 'menu-layout.json')),
	simple: () => build(applyMenuModules(readJson('src', 'menu-layout.simple.json'), [])),
}
const CLOSED = ['resolved', 'completed', 'rejected', 'converted', 'closed']

/**
 * Every value in a JSON tree.
 *
 * @param {unknown} node The tree.
 * @return {Array<unknown>} The values, depth first.
 */
function values(node) {
	if (Array.isArray(node)) {
		return node.flatMap(values)
	}
	if (node && typeof node === 'object') {
		return Object.values(node).flatMap(values)
	}
	return [node]
}

describe.each(Object.keys(profiles))('the %s profile', (name) => {
	const built = profiles[name]()
	const tickets = built.pages.find((page) => page.id === 'Tickets')

	it('ships the queue as a default view on All tickets', () => {
		expect(tickets.config.allowSavedViews).toBe(true)
		const queue = tickets.config.defaultSavedViews.find((view) => view.slug === 'queue')
		expect(queue).toEqual({
			slug: 'queue',
			name: 'Queue',
			filters: { assignee: 'IS NULL', status_notIn: CLOSED },
			sort: [{ key: 'occurredAt', order: 'asc' }],
		})
	})

	it('has no Queue page, menu entry or route to it', () => {
		expect(built.pages.some((page) => page.id === 'Queue' || page.route === '/queue')).toBe(false)
		expect(values(built.pages).filter((value) => value === '/queue')).toEqual([])
		expect(values(built.menu).filter((value) => value === 'Queue')).toEqual([])
	})
})

describe('links to the queue on the simple dashboard', () => {
	it('open the queue view on All tickets', () => {
		const text = JSON.stringify(profiles.simple().pages)
		expect(text).toContain('"route":"Tickets","query":{"savedView":"default:queue"}')
		expect(text).toContain('"route":{"name":"Tickets","query":{"savedView":"default:queue"}}')
	})
})

describe('the view name', () => {
	it('is translated as Wachtrij', () => {
		expect(readJson('l10n', 'nl.json').translations.Queue).toBe('Wachtrij')
	})
})
