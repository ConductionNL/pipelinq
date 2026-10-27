/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The line under a resource's name reads its job title and department, as
 * the PqResource board draws it ("baliemedewerker · burgerzaken"). Read as
 * text.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const src = readFileSync(resolve(__dirname, '../../src/views/bookings/ResourceDetail.vue'), 'utf8')
const fragment = JSON.parse(readFileSync(resolve(__dirname, '../../lib/Settings/register.d/99-zz-resource-job-title.json'), 'utf8'))

describe('the resource header line', () => {
	it('passes the job title and department to the header', () => {
		expect(src).toContain(':headerMeta="headerMeta"')
		expect(src).toMatch(/\[this\.resourceData\.jobTitle, this\.resourceData\.department\]/)
		expect(src).toContain(".join(' · ')")
	})

	it('declares both fields on the resource schema under a new version', () => {
		const schema = fragment.components.schemas.appointmentResource
		expect(schema.version).toBe('1.1.0')
		expect(Object.keys(schema.properties)).toEqual(['jobTitle', 'department'])
	})
})
