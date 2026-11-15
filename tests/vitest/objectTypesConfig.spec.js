/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The object type configuration: every group and every type, in display
 * order, translated when asked for and not before.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'

const translate = vi.hoisted(() => vi.fn((app, text) => `[${app}] ${text}`))
vi.mock('@nextcloud/l10n', () => ({ translate }))

const { objectTypeGroups, objectTypeGroupsByKey, objectTypes } = await import('../../src/config/objectTypes.js')
const golden = JSON.parse(readFileSync(resolve(__dirname, 'golden/objectTypes.json'), 'utf8'))

describe('object type configuration', () => {
	it('translates nothing when the module loads', () => {
		expect(translate).not.toHaveBeenCalled()
	})

	it('lists every group, in order, with its keys in order', () => {
		expect(JSON.stringify(objectTypeGroups())).toBe(JSON.stringify(golden.groups))
	})

	it('lists every object type, in order, with its keys in order', () => {
		expect(JSON.stringify(objectTypes())).toBe(JSON.stringify(golden.types))
	})

	it('looks the groups up by key', () => {
		const byKey = objectTypeGroupsByKey()
		expect(Object.keys(byKey)).toEqual(golden.groups.map((group) => group.key))
		expect(byKey.pos).toEqual(golden.groups.find((group) => group.key === 'pos'))
	})

	it('hands out fresh objects each time', () => {
		expect(objectTypes()[0]).not.toBe(objectTypes()[0])
		expect(objectTypeGroups()[0]).not.toBe(objectTypeGroups()[0])
	})
})
