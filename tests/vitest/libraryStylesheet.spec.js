/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * nextcloud-vue's stylesheet is a linked file (css/nextcloud-vue.css, copied
 * by webpack.config.js), not CSS text inside the bundles. So every page that
 * loads the -shared-nc-vue chunk must link it, or its library components
 * render unstyled.
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-crm-dashboard-layout
 */

import { readFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(__dirname, '../..')
const read = (file) => readFileSync(join(root, file), 'utf8')

const loaders = [
	'templates/index.php',
	'templates/portal.php',
	'templates/settings/admin.php',
	'lib/Dashboard/AbstractPipelinqWidget.php',
]

describe('nextcloud-vue stylesheet', () => {
	it('is linked by every page that loads the shared nextcloud-vue chunk', () => {
		for (const file of loaders) {
			expect(read(file), file).toContain("'-shared-nc-vue'")
			expect(read(file), file).toMatch(/addStyle\([^)]*'nextcloud-vue'\)/)
		}
	})

	it('is no longer inserted from the bundles', () => {
		for (const file of ['src/main.js', 'src/settings.js']) {
			expect(read(file), file).not.toMatch(/import\s+'@conduction\/nextcloud-vue\/css\/index\.css'/)
		}
	})

	it('is copied to css/ by the build', () => {
		expect(read('webpack.config.js')).toContain("path.join(__dirname, 'css', 'nextcloud-vue.css')")
	})
})
