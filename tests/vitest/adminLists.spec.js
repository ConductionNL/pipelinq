// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The admin page's lists: embedded index pages that open rows in place, in
 * a modal the page mounts or in the built-in form dialog. The agent form
 * picks a Nextcloud user (no free-text id) and its skills from the skills.
 *
 * @spec openspec/changes/pl-abstract-admin-sections/specs/admin-settings/spec.md#requirement-the-admin-lists-are-declared-index-pages
 */
import { fieldsFromSchema } from '@conduction/nextcloud-vue/src/utils/schema.js'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { adminLists } from '../../src/views/settings/adminLists.js'

const register = JSON.parse(readFileSync(resolve(__dirname, '../../lib/Settings/pipelinq_register.json'), 'utf8'))
const schemas = register.components.schemas
const settings = readFileSync(resolve(__dirname, '../../src/views/settings/Settings.vue'), 'utf8')
const typeOf = (slug) => ({ register: 'pipelinq', schema: slug })
const skills = [{ id: 's1', title: 'Parking' }, { id: 's2', title: 'Taxes' }]
const lists = adminLists((app, text) => text, typeOf, vi.fn(), skills)

describe('the admin lists', () => {
	it('are all embedded, so a row opens in place', () => {
		for (const [id, list] of Object.entries(lists)) {
			expect(list.embedded, id).toBe(true)
		}
	})

	it('read register objects through the object store, with their type\'s register and schema', () => {
		for (const id of ['productCategory', 'skill', 'agentProfile']) {
			expect(lists[id], id).toMatchObject({ register: 'pipelinq', schema: id })
		}
		expect(adminLists((app, text) => text, () => undefined, vi.fn()).skill.register).toBeUndefined()
	})

	it('picks the agent from the Nextcloud users and the skills from the skills', () => {
		const fields = fieldsFromSchema(schemas.agentProfile, { overrides: lists.agentProfile.fieldOverrides })
		const user = fields.find((field) => field.key === 'userId')
		expect(user.userPicker).toEqual({ multiple: false })
		const skillField = fields.find((field) => field.key === 'skills')
		expect(skillField.widget).toBe('multiselect')
		expect(skillField.items.enum).toEqual(['s1', 's2'])
		expect(skillField.enumLabels).toEqual({ s1: 'Parking', s2: 'Taxes' })
	})

	it('edits a category\'s description, which the schema hides elsewhere', () => {
		expect(schemas.productCategory.properties.description.visible).toBe(false)
		expect(lists.productCategory.fieldOverrides.description).toEqual({ hidden: false })
	})

	it('only opens modals the page mounts', () => {
		const mounted = settings.match(/const ADMIN_MODALS = \{([^}]*)\}/)[1].split(',').map((name) => name.trim()).filter(Boolean)
		const named = new Set()
		for (const list of Object.values(lists)) {
			for (const key of ['rowModal', 'createModal']) {
				if (list[key]) {
					named.add(list[key])
				}
			}
		}
		const openModal = vi.fn()
		adminLists((app, text) => text, typeOf, openModal).posTenderType.actions[0].handler({ id: 'tt1' })
		named.add(openModal.mock.calls[0][0])
		for (const name of named) {
			expect(mounted, name).toContain(name)
		}
	})

	it('hosts the modals itself and reads the lists again when one closes', () => {
		expect(settings).toMatch(/cnOpenModal: \(key, props = \{\}\) => this\.openAdminModal\(key, props\)/)
		expect(settings).toMatch(/closeAdminModal\(\) \{\s+this\.adminModal = null\s+this\.listKey \+= 1/)
	})
})
