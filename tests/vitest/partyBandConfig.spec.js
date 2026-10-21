// @vitest-environment node
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The warnings and quick actions on a client and a contact page are one
 * nextcloud-vue `party-actions` band before the body (pipelinq#2036,
 * platform-phone-on-the-road). The server orders the warnings loudest first
 * (PartyIndicatorServiceTest) and dials from the agent's own extension
 * (CtiControllerTest); the drawing, the acknowledgement, the load error and
 * the tel/mailto/geo links are nextcloud-vue's (CnPartyActionsWidgetEndpoint).
 * This file pins the config.
 *
 * @spec openspec/changes/party-band-as-configuration/specs/party-fields-and-indicators/spec.md#requirement-a-party-page-shows-its-warnings-and-quick-actions-in-one-band
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (...parts) => readFileSync(resolve(__dirname, '../..', ...parts), 'utf8')
const manifest = JSON.parse(read('src', 'manifest.json'))
const band = (pageId) => manifest.pages.find((p) => p.id === pageId).config.bodyWidgets.find((w) => w.id === 'party-indicators')

describe.each(['ClientDetail', 'ContactDetail'])('the party band on %s', (pageId) => {
	const widget = band(pageId)

	it('sits before the body as a party-actions band', () => {
		expect(widget).toMatchObject({ type: 'party-actions', placement: 'before-body' })
		expect(widget.component).toBeUndefined()
	})

	it('reads the warnings through the party leaf, with severity, period, source and acknowledgement', () => {
		const source = widget.content.indicatorSource
		expect(source).toMatchObject({ url: '/apps/pipelinq/api/leaves/party/@objectId', itemsPath: 'indicators', tonePath: 'severity' })
		expect(source.toneMap).toEqual({ critical: 'error', warning: 'warning', info: 'info' })
		expect(source.ack).toMatchObject({ whenPath: 'requiresAcknowledgement', donePath: 'acknowledgedAt', endpoint: '/apps/pipelinq/api/party-indicator-values/{id}/acknowledge' })
		expect(source.errorText).toBe('The list of warnings could not be loaded.')
		expect(widget.content.emptyText).toBe('No warnings on this record.')
	})

	it('adds a warning for this party from the declared vocabulary, with or without warnings shown', () => {
		for (const add of [widget.content.emptyActions[0], widget.content.indicatorActions[0]]) {
			expect(add).toMatchObject({ type: 'open-form', schema: 'partyIndicatorValue', props: { party: '@objectId' } })
			expect(add.includeFields).toEqual(['indicator', 'validFrom', 'validUntil', 'source'])
			expect(add.fieldOverrides.indicator.optionsFrom).toMatchObject({ schema: 'partyIndicator', valueField: 'code' })
			expect(add.fieldOverrides.validFrom.default).toBe('@today')
		}
	})

	it('calls, dials from the agent extension, mails and routes', () => {
		const byId = Object.fromEntries(widget.content.actions.map((a) => [a.id, a]))
		expect(byId.call.target).toBe('@object.phone|tel')
		expect(byId.mail.target).toBe('@object.email|mailto')
		expect(byId.route.target).toBe('@object.address|geo')
		expect(byId.dial).toMatchObject({ type: 'api-call', method: 'POST', url: '/apps/pipelinq/api/cti/click-to-dial', payload: { targetNumber: '@object.phone' } })
		expect(byId.dial.payload.extension).toBeUndefined()
	})
})

describe('the agent profile', () => {
	it('holds the extension Call from my extension dials from', () => {
		const props = JSON.parse(read('lib', 'Settings', 'pipelinq_register.json')).components.schemas.agentProfile.properties
		expect(props.extension).toMatchObject({ type: 'string', pattern: '^[0-9]{1,10}$' })
	})
})
