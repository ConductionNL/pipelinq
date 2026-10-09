// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The user settings footer shows the installed version.
 *
 * `@nextcloud/vue` prints `<app> <appVersion>` under NcAppSettingsDialog, and
 * `appVersion` is whatever webpack.config.js defines. The cloud check of
 * 8 October 2026 read "pipelinq 0.1.0" (package.json) on 0.5.13-beta. This
 * spec evaluates the define the real webpack config hands to DefinePlugin,
 * once on a page that carries the `version` initial state and once on a page
 * without it.
 *
 * @spec openspec/changes/simple-tour-and-readable-labels/specs/navigation-ia/spec.md#requirement-the-user-settings-show-the-installed-app-version-req-nia-108
 * @spec openspec/changes/nextcloud-vue-2-73-1/specs/navigation-ia/spec.md
 */

import { createRequire } from 'module'
import path from 'path'
import { afterEach, describe, expect, it } from 'vitest'

const require = createRequire(import.meta.url)
const ROOT = path.resolve(__dirname, '../..')
const { readInfoXmlVersion } = require(
	path.join(ROOT, 'scripts', 'readInfoXmlVersion.js'),
)

/** The `appVersion` expression webpack.config.js hands to DefinePlugin. */
function definedAppVersion() {
	const config = require(path.join(ROOT, 'webpack.config.js'))
	const defines = config.plugins
		.filter((plugin) => plugin && plugin.definitions)
		.map((plugin) => plugin.definitions)
		.find((definitions) => 'appVersion' in definitions)
	return defines.appVersion
}

/**
 * Evaluate a define the way webpack pastes it into a module.
 *
 * @param {string} expression The define.
 * @return {unknown} Its value.
 */
function evaluate(expression) {
	return new Function(`return (${expression})`)()
}

/**
 * Render the hidden input IInitialState writes for one key.
 *
 * @param {string} key The initial-state key.
 * @param {unknown} value The value.
 */
function provideInitialState(key, value) {
	const input = document.createElement('input')
	input.type = 'hidden'
	input.id = `initial-state-pipelinq-${key}`
	input.value = btoa(JSON.stringify(value))
	document.body.appendChild(input)
}

afterEach(() => {
	document.body.innerHTML = ''
})

describe('the appVersion define', () => {
	it('reads the installed version from the page', () => {
		provideInitialState('version', '0.5.13-beta')
		expect(evaluate(definedAppVersion())).toBe('0.5.13-beta')
	})

	it('falls back to the info.xml version, never package.json', () => {
		const version = evaluate(definedAppVersion())
		// The control: info.xml names a version, and it is not package.json's.
		expect(readInfoXmlVersion()).toMatch(/^\d+\.\d+\.\d+/)
		expect(version).toBe(readInfoXmlVersion())
		expect(version).not.toBe(require(path.join(ROOT, 'package.json')).version)
	})

	it('comes from the library helper, not a copy of it', () => {
		// pipelinq#2318 carried its own copy of the helper until
		// @conduction/nextcloud-vue 2.73 shipped `appVersionDefine`. Swap the
		// library export for a spy and load the webpack config fresh: the
		// config must call the library, with the app id and the info.xml
		// version as the fallback.
		const library = require('@conduction/nextcloud-vue/webpack')
		const original = library.appVersionDefine
		const calls = []
		library.appVersionDefine = (...args) => {
			calls.push(args)
			return original(...args)
		}
		const configPath = require.resolve(path.join(ROOT, 'webpack.config.js'))
		const cached = require.cache[configPath]
		delete require.cache[configPath]
		try {
			const defines = require(configPath)
				.plugins.filter((plugin) => plugin && plugin.definitions)
				.map((plugin) => plugin.definitions)
				.find((definitions) => 'appVersion' in definitions)
			expect(calls).toEqual([['pipelinq', readInfoXmlVersion()]])
			expect(defines.appVersion).toBe(
				original('pipelinq', readInfoXmlVersion()),
			)
		} finally {
			library.appVersionDefine = original
			require.cache[configPath] = cached
		}
	})

	it('ignores an empty or broken initial state', () => {
		provideInitialState('version', '')
		expect(evaluate(definedAppVersion())).toBe(readInfoXmlVersion())
		document.body.innerHTML =
			'<input type="hidden" id="initial-state-pipelinq-version" value="%%%">'
		expect(evaluate(definedAppVersion())).toBe(readInfoXmlVersion())
	})
})
