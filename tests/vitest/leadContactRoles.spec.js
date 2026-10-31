/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contacts on a lead with the role each plays in the deal, as the PqLead
 * board draws them: a role relationship from the lead to the contact, labelled
 * in the user's language and listed decision maker first.
 *
 * @spec openspec/specs/lead-management/spec.md
 */

import { readdirSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeAll, describe, expect, it } from 'vitest'

let roles

beforeAll(async () => {
	globalThis.t = (app, text) => text
	roles = await import('../../src/services/crmRoles.js')
})

/**
 * The register with every fragment merged on top, as the loader does.
 *
 * @return {object}
 */
function mergedRegister() {
	const merge = (a, b) => {
		for (const [k, v] of Object.entries(b)) {
			if (k === '__proto__' || k === 'constructor' || k === 'prototype') {
				continue
			}
			if (v && typeof v === 'object' && !Array.isArray(v) && a[k] && typeof a[k] === 'object') {
				merge(a[k], v)
			} else {
				a[k] = v
			}
		}
		return a
	}
	const dir = resolve(__dirname, '../../lib/Settings')
	const base = JSON.parse(readFileSync(resolve(dir, 'pipelinq_register.json'), 'utf8'))
	for (const f of readdirSync(resolve(dir, 'register.d')).filter((n) => n.endsWith('.json')).sort()) {
		merge(base, JSON.parse(readFileSync(resolve(dir, 'register.d', f), 'utf8')))
	}
	return base
}

describe('lead contact roles', () => {
	it('labels the stored role values as the board names them', () => {
		expect(roles.crmRoleLabel('beslisser')).toBe('Decision maker')
		expect(roles.crmRoleLabel('kampioen')).toBe('Champion')
		expect(roles.crmRoleLabel('gatekeeper')).toBe('Gatekeeper')
		expect(roles.crmRoleLabel('beinvloeder')).toBe('Influencer')
		expect(roles.crmRoleLabel('evaluator')).toBe('Evaluator')
		expect(roles.crmRoleLabel('gebruiker')).toBe('End user')
		expect(roles.crmRoleLabel('sponsor')).toBe('sponsor')
	})

	it('lists the decision maker first, then champion and gatekeeper', () => {
		const sorted = roles.sortByCrmRole([
			{ type: 'gatekeeper' },
			{ type: 'unknown' },
			{ type: 'kampioen' },
			{ type: 'beslisser' },
		])
		expect(sorted.map((r) => r.type)).toEqual(['beslisser', 'kampioen', 'gatekeeper', 'unknown'])
	})

	it('lets a relationship start at a lead', () => {
		const fromType = mergedRegister().components.schemas.relationship.properties.fromType
		expect(fromType.enum).toContain('lead')
	})

	it('shows the roles on the lead overview', () => {
		const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
		const lead = manifest.pages.find((p) => p.id === 'LeadDetail')
		const widget = lead.config.widgets.find((w) => w.id === 'lead-contacts')
		expect(widget.type).toBe('LeadContactRolesWidget')
		expect(widget.content.title).toBe('Contacts and their role')
	})
})
