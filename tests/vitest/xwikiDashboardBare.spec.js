/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The knowledge base card on the operational dashboard as the PqOperationeel
 * board draws it: one heading (the dashboard card's), and a full-width search
 * field with a search icon. The widget drew its own card and a second h3
 * inside the dashboard's. Read as text.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const widget = readFileSync(resolve(__dirname, '../../src/components/xwiki/XWikiWidget.vue'), 'utf8')
const dashboard = readFileSync(resolve(__dirname, '../../src/views/dashboard/widgets/XWikiDashboardWidget.vue'), 'utf8')

describe('the knowledge base card', () => {
	it('is bare on the dashboard', () => {
		expect(dashboard).toMatch(/:showSearch="true"\s+bare \/>/)
	})

	it('draws no own title when bare, and a search form with an icon', () => {
		expect(widget).toContain('<header v-if="!bare" class="xwiki-widget__header">')
		expect(widget).toContain('role="search"')
		expect(widget).toContain("t('pipelinq', 'Search the knowledge base')")
		expect(widget).toMatch(/\.xwiki-widget__search-bar-input \{[^}]*height: 40px/)
	})
})
