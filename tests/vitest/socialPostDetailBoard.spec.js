/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqSocialBericht as configuration: the meta line names the networks
 * and the campaign, the approvals stand in a side card, and the texts card
 * leaves them out.
 *
 * @spec openspec/specs/declarative-view-system/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const fragment = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.d', '78-social-publishing.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
const registry = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')

/**
 * The SocialPostDetail page in the fragment, wherever it is nested.
 *
 * @param {*} node A JSON node.
 * @return {object|null} The page.
 */
function findPage(node) {
	if (Array.isArray(node)) {
		for (const item of node) {
			const hit = findPage(item)
			if (hit) return hit
		}
		return null
	}
	if (node && typeof node === 'object') {
		if (node.id === 'SocialPostDetail') return node
		for (const value of Object.values(node)) {
			const hit = findPage(value)
			if (hit) return hit
		}
	}
	return null
}

const config = findPage(fragment).config
const widget = (id) => config.widgets.find((w) => w.id === id)

describe('Social post page', () => {
	it('names the networks and the campaign on the meta line', () => {
		expect(nl[config.headerMeta.other]).toBe('{count:networks} netwerken · campagne {campaignId.name}')
		expect(nl[config.headerMeta.one]).toBe('{count:networks} netwerk · campagne {campaignId.name}')
		expect(config.headerMetaCounts.networks).toEqual({ register: 'pipelinq', schema: 'socialPublication', filter: { postId: '@objectId' } })
		expect(config.extend).toContain('campaignId')
	})

	it('lists the approvals in a side card, when, what and who', () => {
		expect(config.sideColumn).toEqual(['social-post-data', 'social-post-approvals'])
		const approvals = widget('social-post-approvals')
		expect(nl[approvals.title]).toBe('Goedkeuringen')
		expect(approvals.content.objectRows).toEqual({ path: 'approvals' })
		expect(nl[approvals.content.title[0]]).toBe('{decision} door {userId|user}')
		expect(nl[approvals.content.valueLabels.decision.approved]).toBe('Goedgekeurd')
		expect(nl[approvals.content.footnote]).toBe('Een bericht wacht op goedkeuring voordat het kan worden ingepland.')
	})

	it('leaves the approvals out of the texts card', () => {
		expect(registry).toContain('hideApprovals: true')
	})
})
