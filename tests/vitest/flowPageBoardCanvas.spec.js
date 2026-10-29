/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boards PqFlow and PqFlowRun draw the flow as a canvas page: nextcloud-vue's
 * `config.layout: "canvas"`. Its inspector holds the flow settings, so the
 * page mounts no app sidebar.
 *
 * @spec openspec/changes/flow-page-board-canvas/specs/crm-workflow-automation/spec.md
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const page = manifest.pages.find((p) => p.id === 'FlowDetail')

describe('the flow page', () => {
	it('draws the flow as the board canvas', () => {
		expect(page).toBeDefined()
		expect(page.config.layout).toBe('canvas')
	})

	it('mounts no app sidebar', () => {
		expect(page.sidebarComponent).toBeUndefined()
		expect(fs.existsSync(path.join(ROOT, 'src/views/flows/FlowDetailSidebar.vue'))).toBe(false)
	})
})
