/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A template row can be copied and deleted (the server refuses a delete while
 * a scheduled mailing uses it); a segment row keeps neither action.
 *
 * @spec openspec/changes/marketing-mail-templates/specs/marketing-mail-templates/spec.md#REQ-MMT-001
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '..', '..')
const fragment = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.d/75-marketing-blasts.json'), 'utf8'))
const page = (schema) => fragment.pages.find((p) => p.config?.schema === schema)

describe('row actions on the marketing lists', () => {
	it('lets a template row be copied and deleted', () => {
		const templates = page('campaignTemplate')
		expect(templates.id).toBe('Templates')
		expect(templates.config.showCopyAction).toBe(true)
		expect(templates.config.showDeleteAction).toBe(true)
	})

	it('keeps copy and delete off a segment row', () => {
		const segments = page('segment')
		expect(segments.config.showCopyAction).toBe(false)
		expect(segments.config.showDeleteAction).toBe(false)
	})
})
