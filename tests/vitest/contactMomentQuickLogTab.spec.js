/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Where the quick log with its draft lives on ClientDetail
 * (contact-moments-keep-draft, task 1.3 Verify). The relation-board layout
 * renders the band and the tab strip; a body section below them was not
 * reached in the live pass of 17 Nov 2026. So the quick log is a grid
 * widget on top of the Contact moments tab, bound to the page's client,
 * and the body section is gone, so one page never runs two autosaving
 * quick logs for the same client.
 *
 * @spec openspec/changes/contact-moments-keep-draft/specs/contact-moment-drafts/spec.md#requirement-a-manual-contact-moment-is-kept-as-a-private-draft-req-cmd2-001
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const registrySource = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
const config = manifest.pages.find((page) => page.id === 'ClientDetail').config
const widget = (id) => config.widgets.find((entry) => entry.id === id)
const tabs = widget('client-panels').content.tabs

describe('ClientDetail quick log on the Contact moments tab', () => {
	it('stacks the quick log above the contact moment list in the Contact moments tab', () => {
		const tab = tabs.find((entry) => entry.label === 'Contact moments')
		expect(tab.widgetIds).toEqual(['client-contactmoment-quick-log', 'client-contactmoments'])
		// The tab still counts the list, not the form.
		expect(tab.countFrom).toBe('widget')
		expect(widget('client-contactmoments').content.schema).toBe('ticket')
	})

	it('declares the quick log as a widget with a titled card', () => {
		const quickLog = widget('client-contactmoment-quick-log')
		expect(quickLog.type).toBe('ContactmomentQuickLogWidget')
		expect(quickLog.content.title).toBe('Log contactmoment')
	})

	it('keeps the quick log out of layout, so the grid does not render it a second time', () => {
		expect(config.layout.map((item) => item.widgetId)).not.toContain('client-contactmoment-quick-log')
	})

	it('mounts no quick log as a body section any more', () => {
		const components = (config.bodyWidgets || []).map((entry) => entry.component)
		expect(components).not.toContain('ContactmomentQuickLog')
	})

	it('registers the widget around the quick log, bound to the page client in inline mode', () => {
		expect(registrySource).toMatch(/ContactmomentQuickLogWidget:\s*\{\s*kind: 'widget'/)
		expect(registrySource).toMatch(
			/sectionWidget\(\s*'ContactmomentQuickLogWidget',\s*ContactmomentQuickLog,\s*\(\{ objectId \}\) => \(\{ clientId: objectId, inline: true \}\),\s*\{ card: true \},\s*\)/,
		)
	})
})
