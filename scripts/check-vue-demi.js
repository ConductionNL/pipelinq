#!/usr/bin/env node
/*
 * Guard: vue-demi must be on its Vue 3 shim.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * `vue-demi` arrives transitively via `pinia@2` and picks its Vue 2 or Vue 3
 * shim in a **postinstall hook**. Two ways it silently stays on the Vue 2 one:
 *
 *   - the repo's own `postinstall` looks dead and gets deleted — it is not;
 *   - `npm install` does NOT re-run a postinstall for an already-present
 *     version (only `npm ci` reliably does).
 *
 * When it stays wrong, `pinia` imports a `default` export that `vue@3` does not
 * have. On zaakafhandelapp that was ~25 build errors and 12 of 23 Jest suites
 * down, with nothing in any message naming `vue-demi`.
 *
 * Runs automatically as `prebuild`.
 */
const fs = require('fs')
const path = require('path')

const shim = path.resolve(__dirname, '..', 'node_modules', 'vue-demi', 'lib', 'index.mjs')

if (!fs.existsSync(shim)) {
	// Not installed at all is fine — nothing can be on the wrong shim.
	process.exit(0)
}

const src = fs.readFileSync(shim, 'utf8')
const isVue3Shim = src.includes('import * as Vue') && src.includes('isVue2 = false')

if (!isVue3Shim) {
	console.error(
		'\n[check:vue-demi] vue-demi is still on its Vue 2 shim.\n'
		+ `  ${shim}\n`
		+ '  Expected it to contain both `import * as Vue` and `isVue2 = false`.\n\n'
		+ '  vue-demi selects its shim in a postinstall hook, and `npm install`\n'
		+ '  does not re-run a postinstall for an already-present version. Run\n'
		+ '  `npm ci` (postinstalls always re-run) and try again.\n\n'
		+ '  Building past this produces ~25 unrelated-looking module errors and\n'
		+ '  a broken pinia, none of which mention vue-demi.\n',
	)
	process.exit(1)
}
