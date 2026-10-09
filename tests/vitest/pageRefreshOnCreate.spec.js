/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A new line item refreshes the deal page, so the Line items count follows
 * without a reload (round3-review-points, review point 4).
 *
 * The event name and the channel are the library's own: the spec reads them
 * from the library source, so a rename there fails here instead of leaving
 * the count stale in silence.
 *
 * @spec openspec/changes/round3-review-points/specs/lead-product-link/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import {
	installPageRefreshOnCreate,
	OBJECT_CREATED_EVENT,
	PAGE_REFRESH_CHANNEL,
} from '../../src/services/pageRefreshOnCreate.js'

vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const LIB = path.resolve(
	__dirname,
	'../../node_modules/@conduction/nextcloud-vue/src',
)
const read = (...parts) => fs.readFileSync(path.join(LIB, ...parts), 'utf8')

/** A minimal window: listeners keyed by event name. */
function fakeWindow() {
	const listeners = {}
	return {
		addEventListener: (name, fn) => {
			;(listeners[name] = listeners[name] || []).push(fn)
		},
		removeEventListener: (name, fn) => {
			listeners[name] = (listeners[name] || []).filter((l) => l !== fn)
		},
		dispatch: (name, detail) =>
			(listeners[name] || []).forEach((fn) => fn({ detail })),
	}
}

describe('page refresh on create', () => {
	it('refreshes the page after a line item is created, and not after other objects', () => {
		const win = fakeWindow()
		const send = vi.fn()
		const stop = installPageRefreshOnCreate(win, send)

		win.dispatch(OBJECT_CREATED_EVENT, {
			register: 'pipelinq',
			schema: 'leadProduct',
		})
		expect(send).toHaveBeenCalledWith(PAGE_REFRESH_CHANNEL, {})

		send.mockClear()
		win.dispatch(OBJECT_CREATED_EVENT, {
			register: 'pipelinq',
			schema: 'client',
		})
		expect(send).not.toHaveBeenCalled()

		stop()
		win.dispatch(OBJECT_CREATED_EVENT, { schema: 'leadProduct' })
		expect(send).not.toHaveBeenCalled()
	})

	it('uses the event the library dispatches and the channel the stats widget listens on', () => {
		expect(read('utils', 'walkthroughSignals.js')).toContain(
			`export const OBJECT_CREATED_EVENT = '${OBJECT_CREATED_EVENT}'`,
		)
		expect(
			read('components', 'CnObjectListWidget', 'CnObjectListWidget.vue'),
		).toContain('dispatchObjectCreated(')
		expect(
			read('components', 'CnStatsBlockWidget', 'CnStatsBlockWidget.vue'),
		).toContain(`const PAGE_REFRESH_BUS_CHANNEL = '${PAGE_REFRESH_CHANNEL}'`)
	})

	it('is installed when the app mounts', () => {
		const main = fs.readFileSync(
			path.resolve(__dirname, '../../src/main.js'),
			'utf8',
		)
		expect(main).toMatch(/installPageRefreshOnCreate\(window\)/)
	})
})
