// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Edge cases of the menu runtime: absent profile files, a non-array enabled
 * list, the non-simple profile and manifests without a Reports page.
 */

import { describe, expect, it } from 'vitest'
import { menuRuntime, seedMenuRuntime } from '../../src/utils/menuRuntime.js'

const translate = (text) => `T(${text})`
const profileFile = {
	modules: {
		leads: { label: 'Leads' },
		tickets: { label: 'Tickets' },
		pos: { label: 'Point of sale' },
	},
}

describe('menuRuntime edge cases', () => {
	it('answers an empty menu without a profile file', () => {
		expect(menuRuntime({ profile: 'simple', enabled: ['leads'], translate })).toEqual({
			profile: 'simple',
			modulesOn: '',
			modulesOff: '',
			modulesOnCount: 0,
			modulesCount: 0,
			enabled: {},
			reports: 0,
		})
	})

	it('treats a non-array enabled list as nothing enabled', () => {
		const runtime = menuRuntime({ profile: 'simple', profileFile, enabled: 'leads', translate })
		expect(runtime.modulesOn).toBe('')
		expect(runtime.modulesOff).toBe('T(Leads), T(Tickets), T(Point of sale)')
		expect(runtime.modulesOnCount).toBe(0)
		expect(runtime.enabled).toEqual({ leads: false, tickets: false, pos: false })
	})

	it('enables every module outside the simple profile, whatever the list says', () => {
		const runtime = menuRuntime({ profile: 'full', profileFile, enabled: ['tickets'], translate })
		expect(runtime.enabled).toEqual({ leads: true, tickets: true, pos: true })
		expect(runtime.modulesOn).toBe('T(Tickets)')
		expect(runtime.modulesOnCount).toBe(1)
		expect(runtime.modulesCount).toBe(3)
	})

	it('counts the Reports cards only when the page has a card list', () => {
		const withCards = { pages: [{ id: 'Reports', config: { cards: [{}, {}, {}] } }] }
		const withoutCards = { pages: [{ id: 'Reports', config: {} }] }
		const otherPage = { pages: [{ id: 'Dashboard', config: { cards: [{}] } }] }
		const run = (manifest) => menuRuntime({ profile: 'simple', profileFile, enabled: [], manifest, translate }).reports
		expect(run(withCards)).toBe(3)
		expect(run(withoutCards)).toBe(0)
		expect(run(otherPage)).toBe(0)
		expect(run(undefined)).toBe(0)
	})
})

describe('seedMenuRuntime edge cases', () => {
	it('creates the runtime on a manifest that has none and returns the same manifest', () => {
		const manifest = { pages: [] }
		const menu = { profile: 'simple' }
		expect(seedMenuRuntime(manifest, menu)).toBe(manifest)
		expect(manifest.runtime).toEqual({ menu })
	})

	it('replaces an earlier menu', () => {
		const manifest = { runtime: { menu: { profile: 'old' }, other: 1 } }
		seedMenuRuntime(manifest, { profile: 'new' })
		expect(manifest.runtime).toEqual({ menu: { profile: 'new' }, other: 1 })
	})
})
