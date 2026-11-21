/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqService: the service reads in an Overview tab beside History, with
 * the Actions menu at the end of the tab row, as the resource page does for
 * its tabs. Read as text.
 *
 * @spec openspec/specs/appointment-booking/spec.md#requirement-a-service-reads-in-overview-and-history-tabs
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const src = readFileSync(resolve(__dirname, '../../src/views/bookings/ServiceDetail.vue'), 'utf8')
const template = src.slice(0, src.indexOf('<script>'))

describe('the service detail tabs', () => {
	it('puts the details, rules and steps in Overview and the audit trail in History', () => {
		const overview = template.indexOf("<CnTab :title=\"t('pipelinq', 'Overview')\">")
		const history = template.indexOf("<CnTab :title=\"t('pipelinq', 'History')\" lazy>")
		expect(overview).toBeGreaterThan(-1)
		expect(history).toBeGreaterThan(overview)
		const between = template.slice(overview, history)
		expect(between).toContain("t('pipelinq', 'Service details')")
		expect(between).toContain('data-testid="service-composition"')
		expect(template.slice(history)).toMatch(/<CnAuditTrailCard register="pipelinq" schema="appointmentService" :objectId="serviceId" \/>/)
	})

	it('ends the tab row with the Actions menu', () => {
		const navEnd = template.indexOf('<template #nav-end>')
		expect(navEnd).toBeGreaterThan(template.indexOf('<CnTabs'))
		expect(template.slice(navEnd, template.indexOf('</template>', navEnd))).toContain('<CnActionsMenu')
		expect(src).toMatch(/import \{[^}]*CnActionsMenu[^}]*CnAuditTrailCard[^}]*CnTab,[^}]*CnTabs,/s)
	})
})
