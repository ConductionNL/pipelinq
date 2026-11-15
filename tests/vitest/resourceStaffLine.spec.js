/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The line under a resource's name reads the linked colleague's function and
 * department from their Nextcloud profile, as the PqResource board draws it
 * ("Woo-adviseur · Woo en informatie"). Read as text.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const src = readFileSync(resolve(__dirname, '../../src/views/bookings/ResourceDetail.vue'), 'utf8')
const routes = readFileSync(resolve(__dirname, '../../appinfo/routes.php'), 'utf8')

describe('the resource header line', () => {
	it('reads the linked user\'s profile and passes function and department to the header', () => {
		expect(src).toContain(':headerMeta="headerMeta"')
		expect(src).toContain("generateUrl('/apps/pipelinq/api/staff/{userId}/profile', { userId })")
		expect(src).toMatch(/\[this\.staffLine\.function, this\.staffLine\.department\]/)
		expect(routes).toContain("'url' => '/api/staff/{userId}/profile'")
	})
})
