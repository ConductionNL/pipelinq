/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The store page as board PqStore draws it: the library's store page type,
 * with pipelinq's description, a Connectors lens and the templates that ship
 * with the app.
 *
 * @spec openspec/changes/store-installed-and-updates/specs/pipelinq-store-surface/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

function read(file) {
	return readFileSync(resolve(__dirname, '../../', file), 'utf8')
}

const store = JSON.parse(read('src/manifest.json')).pages.find((page) => page.id === 'Store')
const nl = JSON.parse(read('l10n/nl.json')).translations

describe('the store page (board PqStore)', () => {
	it('is the library store page for pipelinq, not a custom view', () => {
		expect(store.type).toBe('store')
		expect(store.component).toBeUndefined()
		expect(store.config.app).toBe('pipelinq')
		expect(read('src/registry.js')).not.toMatch(/StoreGallery/)
	})

	it('counts the registry in its description and offers a Connectors lens', () => {
		expect(store.config.description).toContain('{count}')
		expect(nl[store.config.description]).toBe('Sjablonen en connectors voor Pipelinq, {count} in de registry')
		expect(store.config.kindLenses).toEqual([{ kind: 'connector', label: 'Connectors' }])
		expect(store.config.kinds).toContain('connector')
	})

	it('lets an administrator publish a template from the page', () => {
		expect(store.config.publishable).toBe(true)
		expect(read('appinfo/routes.php')).toMatch(/'store#publish',\s*'url' => '\/api\/store\/publish'/)
		expect(read('appinfo/routes.php')).toMatch(/'store#publishable',\s*'url' => '\/api\/store\/publishable'/)
	})

	it('names the three templates that ship with pipelinq', () => {
		expect(store.config.builtIn.map((item) => item.slug)).toEqual(['b2b-sales-pipeline', 'service-desk-routing', 'retail-pos-setup'])
	})

	it('labels every kind in Dutch', () => {
		for (const kind of store.config.kinds) {
			expect(typeof nl[kind]).toBe('string')
		}
	})
})
