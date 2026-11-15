/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Row clicks in the compact dashboard list widgets (deals overview, my leads,
 * recent activities) go through `navigateTo`. The widgets also run on the
 * Nextcloud Dashboard without a router, so the target is a plain URL: a plain
 * click navigates in place, a ctrl/cmd/shift or middle click opens a new tab.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock(
	'@conduction/nextcloud-vue',
	() => import('@conduction/nextcloud-vue/src/utils/linkNavigation.js'),
)

const { navigateTo } = await import('../../src/views/widgets/listTable.js')

const URL = '/index.php/apps/pipelinq/leads/42'

describe('listTable navigateTo', () => {
	let win

	beforeEach(() => {
		win = { location: { assign: vi.fn() }, open: vi.fn() }
		vi.stubGlobal('window', win)
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('navigates in place on a plain click', () => {
		navigateTo(URL, { button: 0 })
		expect(win.location.assign).toHaveBeenCalledWith(URL)
		expect(win.open).not.toHaveBeenCalled()
	})

	it('navigates in place without an event', () => {
		navigateTo(URL)
		expect(win.location.assign).toHaveBeenCalledWith(URL)
	})

	it.each([
		['ctrl', { button: 0, ctrlKey: true }],
		['cmd', { button: 0, metaKey: true }],
		['shift', { button: 0, shiftKey: true }],
		['middle', { button: 1 }],
	])('opens a new tab on a %s click', (label, event) => {
		navigateTo(URL, event)
		expect(win.open).toHaveBeenCalledWith(URL, '_blank', 'noopener,noreferrer')
		expect(win.location.assign).not.toHaveBeenCalled()
	})

	it('ignores a right click', () => {
		navigateTo(URL, { button: 2 })
		expect(win.open).not.toHaveBeenCalled()
		expect(win.location.assign).not.toHaveBeenCalled()
	})
})
