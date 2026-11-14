/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The operational dashboard's "My work" card as the PqOperationeel board
 * draws it: rows with the kind and how the due date stands, and a pill that
 * says Late, Today or This week.
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { dueState } from '../../src/utils/dueState.js'

// Wednesday 21 October 2026.
const now = new Date(2026, 9, 21, 10, 0)

describe('a due date against today', () => {
	it('is late by whole days', () => {
		expect(dueState('2026-10-20', now)).toEqual({ state: 'overdue', daysLate: 1 })
		expect(dueState('2026-10-17T09:00:00', now)).toEqual({ state: 'overdue', daysLate: 4 })
	})

	it('is today, this week up to Sunday, or later', () => {
		expect(dueState('2026-10-21T13:00:00', now).state).toBe('today')
		expect(dueState('2026-10-25', now).state).toBe('week')
		expect(dueState('2026-10-26', now).state).toBe('later')
	})

	it('has no state without a date', () => {
		expect(dueState(null, now)).toEqual({ state: null, daysLate: 0 })
		expect(dueState('not a date', now)).toEqual({ state: null, daysLate: 0 })
	})
})

describe('the My work card', () => {
	const src = readFileSync(resolve(__dirname, '../../src/views/dashboard/widgets/MyWorkWidget.vue'), 'utf8')

	it('draws rows with a due pill instead of a table', () => {
		expect(src).not.toContain('<CnDataTable')
		expect(src).toContain('<CnStatusBadge')
		expect(src).toMatch(/label: this\.t\('pipelinq', 'Late'\), variant: 'error'/)
		expect(src).toMatch(/label: this\.t\('pipelinq', 'This week'\), variant: 'warning'/)
	})
})
