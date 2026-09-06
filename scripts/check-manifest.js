#!/usr/bin/env node
/**
 * Validate src/manifest.json against the Conduction app-manifest schema.
 *
 * Uses validateManifest from @conduction/nextcloud-vue when available
 * (per ADR-024), falling back to a structural guard that checks for the
 * required top-level fields.
 *
 * @spec openspec/changes/time-entry-core/tasks.md#task-3.1
 */
'use strict'

const fs = require('fs')
const path = require('path')

const manifestPath = path.resolve(__dirname, '../src/manifest.json')
const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'))

// --- 1. Try library validateManifest (ADR-024 preferred path) ----------
try {
	const lib = require('@conduction/nextcloud-vue')
	if (typeof lib.validateManifest === 'function') {
		const result = lib.validateManifest(manifest)
		if (!result.valid) {
			console.error('Manifest validation FAILED (lib):')
			;(result.errors || []).forEach((e) => console.error(' -', e))
			process.exit(1)
		}
		console.log(
			`Manifest OK (lib): v${manifest.version} | ${manifest.pages.length} pages`,
		)
		process.exit(0)
	}
} catch {
	// Library not yet available or does not export validateManifest.
}

// --- 2. Structural guard (fallback) ------------------------------------
const errors = []
if (!manifest.version) errors.push('Missing: version')
if (!Array.isArray(manifest.pages) || manifest.pages.length === 0)
	errors.push('Missing or empty: pages[]')
if (!Array.isArray(manifest.menu) || manifest.menu.length === 0)
	errors.push('Missing or empty: menu[]')
if (!Array.isArray(manifest.dependencies)) errors.push('Missing: dependencies[]')
else if (!manifest.dependencies.includes('openregister'))
	errors.push('Missing "openregister" in dependencies[]')

if (errors.length > 0) {
	console.error('Manifest validation FAILED:')
	errors.forEach((e) => console.error(' -', e))
	process.exit(1)
}

console.log(
	`Manifest OK: v${manifest.version} | ${manifest.pages.length} pages | ${manifest.menu.length} menu items | deps: ${(manifest.dependencies || []).join(', ')}`,
)
