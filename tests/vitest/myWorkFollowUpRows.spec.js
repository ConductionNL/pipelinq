// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * My work's follow-up rows say when a follow-up is planned (REQ-MW-120, board
 * PqMijnWerk): "planned 14:00" and "Follow-up today" for one planned today,
 * "Tomorrow" or "In 3 days" for one to come, and "2 days overdue" in the
 * danger tone for one past its deadline. The page config is read through the
 * library's own due text, row condition and template, as the record list does.
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-upcoming-follow-ups-read-at-a-glance-req-mw-120
 */

import { resolveDue } from '@conduction/nextcloud-vue/dist/esm/utils/dueText.js'
import { fillTemplate } from '@conduction/nextcloud-vue/dist/esm/utils/fillTemplate.js'
import { evaluateVisibleWhenLocal } from '@conduction/nextcloud-vue/dist/esm/utils/visibleWhen.js'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const page = manifest.pages.find((p) => p.id === 'MyWork')
const widget = page.config.widgets.find((w) => w.id === 'my-follow-ups')
const { meta, due } = widget.content

/** Saturday 21 November 2026, 09:00 in the reader's clock. */
const NOW = new Date(2026, 10, 21, 9, 0, 0)

/**
 * A local moment as the ISO string OpenRegister stores.
 *
 * @param {number} day The day of November 2026.
 * @param {number} hours The hour.
 * @param {number} minutes The minutes.
 * @return {string} The ISO string.
 */
function at(day, hours, minutes = 0) {
	return new Date(2026, 10, day, hours, minutes, 0).toISOString()
}

/**
 * The meta line the record list writes for a row.
 *
 * @param {object} row The follow-up.
 * @return {string} The meta line.
 */
function metaLine(row) {
	const found = meta.cases.find((entry) => evaluateVisibleWhenLocal(entry.when, row))
	return fillTemplate((found || meta).text, row, NOW)
}

describe('My work: follow-up rows (REQ-MW-120)', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.setSystemTime(NOW)
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('reads a call-back this afternoon as planned 14:00 and marks it Follow-up today', () => {
		const row = { subject: 'Call back about the parking permit', type: 'call', deadline: at(21, 14) }
		expect(metaLine(row)).toBe('call · planned 14:00')
		expect(resolveDue(row.deadline, due, (text) => text, NOW, row)).toEqual({ text: 'Follow-up today', tone: 'today' })
	})

	it('reads a follow-up tomorrow as Tomorrow, without a planned time', () => {
		const row = { type: 'call', deadline: at(22, 8, 30) }
		expect(metaLine(row)).toBe('call')
		expect(resolveDue(row.deadline, due, (text) => text, NOW, row).text).toBe('Tomorrow')
	})

	it('reads a follow-up due in three days as In 3 days', () => {
		const row = { type: 'email', deadline: at(24, 10) }
		expect(metaLine(row)).toBe('email')
		expect(resolveDue(row.deadline, due, (text) => text, NOW, row)).toEqual({ text: 'In 3 days', tone: 'upcoming' })
	})

	it('reads a missed follow-up as 2 days overdue in the danger tone', () => {
		const row = { type: 'call', deadline: at(19, 11) }
		expect(metaLine(row)).toBe('call')
		expect(resolveDue(row.deadline, due, (text) => text, NOW, row)).toEqual({ text: '2 days overdue', tone: 'danger' })
	})

	it('keeps a follow-up without a deadline on its type and No due date', () => {
		const row = { type: 'call' }
		expect(metaLine(row)).toBe('call')
		expect(resolveDue(undefined, due, (text) => text, NOW, row)).toEqual({ text: 'No due date', tone: 'empty' })
	})

	it('puts an overdue follow-up in the phone list\'s Overdue group', () => {
		const phone = page.config.widgets.find((w) => w.id === 'my-items-phone').content
		expect(phone.groupBy).toBe('dueGroup')
		expect(phone.valueLabels.dueGroup.late).toBe('Overdue')
		expect(phone.groupTones.late).toBe('error')
	})
})
