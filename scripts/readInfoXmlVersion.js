/*
 * The version `appinfo/info.xml` names, for the `appVersion` fallback.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * webpack.config.js defines `appVersion` with `appVersionDefine` from
 * `@conduction/nextcloud-vue/webpack`. That expression reads the installed
 * version from the `version` initial state in the browser, and falls back to
 * the version the bundle was built from. This reads that fallback.
 *
 * @spec openspec/changes/nextcloud-vue-2-73-1/specs/navigation-ia/spec.md
 */

const fs = require('fs')
const path = require('path')

/**
 * The version `appinfo/info.xml` names.
 *
 * @param {string} [root] The app root. Defaults to this repository.
 * @return {string} The version, or '' when info.xml names none.
 * @spec openspec/changes/nextcloud-vue-2-73-1/specs/navigation-ia/spec.md
 */
function readInfoXmlVersion(root = path.resolve(__dirname, '..')) {
	const xml = fs.readFileSync(path.join(root, 'appinfo', 'info.xml'), 'utf8')
	const match = xml.match(/<version>\s*([^<\s]+)\s*<\/version>/)
	return match ? match[1] : ''
}

module.exports = { readInfoXmlVersion }
