/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The next step's button is text only, as the PqTicket and PqTaak boards
 * (and the DqZaak boards for Next step) draw it. The page draws the stage
 * action's own icon, so the action declares none.
 *
 * @spec openspec/specs/declarative-view-system/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, 'src', file), 'utf8'))
const manifest = read('manifest.json')
const simple = read('menu-layout.simple.json')

describe('next step button', () => {
	it('answers the customer without an icon', () => {
		const config = simple.pages.find((p) => p.id === 'TicketDetail').config
		const answer = config.headerActions.find((action) => action.id === 'ticket-answer')
		expect(config.primaryActionByStage.in_progress).toBe('ticket-answer')
		expect(answer.icon).toBeUndefined()
	})

	it('calls the number without an icon', () => {
		const config = manifest.pages.find((p) => p.id === 'TaskDetail').config
		for (const stage of ['open', 'in_progress']) {
			expect(config.primaryActionByStage[stage].id).toBe('task-call-next')
			expect(config.primaryActionByStage[stage].icon).toBeUndefined()
		}
	})
})
