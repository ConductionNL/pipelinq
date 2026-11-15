// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The public portal entry (src/portal.js).
 *
 * Covers:
 *  - the router is built in history mode on the portal base taken from the
 *    current path, or from generateUrl when the path does not name it, and
 *    gets the portal guard.
 *  - on DOMContentLoaded the app mounts inside the server's host element, or
 *    inside a created one with the same id, with t/n and the router installed.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
	createWebHistory: vi.fn((base) => ({ base })),
	createRouter: vi.fn((options) => ({ options, install: vi.fn() })),
	installPortalGuard: vi.fn(),
	app: null,
	createApp: vi.fn(),
}))

vi.mock('vue-router', () => ({ createRouter: mocks.createRouter, createWebHistory: mocks.createWebHistory }))
vi.mock('vue', async (importOriginal) => ({ ...(await importOriginal()), createApp: mocks.createApp }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => `/index.php${p}` }))
vi.mock('../../src/portal/PortalApp.vue', () => ({ default: { name: 'PortalApp' } }))
vi.mock('../../src/portal/portalRoutes.js', () => ({ portalRoutes: [{ path: '/' }], installPortalGuard: mocks.installPortalGuard }))
vi.mock('../../src/assets/app.css', () => ({}))

function freshApp() {
	const app = { config: { globalProperties: {} }, use: vi.fn(), mount: vi.fn() }
	app.use.mockReturnValue(app)
	mocks.createApp.mockReturnValue(app)
	return app
}

async function loadAt(pathname) {
	window.history.replaceState({}, '', pathname)
	vi.resetModules()
	await import('../../src/portal.js')
}

let listeners

beforeEach(() => {
	vi.clearAllMocks()
	document.body.innerHTML = ''
	listeners = []
	const add = document.addEventListener.bind(document)
	vi.spyOn(document, 'addEventListener').mockImplementation((type, fn, opts) => {
		listeners.push([type, fn])
		add(type, fn, opts)
	})
})

afterEach(() => {
	for (const [type, fn] of listeners) {
		document.removeEventListener(type, fn)
	}
	vi.restoreAllMocks()
	window.history.replaceState({}, '', '/')
})

describe('portal entry', () => {
	it('bases the router on the portal prefix in the current path', async () => {
		await loadAt('/nc/index.php/apps/pipelinq/portal/services/42')
		expect(mocks.createWebHistory).toHaveBeenCalledWith('/nc/index.php/apps/pipelinq/portal')
		const [{ routes, history }] = mocks.createRouter.mock.calls[0]
		expect(routes).toEqual([{ path: '/' }])
		expect(history).toEqual({ base: '/nc/index.php/apps/pipelinq/portal' })
		expect(mocks.installPortalGuard).toHaveBeenCalledWith(mocks.createRouter.mock.results[0].value)
	})

	it('accepts the bare portal path', async () => {
		await loadAt('/apps/pipelinq/portal')
		expect(mocks.createWebHistory).toHaveBeenCalledWith('/apps/pipelinq/portal')
	})

	it('falls back to the generated portal URL elsewhere', async () => {
		await loadAt('/apps/pipelinq/portalish')
		expect(mocks.createWebHistory).toHaveBeenCalledWith('/index.php/apps/pipelinq/portal')
	})

	it('mounts inside the server-rendered host element', async () => {
		const host = document.createElement('div')
		host.id = 'pipelinq-portal'
		document.body.appendChild(host)
		const app = freshApp()
		await loadAt('/apps/pipelinq/portal')

		document.dispatchEvent(new Event('DOMContentLoaded'))

		expect(mocks.createApp).toHaveBeenCalledWith({ name: 'PortalApp' })
		expect(typeof app.config.globalProperties.t).toBe('function')
		expect(typeof app.config.globalProperties.n).toBe('function')
		expect(app.use).toHaveBeenCalledWith(mocks.createRouter.mock.results[0].value)
		expect(app.mount).toHaveBeenCalledTimes(1)
		expect(app.mount).toHaveBeenCalledWith(host)
		expect(document.querySelectorAll('#pipelinq-portal')).toHaveLength(1)
	})

	it('creates the host element when the page has none', async () => {
		const app = freshApp()
		await loadAt('/apps/pipelinq/portal')

		document.dispatchEvent(new Event('DOMContentLoaded'))

		const host = document.getElementById('pipelinq-portal')
		expect(host).not.toBeNull()
		expect(host.parentElement).toBe(document.body)
		expect(app.mount).toHaveBeenCalledWith(host)
	})
})
