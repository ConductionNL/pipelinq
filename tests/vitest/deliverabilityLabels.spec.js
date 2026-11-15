// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for src/utils/deliverabilityLabels.js — the pure label/verdict
 * mapping the marketing-mail-transports deliverability panel
 * (DeliverabilitySettings.vue) uses to render transport kind labels and
 * SPF/DKIM/DMARC verdicts.
 */

import { describe, expect, it } from 'vitest'
import {
	dkimBadgeClass,
	dmarcBadgeClass,
	dmarcVerdictText,
	kindLabel,
} from '../../src/utils/deliverabilityLabels.js'

describe('kindLabel', () => {
	it('labels the three known transport kinds', () => {
		expect(kindLabel('instance')).toBe('Instance mail server')
		expect(kindLabel('mailAccount')).toBe('Mail account')
		expect(kindLabel('provider')).toBe('Bulk provider')
	})

	it('falls back to the raw kind for an unknown value', () => {
		expect(kindLabel('something-else')).toBe('something-else')
	})

	it('returns an empty string for an empty/missing kind', () => {
		expect(kindLabel('')).toBe('')
		expect(kindLabel(undefined)).toBe('')
	})
})

describe('dmarcVerdictText', () => {
	it('names the plain-language verdict for each known status', () => {
		expect(dmarcVerdictText('found')).toBe('DMARC found')
		expect(dmarcVerdictText('missing')).toBe(
			'DMARC missing: bulk senders to Gmail are rejected without it',
		)
		expect(dmarcVerdictText('invalid')).toBe(
			'DMARC record present but malformed',
		)
		expect(dmarcVerdictText('unknown')).toBe('DMARC not checked yet')
	})

	it('falls back to the unknown verdict for an unrecognised status', () => {
		expect(dmarcVerdictText('garbage')).toBe('DMARC not checked yet')
		expect(dmarcVerdictText(undefined)).toBe('DMARC not checked yet')
	})
})

describe('dkimBadgeClass', () => {
	it('is the success tone when DKIM was found', () => {
		expect(dkimBadgeClass(true)).toBe('cn-status-badge--success')
	})

	it('is the error tone when DKIM was not found, as the board draws "DKIM missing"', () => {
		expect(dkimBadgeClass(false)).toBe('cn-status-badge--error')
	})
})

describe('dmarcBadgeClass', () => {
	it('is the success tone for a found record', () => {
		expect(dmarcBadgeClass('found')).toBe('cn-status-badge--success')
	})

	it('is the warning tone for an unknown/uncached status', () => {
		expect(dmarcBadgeClass('unknown')).toBe('cn-status-badge--warning')
	})

	it('is the neutral tone for missing or invalid', () => {
		expect(dmarcBadgeClass('missing')).toBe('cn-status-badge--default')
		expect(dmarcBadgeClass('invalid')).toBe('cn-status-badge--default')
	})
})
