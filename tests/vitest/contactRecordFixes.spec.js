// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PL-ABSTRACT bugs 17-19 on the contact record (lane PL1):
 * - the relationship form is a dialog in src/dialogs/ (focus trap, Escape),
 *   not a hand-rolled overlay;
 * - the activity timeline formats dates in the reader's locale, not nl-NL;
 * - the BRP panel draws icons instead of emoji and its messages carry no
 *   em-dash;
 * - the components nothing mounted are gone.
 *
 * @spec openspec/specs/contact-relationship-mapping/spec.md#requirement-relationship-management-on-contact-detail-v1
 */

import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, describe, expect, it } from 'vitest'

const root = resolve(__dirname, '../..')
const read = (...parts) => readFileSync(resolve(root, ...parts), 'utf8')

describe('the contact record speaks the reader\'s language', () => {
	afterEach(() => {
		delete globalThis.OC
	})

	it('draws icons, not emoji, in the BRP panel and writes no em-dash to the user', () => {
		const source = read('src', 'components', 'BrpContactPanel.vue')
		const template = source.slice(0, source.indexOf('<script>'))
		expect(template).not.toMatch(/🔒|⚡/u)
		expect(source).not.toMatch(/t\('pipelinq',\s*'[^']*—/u)
		expect(source).not.toMatch(/'pipelinq',\s*\n\s*'[^']*—/u)
	})
})

describe('components nothing mounted are gone', () => {
	it.each([
		'src/components/CallTimer.vue',
		'src/components/CommunicationHistory.vue',
		'src/components/EntityNotes.vue',
		'src/components/LeadContactRoles.vue',
		'src/dialogs/AddContactRoleDialog.vue',
		'src/components/LeadProducts.vue',
		'src/components/ProductRevenue.vue',
		'src/components/ProspectCard.vue',
		'src/components/ProspectWidget.vue',
		'src/components/werkplek/WerkplekNewTaskDialog.vue',
		'src/components/ContactRelationships.vue',
		'src/components/ActivityTimeline.vue',
	])('%s', (file) => {
		expect(existsSync(resolve(root, file))).toBe(false)
	})

	it('names the agent status toggle by what it does', () => {
		expect(existsSync(resolve(root, 'src/components/werkplek/AgentAvailabilityToggle.vue'))).toBe(true)
		expect(read('src', 'views', 'werkplek', 'widgets', 'WerkplekHeaderActions.vue')).toContain('AgentAvailabilityToggle')
	})
})
