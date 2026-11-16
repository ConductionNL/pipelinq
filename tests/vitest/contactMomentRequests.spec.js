// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A contact moment is filed on an open request
 * (openspec/changes/specify-capability-rows/specs/contactmomenten/spec.md).
 */

import { describe, expect, it } from 'vitest'

globalThis.t = (app, text) => text

const { openRequestOptions } = await import('../../src/services/contactMomentRequests.js')
const { buildContactMoment } = await import('../../src/services/contactMomentDraft.js')

const requests = [
	{ id: 'parking', title: 'Parking permit', client: 'jansen', status: 'in_progress' },
	{ id: 'waste', title: 'Waste container', client: 'jansen', status: 'completed' },
	{ id: 'other', title: 'Building permit', client: 'pietersen', status: 'new' },
	{ id: 'fresh', title: 'Fresh question', client: { id: 'jansen' } },
	{ title: 'No id' },
]

describe('the Request picker', () => {
	it('offers only the open requests of the chosen client', () => {
		expect(openRequestOptions(requests, 'jansen')).toEqual([
			{ value: 'parking', label: 'Parking permit' },
			{ value: 'fresh', label: 'Fresh question' },
		])
	})

	it('never offers a closed request', () => {
		const offered = openRequestOptions(requests, 'jansen').map((o) => o.value)
		expect(offered).not.toContain('waste')
	})

	it('offers every open request while no client is chosen', () => {
		expect(openRequestOptions(requests, null).map((o) => o.value)).toEqual(['parking', 'other', 'fresh'])
	})

	it('reads a client given as an object', () => {
		expect(openRequestOptions(requests, { id: 'pietersen' }).map((o) => o.value)).toEqual(['other'])
	})

	it('keeps the request already picked, even when it is closed', () => {
		expect(openRequestOptions(requests, 'jansen', 'waste').map((o) => o.value)).toEqual(['parking', 'waste', 'fresh'])
	})
})

describe('saving with a request picked', () => {
	it('stores the contact moment on that request and creates no new request', () => {
		const saved = buildContactMoment(
			{ title: 'Called again', channel: 'telefoon', client: 'jansen', parentTicket: 'parking' },
			{ author: 'agent', now: new Date('2026-11-13T10:00:00Z') },
		)
		expect(saved.parentTicket).toBe('parking')
		expect(saved.ticketType).toBe('interaction')
		expect(saved.ticketType).not.toBe('request')
	})
})
