// @vitest-environment node
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The relationships of a client or contact are a nextcloud-vue `object-list`
 * over the `relationship` schema. The server owns the type table
 * (GET /api/relationship-types), refuses an unknown or repeated relationship
 * (POST /api/relationships), derives the inverse type, category and target
 * kind, and keeps the inverse row (RelationshipTypeServiceTest,
 * RelationshipInverseListenerTest). This file pins the config.
 *
 * @spec openspec/changes/relationship-types-on-the-server/specs/contact-relationship-mapping/spec.md#requirement-detail-pages-list-relationships-as-an-object-list
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (...parts) => readFileSync(resolve(__dirname, '../..', ...parts), 'utf8')
const manifest = JSON.parse(read('src', 'manifest.json'))
const register = JSON.parse(read('lib', 'Settings', 'pipelinq_register.json'))
const page = (id) => manifest.pages.find((p) => p.id === id).config

describe.each([
	['client', 'ClientDetail'],
	['contact', 'ContactDetail'],
])('the relationships of a %s', (kind, pageId) => {
	const config = page(pageId)
	const list = config.widgets.find((w) => w.id === `${kind}-relationships`)

	it('lists the relationships from this record, grouped by category, in the side column', () => {
		expect(config.sideColumn).toContain(`${kind}-relationships`)
		expect(list.type).toBe('object-list')
		expect(list.content).toMatchObject({ register: 'pipelinq', schema: 'relationship', filter: { fromContact: '@objectId' }, groupBy: 'category' })
	})

	it('names the other party, the type and when it ends, an ended one in red', () => {
		const [name, type, ends] = list.content.columns
		expect(name).toMatchObject({ key: 'toContact', widget: 'fkResolve' })
		expect(name.widgetProps.schemaFrom).toEqual({ field: 'toType', map: { client: 'client', contact: 'contact' } })
		expect(type.key).toBe('type')
		expect(ends.widgetProps.variantWhen).toEqual([{ op: 'lt', value: 0, variant: 'error' }])
	})

	it('opens the other party on its own page', () => {
		expect(list.content).toMatchObject({ rowRouteField: 'toType', rowRouteMap: { client: 'ClientDetail', contact: 'ContactDetail' }, rowRouteIdField: 'toContact' })
	})

	it('adds through the server, picking a contact or client and a type the server offers', () => {
		expect(list.content.createEndpoint).toEqual({ url: '/apps/pipelinq/api/relationships', extra: { fromType: kind } })
		expect(read('appinfo', 'routes.php')).toContain("'url' => '/api/relationships'")
		const { toContact, type, strength } = list.content.formFieldOverrides
		expect(toContact.widget).toBe('party')
		expect(toContact.sources.map((s) => s.schema)).toEqual(['contact', 'client'])
		expect(type.optionsFrom).toMatchObject({ url: '/apps/pipelinq/api/relationship-types', responsePath: 'results', valueField: 'value', groupField: 'category' })
		expect(strength.default).toBe('medium')
		expect(list.content.formIncludeFields).toEqual(['toContact', 'type', 'notes', 'startDate', 'endDate', 'strength'])
	})

	it('edits and removes a relationship in place', () => {
		expect(list.content).toMatchObject({ editable: true, deletable: true })
	})
})

describe('the relationship schema', () => {
	it('shows notes, dates and strength on the form', () => {
		const props = register.components.schemas.relationship.properties
		for (const key of ['notes', 'startDate', 'endDate', 'strength']) {
			expect(props[key].visible, key).not.toBe(false)
		}
	})
})
