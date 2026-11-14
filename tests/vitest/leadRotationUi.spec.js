/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lead rotation in the interface: the admin switch, the cap and pause an
 * agent profile saves, and the reason on the lead page.
 *
 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { profileToSave } from '../../src/utils/agentProfileForm.js'

const read = (path) => JSON.parse(readFileSync(resolve(__dirname, '../..', path), 'utf8'))

describe('profileToSave', () => {
	it('keeps a whole cap of at least 1 and makes pause a boolean', () => {
		expect(profileToSave({ userId: 'anna', leadCapPer30Days: '12', pauseLeadAssignment: true }))
			.toEqual({ userId: 'anna', leadCapPer30Days: 12, pauseLeadAssignment: true })
	})

	it.each([[''], [null], [0], [-2], [2.5], ['many'], [undefined]])('drops the cap %p, which means no cap', (cap) => {
		const saved = profileToSave({ userId: 'anna', leadCapPer30Days: cap })
		expect(saved).toEqual({ userId: 'anna' })
	})

	it('saves a pause that is not exactly true as false, and leaves a profile without either key as it is', () => {
		expect(profileToSave({ userId: 'anna', pauseLeadAssignment: 'yes' })).toEqual({ userId: 'anna', pauseLeadAssignment: false })
		expect(profileToSave({ id: 'p1', userId: 'anna', skills: ['s1'], isAvailable: true })).toEqual({ id: 'p1', userId: 'anna', skills: ['s1'], isAvailable: true })
	})

	it('does not change the form it was given', () => {
		const form = { userId: 'anna', leadCapPer30Days: '' }
		profileToSave(form)
		expect(form).toEqual({ userId: 'anna', leadCapPer30Days: '' })
	})
})

describe('admin switch', () => {
	it('lists lead_rotation_enabled as a true/false switch under lead management', () => {
		const field = read('src/views/settings/adminSections.json').leadManagement.fields
			.find((f) => f.key === 'lead_rotation_enabled')
		expect(field).toMatchObject({ type: 'switch', trueValue: 'true', falseValue: 'false' })
		expect(field.label).toBe('Assign new leads in turn')
	})
})

describe('lead page', () => {
	it('shows why the lead got its owner in a side card, only when rotation wrote a reason', () => {
		const page = read('src/manifest.json').pages.find((p) => p.id === 'LeadDetail')
		const card = page.config.widgets.find((w) => w.id === 'side-assignment')
		expect(card).toMatchObject({
			type: 'facts',
			title: 'Why this owner',
			visibleWhen: { field: 'assignmentReason', op: 'notEmpty' },
			content: { fields: [{ as: 'line', template: '{assignmentReason}' }] },
		})
		const side = page.config.sideColumn
		expect(side.indexOf('side-assignment')).toBe(side.indexOf('side-suggested') - 1)
	})
})
