// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * My work's notification summary (REQ-MW-140): a header badge with the
 * user's unread pipelinq notifications, their list, read on open, nothing at
 * 0. The behaviour is the library's (nextcloud-vue `notificationsBadge`);
 * this pins the page's setting, that the notifications it counts are the
 * ones pipelinq sends, and that the installed library knows the key.
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-notification-summary-on-my-work-req-mw-140
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const page = manifest.pages.find((p) => p.id === 'MyWork')
const library = resolve(__dirname, '../../node_modules/@conduction/nextcloud-vue')
const info = readFileSync(resolve(__dirname, '../../appinfo/info.xml'), 'utf8')
const notifier = readFileSync(resolve(__dirname, '../../lib/Notification/Notifier.php'), 'utf8')

describe('My work: notification summary', () => {
	it('counts the notifications of this app in the header', () => {
		expect(page.config.notificationsBadge).toBe(true)
		// `true` reads the page's app id, which is the app id pipelinq notifies under.
		expect(info).toContain('<id>pipelinq</id>')
		expect(notifier).toMatch(/implements INotifier/)
	})

	it('runs on a library that knows notificationsBadge', () => {
		const schema = JSON.parse(readFileSync(resolve(library, 'src/schemas/app-manifest-v2.schema.json'), 'utf8'))
		expect(schema.$defs.page.properties.config.properties.notificationsBadge).toBeDefined()
		const nl = JSON.parse(readFileSync(resolve(library, 'l10n/nl.json'), 'utf8'))
		expect(nl.plurals['%n unread notification']).toEqual(['%n ongelezen melding', '%n ongelezen meldingen'])
	})
})
