// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The v2 component registry (src/registry.js) against its consumers.
 *
 * Covers:
 *  - every entry has a recognised kind and what that kind needs: a component,
 *    or a handler function.
 *  - every widget entry carries the grid metadata CnAppRoot's validator wants.
 *  - every component / handler name the bundled manifest and its fragments
 *    use resolves to a registry entry.
 */

import { readdirSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeAll, describe, expect, it } from 'vitest'

const SRC = resolve(__dirname, '../../src')
const KINDS = ['page', 'modal', 'widget', 'section', 'handler', 'create-override']
const REFERENCE_KEYS = /^(component|headerComponent|actionsComponent|moveHandler|moveTargetsHandler|createOverride)$/

let registry

beforeAll(async () => {
	// Some components translate at module scope through the Nextcloud globals.
	globalThis.t = (_app, text) => text
	globalThis.n = (_app, one, many, count) => (count === 1 ? one : many)
	registry = (await import('../../src/registry.js')).default
}, 60000)

function manifestReferences() {
	const files = [resolve(SRC, 'manifest.json')].concat(
		readdirSync(resolve(SRC, 'manifest.d')).filter((f) => f.endsWith('.json')).map((f) => resolve(SRC, 'manifest.d', f)),
	)
	const refs = new Set()
	const walk = (node) => {
		if (Array.isArray(node)) {
			node.forEach(walk)
		} else if (node && typeof node === 'object') {
			for (const [key, value] of Object.entries(node)) {
				if (REFERENCE_KEYS.test(key) && typeof value === 'string') {
					refs.add(value)
				}
				walk(value)
			}
		}
	}
	files.forEach((f) => walk(JSON.parse(readFileSync(f, 'utf8'))))
	return [...refs]
}

describe('registry contract', () => {
	it('gives every entry a recognised kind and its payload', () => {
		const bad = []
		for (const [key, entry] of Object.entries(registry)) {
			if (!KINDS.includes(entry.kind)) {
				bad.push(`${key}: kind ${entry.kind}`)
			} else if (['handler', 'create-override'].includes(entry.kind)) {
				if (typeof entry.handler !== 'function') bad.push(`${key}: no handler`)
			} else if (!entry.component || typeof entry.component !== 'object') {
				bad.push(`${key}: no component`)
			}
		}
		expect(bad).toEqual([])
		expect(Object.keys(registry).length).toBeGreaterThan(100)
	})

	it('gives every widget its grid metadata', () => {
		const bad = Object.entries(registry)
			.filter(([, e]) => e.kind === 'widget')
			.filter(([, e]) => !(e.defaultSize && e.minSize && e.maxSize && Array.isArray(e.allowedSlots) && e.allowedSlots.length > 0))
			.map(([key]) => key)
		expect(bad).toEqual([])
	})

	it('resolves every name the manifest refers to', () => {
		const refs = manifestReferences()
		expect(refs.length).toBeGreaterThan(10)
		const unknown = refs.filter((name) => !(name in registry))
		expect(unknown).toEqual([])
	})
})
