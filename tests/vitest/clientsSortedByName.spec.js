/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client list opens sorted by name, A to Z, as board PqOrganisaties
 * draws it: the Naam head is the sorted column and the rows run
 * alphabetically.
 *
 * @spec openspec/changes/clients-sorted-by-name/specs/client-management/spec.md#requirement-the-client-list-opens-sorted-by-name
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '..', '..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))

describe('client list sort', () => {
	it('sorts the Clients page by name, ascending', () => {
		const clients = manifest.pages.find((p) => p.id === 'Clients' && p.route === '/clients')
		expect(clients.config.sortKey).toBe('name')
		expect(clients.config.sortOrder).toBe('asc')
	})
})
