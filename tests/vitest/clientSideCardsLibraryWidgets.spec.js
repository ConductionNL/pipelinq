// @vitest-environment node
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * PqKlant / PqOrganisatie "Contactkanalen" and "Relaties" are library widgets
 * placed by the manifest: `field-groups` (e-mail, phone and social lists as
 * label and value pairs, one Add menu in the title row) and `object-links`
 * (the relationship objects from this client, the inverse kept in step). The
 * two app cards that drew them before are gone from the registry.
 *
 * @spec openspec/changes/client-side-cards-library-widgets/specs/client-side-cards-library-widgets/spec.md#requirement-the-client-side-cards-are-library-widgets
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'

const registrySource = readFileSync(resolve(__dirname, '../../src/registry.js'), 'utf8')
const nl = JSON.parse(readFileSync(resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations

const page = manifest.pages.find((p) => p.id === 'ClientDetail')
const widget = (id) => page.config.widgets.find((w) => w.id === id)

describe('the client side cards are library widgets', () => {
	it('draws the channels as field groups with one Add menu in the title row', () => {
		const channels = widget('client-channels')
		expect(channels.type).toBe('field-groups')
		expect(channels.content.add).toEqual({ label: 'Add', ariaLabel: 'Add channel', placement: 'title' })
		expect(channels.content.groups.map((g) => g.field)).toEqual(['emails', 'phones', 'socialProfiles'])
		expect(channels.content.groups.map((g) => g.add.label)).toEqual(['Email address', 'Phone number', 'Social profile'])
		expect(channels.content.afterSave).toMatchObject({ url: '/apps/pipelinq/api/contacts-sync/write-back', body: { objectType: 'client', objectId: '@objectId' } })
	})

	it('keeps the plain e-mail and phone in step with the preferred entry', () => {
		const [emails, phones] = widget('client-channels').content.groups
		expect(emails.mirror).toEqual({ field: 'email', path: 'value', prefer: 'primary' })
		expect(phones.mirror).toEqual({ field: 'phone', path: 'value', prefer: 'primary' })
		expect(emails.add.exclusive).toEqual(['primary'])
		expect(phones.add.exclusive).toEqual(['primary'])
	})

	it('words the rows as the board does: "E-mail, privé", "Telefoon, mobiel", "Sociaal" with "Nog geen"', () => {
		const [emails, phones, social] = widget('client-channels').content.groups
		expect(emails.row.label[0]).toBe('Email')
		expect(nl[emails.row.label[1].map.private]).toBe('privé')
		expect(phones.row.label[0]).toBe('Phone')
		expect(nl[phones.row.label[1].map.mobile]).toBe('mobiel')
		expect(nl[social.emptyLabel]).toBe('Sociaal')
		expect(nl[social.emptyText]).toBe('Nog geen')
	})

	it('draws the relationships as object links from this client, the inverse kept in step', () => {
		const rel = widget('client-relationships')
		expect(rel.type).toBe('object-links')
		expect(rel.content).toMatchObject({
			register: 'pipelinq',
			schema: 'relationship',
			from: 'fromContact',
			to: 'toContact',
			toType: 'toType',
			selfType: 'client',
			pair: true,
			add: { label: 'Add', ariaLabel: 'Add relationship', placement: 'title' },
		})
		expect(rel.content.targets.map((t) => [t.type, t.schema, t.route])).toEqual([
			['contact', 'contact', 'ContactDetail'],
			['client', 'client', 'ClientDetail'],
		])
		const partner = rel.content.types.find((t) => t.value === 'partner')
		expect(partner).toMatchObject({ inverse: 'partner', group: 'Familie' })
		expect(nl[partner.label]).toBe('Partner van')
	})

	it('has no app card for either any more', () => {
		expect(registrySource).not.toContain('ContactChannelsCard')
		expect(registrySource).not.toContain('ContactRelationshipsCard')
	})
})
