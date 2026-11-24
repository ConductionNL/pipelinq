// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The Nextcloud dashboard widget entries.
 *
 * Covers:
 *  - registerDashboardWidget registers the id with OCA.Dashboard and, when
 *    the dashboard calls back, mounts the component into the given element
 *    with the widget's title, t/n and the shared Pinia store.
 *  - each widget entry registers its own component under the id its PHP
 *    widget class (lib/Dashboard) declares.
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { getActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const ROOT = resolve(__dirname, '../..')

describe('registerDashboardWidget', () => {
	let register

	beforeEach(() => {
		register = vi.fn()
		globalThis.OCA = { Dashboard: { register } }
	})

	afterEach(() => {
		delete globalThis.OCA
		document.body.innerHTML = ''
	})

	it('registers the widget id and mounts the component on callback', async () => {
		const { registerDashboardWidget } = await import('../../src/mountDashboardWidget.js')
		const seen = {}
		const Component = {
			props: { title: { type: String, default: '' } },
			render() {
				seen.title = this.title
				seen.t = typeof this.t
				seen.n = typeof this.n
				seen.pinia = getActivePinia() !== undefined
				return h('p', { class: 'mounted' }, this.title)
			},
		}

		registerDashboardWidget('pipelinq_test_widget', Component)
		expect(register).toHaveBeenCalledTimes(1)
		const [id, callback] = register.mock.calls[0]
		expect(id).toBe('pipelinq_test_widget')

		const el = document.createElement('div')
		document.body.appendChild(el)
		callback(el, { widget: { title: 'My leads' } })

		expect(el.querySelector('.mounted').textContent).toBe('My leads')
		expect(seen).toEqual({ title: 'My leads', t: 'function', n: 'function', pinia: true })
	})
})

const ENTRIES = [
	['createLeadWidget', 'CreateLeadWidget'],
	['dealsOverviewWidget', 'DealsOverviewWidget'],
	['findClientWidget', 'FindClientWidget'],
	['myLeadsWidget', 'MyLeadsWidget'],
	['recentActivitiesWidget', 'RecentActivitiesWidget'],
	['startRequestWidget', 'StartRequestWidget'],
]

describe('dashboard widget entries', () => {
	afterEach(() => {
		vi.resetModules()
		vi.doUnmock('../../src/mountDashboardWidget.js')
	})

	it.each(ENTRIES)('%s registers %s under its PHP widget id', async (entry, component) => {
		const registerDashboardWidget = vi.fn()
		vi.doMock('../../src/mountDashboardWidget.js', () => ({ registerDashboardWidget }))
		const marker = { name: `${component}Marker` }
		vi.doMock(`../../src/views/widgets/${component}.vue`, () => ({ default: marker }))

		await import(`../../src/${entry}.js`)

		const php = readFileSync(resolve(ROOT, `lib/Dashboard/${component}.php`), 'utf8')
		const declared = php.match(/return '(pipelinq_[a-z_]+_widget)';/)[1]
		expect(registerDashboardWidget).toHaveBeenCalledTimes(1)
		expect(registerDashboardWidget).toHaveBeenCalledWith(declared, marker)
		vi.doUnmock(`../../src/views/widgets/${component}.vue`)
	})
})
