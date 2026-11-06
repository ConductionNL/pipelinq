/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A resource's Delete sits in the header's More menu, not beside Edit, as the
 * board draws it (pl2 PqResource 'button Verwijderen: extra on live'). Read as
 * text.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const src = readFileSync(resolve(__dirname, '../../src/views/bookings/ResourceDetail.vue'), 'utf8')

describe('the resource detail header', () => {
	it('lists Delete in the More menu and keeps Edit a button', () => {
		expect(src).toContain(':headerActions="headerActions"')
		expect(src).toMatch(/id: 'delete',\s+label: t\('pipelinq', 'Delete'\)/)
		expect(src).not.toContain('<NcButton variant="tertiary" @click="showDelete = true">')
	})
})
