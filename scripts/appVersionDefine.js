/*
 * The `appVersion` define, read in the browser from the installed app.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * `@nextcloud/vue` prints `<app name> <appVersion>` in the footer of every
 * NcAppSettingsDialog, so also in pipelinq's user settings. It reads
 * `appVersion` as a global that webpack defines. pipelinq defined it from
 * `package.json`, a `0.1.0` nobody bumps, so the cloud showed "pipelinq 0.1.0"
 * on 0.5.13-beta. Reading `appinfo/info.xml` at build time is not enough
 * either: the release workflow writes the release version into info.xml AFTER
 * it builds the bundle, so the footer would name the previous version.
 *
 * So `appVersion` is an EXPRESSION. It runs once in the browser and reads the
 * `version` initial state that DashboardController (and the admin settings
 * page) provide from what Nextcloud has installed. It reads the hidden input
 * `@nextcloud/initial-state` reads; it cannot import that package, because a
 * define is pasted into every module as code, `.mjs` modules of
 * `@nextcloud/vue` included. A page without that state (a dashboard widget, a
 * test) shows the version the bundle was built from.
 *
 * nextcloud-vue is adding the same helper (`appVersionDefine` in
 * `@conduction/nextcloud-vue/webpack`). Once pipelinq depends on a release
 * that ships it, this file can go.
 *
 * @spec openspec/changes/simple-tour-and-readable-labels/specs/navigation-ia/spec.md#requirement-the-user-settings-show-the-installed-app-version-req-nia-108
 */

const fs = require('fs')
const path = require('path')

/**
 * The version `appinfo/info.xml` names.
 *
 * @param {string} [root] The app root. Defaults to this repository.
 * @return {string} The version, or '' when info.xml names none.
 */
function readInfoXmlVersion(root = path.resolve(__dirname, '..')) {
	const xml = fs.readFileSync(path.join(root, 'appinfo', 'info.xml'), 'utf8')
	const match = xml.match(/<version>\s*([^<\s]+)\s*<\/version>/)
	return match ? match[1] : ''
}

/**
 * A JavaScript expression for `webpack.DefinePlugin` that evaluates to the
 * installed app version.
 *
 * @param {string} appId The app id, as used for its initial state.
 * @param {string} [fallback] The version to show without initial state.
 * @return {string} The expression.
 */
function appVersionDefine(appId, fallback = '') {
	const id = String(appId || '').trim()
	if (id === '') {
		throw new TypeError('appVersionDefine() needs the app id')
	}
	const elementId = JSON.stringify(`initial-state-${id}-version`)
	const fallbackLiteral = JSON.stringify(String(fallback ?? ''))
	return (
		'(function () {'
		+ ' try {'
		+ ` var el = typeof document !== "undefined" && document.getElementById(${elementId});`
		+ ' if (el && el.value) {'
		+ ' var v = JSON.parse(atob(el.value));'
		+ ' if (typeof v === "string" && v !== "") { return v; }'
		+ ' }'
		+ ' } catch (e) {}'
		+ ` return ${fallbackLiteral};`
		+ ' })()'
	)
}

module.exports = { appVersionDefine, readInfoXmlVersion }
