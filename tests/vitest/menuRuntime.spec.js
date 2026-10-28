/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The menu facts the Advanced page reads from `runtime.menu` (board PqGeavanceerdHub).
 *
 * @spec openspec/changes/advanced-hub-status-panel/specs/admin-settings/spec.md#requirement-the-advanced-page-shows-the-menu-as-it-is-set
 */
import { describe, expect, it } from 'vitest'
import advanced from '../../src/manifest.d/99-advanced.json'
import { menuRuntime, seedMenuRuntime } from '../../src/utils/menuRuntime.js'

const profileFile = {
	modules: {
		sales: { label: 'Sales' },
		marketing: { label: 'Marketing' },
		pos: { label: 'Point of Sale' },
		products: { label: 'Products' },
	},
}
const dutch = { Sales: 'Verkoop', Marketing: 'Marketing', 'Point of Sale': 'Kassa', Products: 'Producten' }
const manifest = { pages: [{ id: 'Reports', config: { cards: [{}, {}, {}] } }] }

describe('menuRuntime', () => {
	it('names the modules in and out of the menu in the profile order, translated', () => {
		const menu = menuRuntime({ profile: 'simple', profileFile, enabled: ['products', 'sales'], manifest, translate: (text) => dutch[text] })
		expect(menu).toEqual({
			profile: 'simple',
			modulesOn: 'Verkoop, Producten',
			modulesOff: 'Marketing, Kassa',
			modulesOnCount: 2,
			modulesCount: 4,
			enabled: { sales: true, marketing: false, pos: false, products: true },
			reports: 3,
		})
	})

	it('keeps the runtime a manifest already has', () => {
		const seeded = seedMenuRuntime({ runtime: { user: { primaryRole: 'kcc' } } }, { profile: 'full' })
		expect(seeded.runtime).toEqual({ user: { primaryRole: 'kcc' }, menu: { profile: 'full' } })
	})

	it('is what the Advanced page names: every runtime token it uses is a key menuRuntime gives', () => {
		const keys = Object.keys(menuRuntime({ profile: 'simple', profileFile, enabled: [], manifest, translate: (text) => text }))
		const used = [...JSON.stringify(advanced).matchAll(/\{runtime\.menu\.([A-Za-z]+)\}/g)].map((match) => match[1])
		expect(used.length).toBeGreaterThan(0)
		for (const key of used) {
			expect(keys).toContain(key)
		}
	})
})
