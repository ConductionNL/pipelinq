// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A lead whose value was typed over what its product lines add up to shows the
 * calculated value and one action to use it (lead-use-calculated-value).
 *
 * @spec openspec/changes/lead-use-calculated-value/tasks.md#task-2
 */

import { describe, expect, it } from 'vitest'
import register from '../../lib/Settings/pipelinq_register.json'
import overlay from '../../lib/Settings/register.d/99-zzzzzzz-lead-calculated-value.json'
import manifest from '../../src/manifest.json'

const lead = overlay.components.schemas.lead
const config = manifest.pages.find((page) => page.id === 'LeadDetail').config
const OVERRIDDEN = { field: 'valueOverridden', op: 'eq', value: true }

describe('the lead schema', () => {
	it('keeps the calculated value and works out whether the value was typed over it', () => {
		expect(lead.properties.calculatedValue.readOnly).toBe(true)
		expect(lead.configuration['x-openregister-calculations'].valueOverridden.materialise).toBe(true)
		expect(lead.version).toBe('1.7.0')
		expect(register.info.version).toBe('1.15.0')
	})
})

describe('the lead page', () => {
	const deal = config.widgets.find((widget) => widget.id === 'lead-deal').content

	it('shows the calculated value under the value, only when the value was typed over it', () => {
		expect(deal.include.slice(0, 2)).toEqual(['value', 'calculatedValue'])
		expect(deal.overrides.calculatedValue).toMatchObject({ editable: false, visibleWhen: OVERRIDDEN })
	})

	it('offers one action that writes the calculated value into the value', () => {
		const action = config.headerActions.find((entry) => entry.id === 'use-calculated-value')
		expect(action).toMatchObject({ type: 'api-call', method: 'PATCH', payload: { value: '@object.calculatedValue' }, visibleWhen: OVERRIDDEN })
		expect(action.url).toBe('/apps/openregister/api/objects/pipelinq/lead/@objectId')
	})
})
