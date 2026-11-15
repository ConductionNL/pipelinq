/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for the task priority badge colour: the three priorities and every
 * other value, including ones that only look like a priority.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-49
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))

const { getTaskPriorityColor } = await import('../../src/services/taskUtils.js')

describe('getTaskPriorityColor', () => {
	it.each([
		['high', 'var(--color-error)'],
		['normal', 'var(--color-text-maxcontrast)'],
		['low', 'var(--color-text-lighter)'],
		['hoog', 'var(--color-text-maxcontrast)'],
		['High', 'var(--color-text-maxcontrast)'],
		[' high', 'var(--color-text-maxcontrast)'],
		['', 'var(--color-text-maxcontrast)'],
		[undefined, 'var(--color-text-maxcontrast)'],
		[null, 'var(--color-text-maxcontrast)'],
		[['high'], 'var(--color-text-maxcontrast)'],
		[new String('high'), 'var(--color-text-maxcontrast)'],
		['constructor', 'var(--color-text-maxcontrast)'],
		['toString', 'var(--color-text-maxcontrast)'],
		['__proto__', 'var(--color-text-maxcontrast)'],
		[0, 'var(--color-text-maxcontrast)'],
	])('%o answers %s', (priority, colour) => {
		expect(getTaskPriorityColor(priority)).toBe(colour)
	})
})
