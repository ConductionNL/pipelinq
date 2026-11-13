// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The admin settings entry (src/settings.js).
 *
 * Covers:
 *  - the library catalogue and the Integrations tab icons are registered.
 *  - the app gets t/n, a cnTranslate bound to the pipelinq catalogue and Pinia.
 *  - the page mounts only after the object types are registered.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
	registerIcons: vi.fn(),
	registerTranslations: vi.fn(),
	initializeStores: vi.fn(),
	translate: vi.fn((app, text) => `${app}:${text}`),
	createApp: vi.fn(),
}))

vi.mock('@conduction/nextcloud-vue', () => ({ registerIcons: mocks.registerIcons, registerTranslations: mocks.registerTranslations }))
vi.mock('@conduction/nextcloud-vue/css/index.css', () => ({}))
vi.mock('@nextcloud/l10n', () => ({ translate: mocks.translate, translatePlural: vi.fn() }))
vi.mock('vue', async (importOriginal) => ({ ...(await importOriginal()), createApp: mocks.createApp }))
vi.mock('../../src/views/settings/Settings.vue', () => ({ default: { name: 'Settings' } }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: mocks.initializeStores }))

function freshApp() {
	const app = { mixin: vi.fn(), provide: vi.fn(), use: vi.fn(), mount: vi.fn() }
	mocks.createApp.mockReturnValue(app)
	return app
}

async function load() {
	vi.resetModules()
	await import('../../src/settings.js')
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('settings entry', () => {
	it('wires the app before mounting it', async () => {
		let resolveStores
		mocks.initializeStores.mockReturnValue(new Promise((r) => { resolveStores = r }))
		const app = freshApp()
		await load()

		expect(mocks.createApp).toHaveBeenCalledWith({ name: 'Settings' })
		expect(mocks.registerTranslations).toHaveBeenCalledTimes(1)
		expect(Object.keys(mocks.registerIcons.mock.calls[0][0]).sort()).toEqual(
			['EmailFastOutline', 'EmailOutline', 'Headset', 'PowerPlugOutline', 'ShareVariant'],
		)
		const { methods } = app.mixin.mock.calls[0][0]
		expect(Object.keys(methods).sort()).toEqual(['n', 't'])

		const [key, cnTranslate] = app.provide.mock.calls[0]
		expect(key).toBe('cnTranslate')
		expect(cnTranslate('Status')).toBe('pipelinq:Status')

		expect(app.use).toHaveBeenCalledTimes(1)
		const { default: pinia } = await import('../../src/pinia.js')
		expect(app.use).toHaveBeenCalledWith(pinia)
		expect(app.mount).not.toHaveBeenCalled()

		resolveStores()
		await Promise.resolve()
		await Promise.resolve()
		expect(app.mount).toHaveBeenCalledWith('#pipelinq-settings')
	})
})
