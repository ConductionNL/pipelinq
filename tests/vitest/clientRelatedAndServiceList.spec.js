// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client page has a Related card, and the services list reads as words.
 *
 * The cloud check of 10 October 2026 found (point 4) that the contact, lead,
 * task, ticket, product, booking and contract pages carry the Related card
 * but the client page does not, and (point 6) that the services list showed
 * the stored code "active" in its Status column while the service page reads
 * "Active", and an Online column that looked blank for a service customers
 * can book online.
 *
 * The list cells are rendered through the library's real CnCellRenderer with
 * pipelinq's real formatter registry and the real merged schema, so the test
 * sees what a reader sees.
 *
 * @spec openspec/changes/round5-client-related-and-service-list/specs/client-management/spec.md
 * @spec openspec/changes/round5-client-related-and-service-list/specs/appointment-booking/spec.md
 */

import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import CnCellRenderer from '@conduction/nextcloud-vue/src/components/CnCellRenderer/CnCellRenderer.vue'
import { CELL_FORMATTERS } from '../../src/services/cellFormatters.js'

const ROOT = path.resolve(__dirname, '../..')
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

/** Every page: the bundled manifest plus every fragment. */
const PAGES = [
	...readJson('src', 'manifest.json').pages,
	...fs
		.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
		.filter((name) => name.endsWith('.json'))
		.sort()
		.flatMap((name) => readJson('src', 'manifest.d', name).pages || []),
]

/**
 * A page by id.
 *
 * @param {string} id The page id.
 * @return {object} The page.
 */
function page(id) {
	return PAGES.find((p) => p.id === id)
}

/**
 * Whether two grid items overlap.
 *
 * @param {object} a Item.
 * @param {object} b Item.
 * @return {boolean}
 */
function overlaps(a, b) {
	return (
		a.gridX < b.gridX + b.gridWidth
		&& b.gridX < a.gridX + a.gridWidth
		&& a.gridY < b.gridY + b.gridHeight
		&& b.gridY < a.gridY + a.gridHeight
	)
}

/**
 * Deep-merge like ConfigFileLoaderService::deepMergeConfig: objects merge
 * key by key, everything else is replaced.
 *
 * @param {object} base The base.
 * @param {object} override The fragment.
 * @return {object} The base, merged in place.
 */
function merge(base, override) {
	const isObject = (v) => v && typeof v === 'object' && !Array.isArray(v)
	for (const [key, value] of Object.entries(override)) {
		if (isObject(value) && isObject(base[key])) {
			merge(base[key], value)
		} else {
			base[key] = value
		}
	}
	return base
}

/** The appointmentService schema as the register import builds it. */
function serviceSchema() {
	const register = readJson('lib', 'Settings', 'pipelinq_register.json')
	fs.readdirSync(path.join(ROOT, 'lib', 'Settings', 'register.d'))
		.filter((name) => name.endsWith('.json'))
		.sort()
		.forEach((name) =>
			merge(register, readJson('lib', 'Settings', 'register.d', name)),
		)
	return register.components.schemas.appointmentService
}

describe('the Related card', () => {
	const PAGES_WITH_RELATED = [
		'ClientDetail',
		'ContactDetail',
		'LeadDetail',
		'TaskDetail',
		'TicketDetail',
		'ProductDetail',
		'BookingDetail',
		'ContractDetail',
	]

	it('is on the client page and on every page the cloud check named', () => {
		const withRelated = PAGES.filter(
			(p) =>
				p.type === 'detail'
				&& (p.config?.widgets || []).some((w) => w.type === 'related'),
		).map((p) => p.id)
		expect(withRelated).toEqual(expect.arrayContaining(PAGES_WITH_RELATED))
	})

	it('has a place in the client page layout that overlaps nothing', () => {
		const client = page('ClientDetail')
		const related = client.config.widgets.find((w) => w.type === 'related')
		expect(related).toBeDefined()
		const cell = client.config.layout.find((l) => l.widgetId === related.id)
		expect(cell).toBeDefined()
		expect(cell.gridHeight).toBeGreaterThanOrEqual(5)
		for (const other of client.config.layout) {
			if (other !== cell) {
				expect(overlaps(cell, other), other.widgetId).toBe(false)
			}
		}
	})

	it('sits beside the Records strip, as on the contact page', () => {
		const layout = page('ClientDetail').config.layout
		const records = layout.find((l) => l.widgetId === 'client-records')
		const related = layout.find((l) => l.widgetId === 'client-related')
		expect(related.gridY).toBe(records.gridY)
		expect(records.gridWidth + related.gridWidth).toBe(12)
	})
})

describe('the services list', () => {
	const schema = serviceSchema()
	const columns = page('Services').config.columns

	/**
	 * Render one list cell the way CnDataTable does: the column's formatter,
	 * widget and widget props, the schema property, pipelinq's formatters.
	 *
	 * @param {string} key The column key.
	 * @param {unknown} value The stored value.
	 * @return {object} The mounted cell.
	 */
	function cell(key, value) {
		const col = columns.find((c) => typeof c === 'object' && c.key === key)
		const base = schema.properties[key]
		return mount(CnCellRenderer, {
			props: {
				value,
				row: { [key]: value },
				property: col.type ? { ...base, type: col.type } : base,
				formatter: col.formatter || null,
				formatterOptions: col.formatterOptions || null,
				widget: col.widget || null,
				widgetProps: col.widgetProps || null,
			},
			global: { provide: { cnFormatters: CELL_FORMATTERS } },
		})
	}

	it('the schema labels the status values', () => {
		expect(schema.properties.status['x-enum-labels']).toMatchObject({
			active: 'Active',
		})
	})

	it('shows the status label, not the stored code', () => {
		const w = cell('status', 'active')
		expect(w.text()).toBe('Active')
		expect(cell('status', 'archived').text()).toBe('Archived')
	})

	it('keeps the status colour', () => {
		expect(cell('status', 'active').html()).toContain('success')
	})

	it('says Yes for a service customers can book online', () => {
		expect(cell('bookableOnline', true).text()).toBe('Yes')
	})

	it('says No for a service they cannot', () => {
		expect(cell('bookableOnline', false).text()).toBe('No')
	})
})
