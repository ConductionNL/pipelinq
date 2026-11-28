/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Another app reaches the agent panel with a plain URL, optionally focused
 * on one call with `?callId=`. The link is built here the way a consuming
 * app builds it, with the real `@nextcloud/router`, and read back the way
 * the panel reads it.
 *
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-another-app-links-in-with-a-plain-url
 */

import { generateUrl } from '@nextcloud/router'
import { readFileSync } from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { AGENT_PANEL_ROUTE, agentPanelUrl, focusedCallId } from '../../src/services/agentPanelLink.js'

const BASE = { baseURL: 'https://cloud.example' }

/**
 * The link exactly as the integration doc tells another app to write it.
 *
 * @param {string} callId The call to focus.
 * @return {string} The URL.
 */
function consumerLink(callId) {
	return generateUrl('/apps/pipelinq/kcc/agent-panel?callId={callId}', { callId }, BASE)
}

describe('the agent panel link', () => {
	it('is a plain pipelinq page route', () => {
		expect(AGENT_PANEL_ROUTE).toBe('/apps/pipelinq/kcc/agent-panel')
		expect(agentPanelUrl(null, BASE)).toBe('https://cloud.example/index.php/apps/pipelinq/kcc/agent-panel')
	})

	it('built by a consuming app opens the panel focused on that call', () => {
		const url = new URL(consumerLink('42'))
		expect(url.pathname).toBe('/index.php/apps/pipelinq/kcc/agent-panel')
		expect(focusedCallId(url.search)).toBe('42')
	})

	it('is the same link pipelinq builds itself', () => {
		expect(agentPanelUrl('42', BASE)).toBe(consumerLink('42'))
		expect(agentPanelUrl(42, BASE)).toBe(consumerLink('42'))
	})

	it('keeps a call id with reserved characters intact', () => {
		const url = new URL(consumerLink('call/7 & more?x=1'))
		expect(focusedCallId(url.searchParams)).toBe('call/7 & more?x=1')
	})

	it('without callId shows the newest active call', () => {
		expect(focusedCallId('')).toBeNull()
		expect(focusedCallId('?other=1')).toBeNull()
		expect(focusedCallId('?callId=')).toBeNull()
		expect(focusedCallId('?callId=%20%20')).toBeNull()
		expect(focusedCallId(undefined)).toBeNull()
	})

	it('ignores a callId that cannot be a call id', () => {
		expect(focusedCallId('?callId=' + 'x'.repeat(256))).toBeNull()
		expect(agentPanelUrl('   ', BASE)).toBe(agentPanelUrl(null, BASE))
		expect(agentPanelUrl({ id: 1 }, BASE)).toBe(agentPanelUrl(null, BASE))
	})

	it('reaches the SPA through the page catch-all route', () => {
		const routes = readFileSync(path.resolve(__dirname, '../../appinfo/routes.php'), 'utf8')
		expect(routes).toMatch(/'name' => 'dashboard#page', 'url' => '\/\{path\}'/)
	})

	it('is documented with the link a consuming app writes', () => {
		const doc = readFileSync(path.resolve(__dirname, '../../docs/Integrations/kcc-agent-panel-link.md'), 'utf8')
		expect(doc).toContain("generateUrl('/apps/pipelinq/kcc/agent-panel?callId={callId}', { callId })")
		expect(doc).toContain("generateUrl('/apps/pipelinq/kcc/agent-panel')")
	})
})
