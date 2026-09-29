/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * My work as a phone list: today's work first (platform-phone-on-the-road).
 * The last spec pins the caller: MyWork.vue groups with this helper, lists
 * follow-up tasks, and has a phone layout.
 *
 * @spec openspec/changes/platform-phone-on-the-road/specs/mobile-experience/spec.md#requirement-my-work-works-as-a-phone-list-req-mob-002
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { GROUP_ORDER, workGroup } from '../../src/services/myWorkGroups.js'

const today = new Date(2026, 8, 30)
const weekEnd = new Date(2026, 9, 4, 23, 59, 59)

describe('my work groups', () => {
	it('puts today first in the display order', () => {
		expect(GROUP_ORDER[0]).toBe('due-today')
	})

	it.each([
		[new Date(2026, 8, 30, 9, 0), 'due-today'],
		[new Date(2026, 8, 30, 23, 30), 'due-today'],
		[new Date(2026, 8, 29, 17, 0), 'overdue'],
		[new Date(2026, 9, 2, 9, 0), 'due-this-week'],
		[new Date(2026, 9, 12, 9, 0), 'upcoming'],
		[null, 'no-due-date'],
	])('%s lands in %s', (due, group) => {
		expect(workGroup(due, today, weekEnd, false)).toBe(group)
	})

	it('never files a closed item as due', () => {
		expect(workGroup(new Date(2026, 8, 30, 9, 0), today, weekEnd, true)).toBe(
			'no-due-date',
		)
	})

	it('two follow-ups due today sort above five later this week', () => {
		const dues = [
			new Date(2026, 9, 1, 9),
			new Date(2026, 9, 2, 9),
			new Date(2026, 8, 30, 14),
			new Date(2026, 9, 3, 9),
			new Date(2026, 9, 1, 11),
			new Date(2026, 8, 30, 9),
			new Date(2026, 9, 4, 9),
		]
		const groups = dues.map((d) => workGroup(d, today, weekEnd, false))
		const ordered = [...groups].sort(
			(a, b) => GROUP_ORDER.indexOf(a) - GROUP_ORDER.indexOf(b),
		)
		expect(ordered.slice(0, 2)).toEqual(['due-today', 'due-today'])
		expect(ordered.slice(2).every((g) => g === 'due-this-week')).toBe(true)
	})
})

describe('the My work page', () => {
	const source = readFileSync(
		resolve(__dirname, '../../src/views/MyWork.vue'),
		'utf8',
	)

	it('groups with workGroup and shows the groups in GROUP_ORDER', () => {
		expect(source).toMatch(/workGroup\(/)
		expect(source).toMatch(/GROUP_ORDER/)
	})

	it('lists the follow-up tasks assigned to the user', () => {
		expect(source).toMatch(/fetchRaw\('crmTask'/)
		expect(source).toMatch(/assigneeUserId: this\.currentUser/)
	})

	it('has a one-column phone layout with 44 pixel targets', () => {
		expect(source).toMatch(/@media \(max-width: 600px\)/)
		expect(source).toMatch(/min-height: 44px/)
	})
})
