/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Importeren uit Nextcloud Contacten" is a secondary button beside "Nu
 * synchroniseren", as the board draws both (pl2 PqPersoonlijk: tertiary drew
 * it without an edge in the link ink). Read as text.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const src = readFileSync(resolve(__dirname, '../../src/components/settings/ContactsSyncSettingsSection.vue'), 'utf8')

describe('the contacts sync buttons', () => {
	it('draws the import as a secondary button', () => {
		expect(src).toMatch(/variant="secondary"\s+data-testid="contacts-sync-import"/)
	})
})
