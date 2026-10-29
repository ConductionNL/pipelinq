/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqKassaAudit: an opened audit row is a 360px card beside the list,
 * and its sections are as tall as what they say rather than fixed rows.
 *
 * @spec openspec/changes/pos-audit-record-side-card/specs/pos-audit-record-side-card/spec.md#requirement-an-opened-audit-entry-is-the-side-card-the-board-draws
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const pages = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8')).pages
const list = pages.find((p) => p.id === 'KassakoppelingAuditList')
const detail = pages.find((p) => p.id === 'KassakoppelingAuditDetail')

describe('cash register audit record beside the list', () => {
	it('opens the record in a 360px pane', () => {
		expect(list.splitView).toEqual({ enabled: true, paneWidth: '360px' })
	})

	it('sizes every section of the record to its content', () => {
		expect(detail.config.layout).toHaveLength(3)
		for (const cell of detail.config.layout) {
			expect(cell.sizeToContent).toBe(true)
		}
	})
})
