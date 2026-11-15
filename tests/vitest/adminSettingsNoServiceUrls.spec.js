// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The admin page never asks for a Shillinq address (pipelinq-audit-admin-forms-pos, A5).
 * Shillinq is detected as an installed app and reached internally through
 * OpenRegister, so a webhook URL field only invites a wrong answer. The xWiki
 * test message no longer points at a direct URL field that is gone.
 *
 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/admin-settings/spec.md
 */

import { readFileSync } from 'fs'
import { resolve } from 'path'
import { describe, expect, it } from 'vitest'

const source = readFileSync(
	resolve(__dirname, '../../src/views/settings/Settings.vue'),
	'utf8',
)
const template = source.slice(0, source.indexOf('<script>'))

describe('admin settings ask for no Shillinq address', () => {
	it('has no Shillinq webhook URL field', () => {
		expect(template).not.toMatch(/shillinq_(wip|ap)_webhook_url/)
		expect(source).not.toMatch(/webhook URL'\)/)
	})

	it('shows the Shillinq options only when Shillinq is detected', () => {
		expect(template).toMatch(/v-if="isAdmin && shillinqInstalled"/)
		expect(source).toMatch(/'detected_integrations'/)
	})

	it('does not send the admin to a direct xWiki URL', () => {
		expect(source).not.toMatch(/Check the direct URL/)
	})
})
