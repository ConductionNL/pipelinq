// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The getting-started tour hands off to shillinq for billing.
 *
 * The last sales step carries a cross-app `handoff`. Without a `tour` the
 * engine asks shillinq to resume `pipelinq:getting-started`, a tour shillinq
 * does not have, so the user lands on shillinq with nothing to follow. The
 * hand-off names shillinq's own tour and the step where billing starts.
 * Every element the tour points at has a stable identity: a menu entry, the
 * shared Add button, or the pipeline board's `data-walkthrough-id`.
 *
 * @spec openspec/specs/pipelinq-walkthrough/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}
const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
// The tour runs in the full structure only (the simple menu holds it back).
const manifest = buildProfiledManifest(buildManifest, readJson('src', 'manifest.json'), fragments, readJson('src', 'menu-layout.json'))
const tour = manifest.walkthrough.tours.find((t) => t.id === 'pipelinq:getting-started')

describe('getting-started tour hand-off', () => {
	it('hands off to shillinq on the billing step', () => {
		const step = tour.steps.find((s) => s.id === 'send-to-shillinq')
		expect(step.handoff.url).toBe('/index.php/apps/shillinq/')
		expect(step.handoff.app).toBe('Shillinq')
	})

	it('resumes a tour shillinq ships, at the step where billing starts', () => {
		const step = tour.steps.find((s) => s.id === 'send-to-shillinq')
		expect(step.handoff.tour).toBe('shillinq:getting-started')
		expect(step.handoff.step).toBe('open-quick-draft')
	})

	it('points every element step at an instrumented element', () => {
		const menuIds = new Set()
		const collect = (items) => (items || []).forEach((m) => {
			menuIds.add(m.id)
			menuIds.add(typeof m.route === 'string' ? m.route : m.route?.name)
			collect(m.children)
		})
		collect(manifest.menu)
		const elementIds = new Set(['index-add'])
		const vueRoot = path.join(ROOT, 'src')
		const walk = (dir) => fs.readdirSync(dir, { withFileTypes: true }).forEach((e) => {
			const p = path.join(dir, e.name)
			if (e.isDirectory()) {
				walk(p)
			} else if (e.name.endsWith('.vue')) {
				for (const m of fs.readFileSync(p, 'utf8').matchAll(/data-walkthrough-id="([^"]+)"/g)) {
					elementIds.add(m[1])
				}
			}
		})
		walk(vueRoot)
		for (const step of tour.steps) {
			if (step.target.kind === 'element') {
				expect(elementIds.has(step.target.ref), step.id).toBe(true)
			}
			if (step.target.kind === 'nav-item') {
				expect(menuIds.has(step.target.ref), step.id).toBe(true)
			}
		}
	})
})
