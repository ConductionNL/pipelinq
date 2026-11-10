/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A profile file's modules, branch by branch: a module that is off removes
 * its ids, one that is on brings its menu and ids back and the caption with it.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-104
 */
import { describe, expect, it } from 'vitest'
import { applyMenuModules } from '../../src/utils/menuModules.js'

describe('applyMenuModules', () => {
	it('passes a file without modules through', () => {
		const file = { menu: [] }
		expect(applyMenuModules(file, ['a'])).toBe(file)
		expect(applyMenuModules(null, ['a'])).toBe(null)
		const odd = { modules: 'nope' }
		expect(applyMenuModules(odd, ['a'])).toBe(odd)
	})

	it('removes the ids of modules that are off, in file order, and keeps the file as it was', () => {
		const file = { removals: ['x'], menu: [{ id: 'm' }], modules: { a: { ids: ['a1', 'x'] }, b: null, c: { ids: 'nope' } }, modulesCaption: { id: 'cap' } }
		const out = applyMenuModules(file, 'nope')
		expect(out).toEqual({ ...file, removals: ['x', 'a1'], menu: [{ id: 'm' }] })
		expect(file.removals).toEqual(['x'])
	})

	it('brings an on module back: its menu, its ids out of the removals, then the caption', () => {
		const file = {
			removals: ['a1', 'b1'],
			modules: { a: { ids: ['a1'], menu: [{ id: 'A' }] }, b: { ids: ['b1'], menu: 'nope' }, c: { ids: ['a1'] } },
			modulesCaption: { id: 'cap' },
		}
		const out = applyMenuModules(file, ['a', 'b'])
		expect(out.removals).toEqual(['a1'])
		expect(out.menu).toEqual([{ id: 'A' }, { id: 'cap' }])
		expect(applyMenuModules(file, ['c', 'a']).removals).toEqual(['b1'])
		expect(applyMenuModules(file, ['b']).removals).toEqual(['a1'])
	})

	it('adds no caption when no module is on or the file has none', () => {
		const file = { modules: { a: { menu: [{ id: 'A' }] } } }
		expect(applyMenuModules(file, ['a'])).toEqual({ ...file, removals: [], menu: [{ id: 'A' }] })
		expect(applyMenuModules({ ...file, modulesCaption: { id: 'cap' } }, []).menu).toEqual([])
	})
})
