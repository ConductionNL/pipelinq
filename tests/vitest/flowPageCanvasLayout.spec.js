/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boards PqFlow and PqFlowRun: the flow page is laid out as a canvas page
 * (nc-vue CnFlowBoard `layout: "canvas"`), mounts no app sidebar, and names
 * when the version was saved on its path line.
 *
 * @spec openspec/specs/crm-workflow-automation/spec.md#requirement-automation-builder-ui
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const page = manifest.pages.find((p) => p.id === 'FlowDetail')

describe('the flow page', () => {
	it('is laid out as a canvas page', () => {
		expect(page.config.layout).toBe('canvas')
	})

	it('mounts no app sidebar', () => {
		expect(page.sidebarComponent).toBeUndefined()
	})

	it('names the trigger, the steps and when the version was saved', () => {
		expect(page.config.metaParts).toEqual(['trigger', 'steps', 'version', 'saved'])
	})

	it('keeps editing a published flow in place', () => {
		expect(page.config.saveAsVersion).toBe(true)
	})
})
