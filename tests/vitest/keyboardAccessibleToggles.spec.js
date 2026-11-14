// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Keyboard access on the two dashboard widgets this change touches: the Find
 * Client result row opens the same client on Enter and on Space as on a mouse
 * click, and the Prospect widget header exposes the Refresh control and the
 * expand toggle as separate, non-nested controls.
 *
 * @spec openspec/changes/keyboard-accessible-click-toggles/proposal.md
 */
import * as fs from 'fs'
import * as path from 'path'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const openRowTarget = vi.fn()
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcLoadingIcon: {}, NcNoteCard: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@conduction/nextcloud-vue', () => ({ openRowTarget: (...a) => openRowTarget(...a) }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn() }))

let FindClientWidget

beforeAll(async () => {
	globalThis.t = (app, text, vars) => text.replace(/\{(\w+)\}/g, (_m, k) => (vars?.[k] ?? _m))
	globalThis.OC = { requestToken: 'tok' }
	FindClientWidget = (await import('../../src/views/widgets/FindClientWidget.vue')).default
}, 60000)

beforeEach(() => {
	openRowTarget.mockReset()
})

afterEach(() => {
	vi.restoreAllMocks()
})

/**
 * Bind the widget's methods and data over a plain context.
 *
 * @return {object} The context.
 */
function bind() {
	const ctx = { ...FindClientWidget.data.call({}) }
	for (const [name, fn] of Object.entries(FindClientWidget.methods)) {
		if (!(name in ctx)) ctx[name] = fn.bind(ctx)
	}
	return ctx
}

describe('FindClientWidget keyboard parity', () => {
	const client = { id: 'c1', name: 'Acme', type: 'organization' }

	it('opens the client the same way on a click and on Enter', () => {
		const ctx = bind()
		const clickEvent = { type: 'click' }
		ctx.viewClient(client, clickEvent)
		expect(openRowTarget).toHaveBeenCalledWith(clickEvent, '/index.php/apps/pipelinq/clients/c1')

		openRowTarget.mockReset()
		const enterEvent = { type: 'keydown', key: 'Enter' }
		ctx.viewClient(client, enterEvent)
		expect(openRowTarget).toHaveBeenCalledWith(enterEvent, '/index.php/apps/pipelinq/clients/c1')
	})

	it('opens the client on Space and prevents the page from scrolling', () => {
		const ctx = bind()
		const preventDefault = vi.fn()
		ctx.viewClientOnSpace(client, { type: 'keydown', key: ' ', preventDefault })
		expect(openRowTarget).toHaveBeenCalledWith(expect.objectContaining({ key: ' ' }), '/index.php/apps/pipelinq/clients/c1')
		expect(preventDefault).toHaveBeenCalledOnce()
	})
})

describe('the result row is a single keyboard-reachable control', () => {
	const source = fs.readFileSync(path.resolve(__dirname, '../../src/views/widgets/FindClientWidget.vue'), 'utf8')

	it('marks client-info as a button with a label and Enter/Space handlers', () => {
		const block = source.slice(source.indexOf('class="client-info"'))
		expect(block).toMatch(/role="button"/)
		expect(block).toMatch(/tabindex="0"/)
		expect(block).toMatch(/@keydown\.enter="viewClient/)
		expect(block).toMatch(/@keydown\.space="viewClientOnSpace/)
	})
})

describe('ProspectWidget header controls are not nested', () => {
	const source = fs.readFileSync(path.resolve(__dirname, '../../src/components/ProspectWidget.vue'), 'utf8')
	const header = source.slice(source.indexOf('prospect-widget__header'), source.indexOf('</div>\n\n\t\t<!-- Collapsed preview'))

	it('exposes the expand toggle as its own native button, outside the Refresh button', () => {
		expect(header).toMatch(/<button[^>]*class="prospect-widget__expand-toggle"[\s\S]*?aria-expanded="expanded"[\s\S]*?aria-label/)
	})

	it('no longer makes the whole header a button that wraps the focusable Refresh control', () => {
		const headerOpen = source.slice(source.indexOf('<div class="prospect-widget__header"'), source.indexOf('prospect-widget__title'))
		expect(headerOpen).not.toMatch(/role="button"/)
		expect(header).not.toMatch(/@click\.stop="refresh"/)
	})
})
