/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqNieuwsbrieven: under each newsletter the line names its audience,
 * "Segment: verenigingen in Zuiddrecht · 412 contacten" or "Mailinglijst:
 * Afvalnieuws · 2.180 abonnees", read through nc-vue's `secondaryRefs`.
 *
 * @spec openspec/changes/newsletter-list-audience-line/specs/marketing/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.d/75-marketing-blasts.json'), 'utf8'))
const nl = JSON.parse(readFileSync(resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
const page = manifest.pages.find((p) => p.id === 'Blasts')
const name = page.config.columns.find((c) => c.key === 'name')

const SEGMENT = 'Segment: {segment.name|lowerfirst} · {segment.estimatedSize|number} contacts'
const LIST = 'Mailing list: {list.name} · {list.subscribers|number} subscribers'

describe('the newsletter list names each newsletter\'s audience', () => {
	it('reads the segment, else the mailing list, as the line under the name', () => {
		expect(name.secondary).toEqual([SEGMENT, LIST])
		expect(name.secondaryRefs.segment).toEqual({ field: 'segmentId', register: 'pipelinq', schema: 'segment' })
		expect(name.secondaryRefs.list).toMatchObject({ field: 'listId', register: 'pipelinq', schema: 'mailingList' })
	})

	it('counts a list\'s confirmed subscriptions', () => {
		expect(name.secondaryRefs.list.counts.subscribers).toEqual({
			register: 'pipelinq',
			schema: 'subscription',
			where: { listId: '@self.id', state: 'confirmed' },
		})
	})

	it('says it in Dutch', () => {
		expect(nl[SEGMENT]).toBe('Segment: {segment.name|lowerfirst} · {segment.estimatedSize|number} contacten')
		expect(nl[LIST]).toBe('Mailinglijst: {list.name} · {list.subscribers|number} abonnees')
	})
})
