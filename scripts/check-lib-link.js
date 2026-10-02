#!/usr/bin/env node
/*
 * Guard: @conduction/nextcloud-vue must come from the registry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Local library work links a sibling checkout with `npm i ../nextcloud-vue/`,
 * which rewrites package.json and package-lock.json to point at that folder.
 * Committed, that link builds against whatever happens to sit next to the app,
 * or fails outright where nothing does. Runs in CI as a frontend check.
 */
const fs = require('fs')
const path = require('path')

const LIB = '@conduction/nextcloud-vue'
const root = path.resolve(__dirname, '..')

const pkg = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'))
const lock = JSON.parse(
	fs.readFileSync(path.join(root, 'package-lock.json'), 'utf8'),
)

/**
 * @param {unknown} spec A dependency spec or lockfile `resolved` value.
 * @return {boolean} Whether it points at a local folder.
 */
function isLocal(spec) {
	return typeof spec === 'string' && /^(file:|link:|\.{0,2}\/)/.test(spec)
}

const problems = []

const declared = pkg.dependencies?.[LIB] ?? pkg.devDependencies?.[LIB]
if (isLocal(declared)) {
	problems.push(`package.json declares ${LIB} as "${declared}"`)
}

const locked = lock.packages?.[`node_modules/${LIB}`]
if (locked && (locked.link === true || isLocal(locked.resolved))) {
	problems.push(`package-lock.json resolves ${LIB} to "${locked.resolved}"`)
}

if (problems.length > 0) {
	console.error(
		`\n[check:lib-link] ${LIB} is linked to a local checkout:\n`
			+ problems.map((p) => `  - ${p}\n`).join('')
			+ `\n  Reinstall the registry version before committing: \`npm i ${LIB}@<version>\`.\n`,
	)
	process.exit(1)
}
