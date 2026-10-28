/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The user chips in pipelinq's tables draw at 28px, as the Dq tables
 * (DqTaken, DqFilter*) do: the Tickets handler and the programmes owner.
 *
 * @spec exclude board measure (pl3 PqTickets avatars 28 vs 32), configuration only
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, 'src', ...parts), 'utf8'))

describe('table user chips draw at 28px', () => {
	it('Tickets handler', () => {
		const page = read('menu-layout.simple.json').pages.find((p) => p.id === 'Tickets')
		const column = page.config.columns.find((c) => c.key === 'assignee')
		expect(column.widget).toBe('avatar')
		expect(column.widgetProps.size).toBe(28)
	})

	it('programmes owner', () => {
		const text = JSON.stringify(read('manifest.d', '83-programmes.json'))
		const owner = JSON.parse(text.match(/\{"key":"owner","label":"Owner","widget":"avatar","widgetProps":\{[^}]*\}\}/)[0])
		expect(owner.widgetProps.size).toBe(28)
	})
})
