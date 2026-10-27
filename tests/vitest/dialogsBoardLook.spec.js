/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every pipelinq dialog is a CnDialog, so on a board page it is the board
 * dialog (eyebrow, title, 40px fields and buttons, shadow) and in the
 * Nextcloud look it is NcDialog as before. A dialog drawn on NcDialog
 * directly looks like no board.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const SRC = resolve(__dirname, '../../src')

/** Dialogs another lane is converting (PQ-L3), until they land. */
const IN_FLIGHT = ['dialogs/SegmentFormDialog.vue', 'dialogs/TicketAnswerDialog.vue']

/**
 * Every .vue file under a folder.
 *
 * @param {string} dir The folder.
 * @return {string[]} The paths.
 */
function vueFiles(dir) {
	return readdirSync(dir).flatMap((name) => {
		const path = join(dir, name)
		return statSync(path).isDirectory() ? vueFiles(path) : (name.endsWith('.vue') ? [path] : [])
	})
}

describe('pipelinq dialogs', () => {
	it('draw on CnDialog, not on NcDialog', () => {
		const direct = vueFiles(SRC)
			.filter((path) => readFileSync(path, 'utf8').includes('<NcDialog'))
			.map((path) => relative(SRC, path))
			.filter((path) => !IN_FLIGHT.includes(path))
		expect(direct).toEqual([])
	})
})
