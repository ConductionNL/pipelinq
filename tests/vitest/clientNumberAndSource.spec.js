/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client's identity card names the customer number and the source of
 * the customer's data, as the PqKlant board draws them.
 *
 * @spec openspec/specs/client-management/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (path) => JSON.parse(readFileSync(resolve(__dirname, '../..', path), 'utf8'))

describe('client number and source', () => {
	it('declares both on the client', () => {
		const schema = read('lib/Settings/register.d/94-client-number-and-source.json').components.schemas.client
		expect(schema.version).toBe('1.3.0')
		expect(schema.properties.customerNumber.type).toBe('string')
		expect(schema.properties.source.type).toBe('string')
	})

	it('shows both on the identity card', () => {
		const page = read('src/manifest.json').pages.find((p) => p.id === 'ClientDetail')
		const identity = page.config.widgets.find((w) => w.id === 'client-identity')
		expect(identity.content.include).toEqual(expect.arrayContaining(['customerNumber', 'source']))
	})
})
