// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The requests the notes card, the contact import dialog, the My work widget
 * and the pipeline board send: each URL, method and body, with the JSON,
 * CSRF-token and OCS headers Nextcloud expects.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-timeline-notes-ui/tasks.md#task-12
 */
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(async () => ({ data: [] })), post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({ NcActionButton: {}, NcActionCaption: {}, NcActionCheckbox: {}, NcActions: {}, NcButton: {}, NcCheckboxRadioSwitch: {}, NcLoadingIcon: {}, NcNoteCard: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnActionsBar: {}, CnBuildiqEditButton: {}, CnDialog: {}, CnPageHeader: {}, CnQuickFilterBar: {}, CnStatusBadge: {}, openRowTarget: vi.fn(), useLook: () => ({ isBoard: false, lookClass: '' }) }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn() }))
vi.mock('../../src/components/leadScore/LeadScoreBadge.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/LeadCreateDialog.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/PipelineFormDialog.vue', () => ({ default: {} }))
vi.mock('../../src/views/pipeline/PipelineCard.vue', () => ({ default: {} }))

const headers = { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' }
const components = {}

beforeAll(async () => {
	globalThis.t = (_app, text) => text
	components.notes = (await import('../../src/components/EntityNotes.vue')).default
	components.importDialog = (await import('../../src/dialogs/ContactImportDialog.vue')).default
	components.myWork = (await import('../../src/views/dashboard/widgets/MyWorkWidget.vue')).default
	components.board = (await import('../../src/views/pipeline/PipelineBoard.vue')).default
}, 60000)

beforeEach(() => {
	vi.stubGlobal('OC', { requestToken: 'tok' })
	vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({}) })))
})

afterEach(() => {
	vi.unstubAllGlobals()
})

/**
 * @param {object} component The component options.
 * @param {object} state The state.
 * @return {object} A context with the state and every method bound.
 */
function bind(component, state) {
	const ctx = { t: globalThis.t, ...state }
	for (const [name, fn] of Object.entries(component.methods)) {
		if (!(name in ctx)) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

describe('EntityNotes', () => {
	it('reads, adds and deletes notes with the JSON and token headers', async () => {
		const ctx = bind(components.notes, { objectType: 'lead', objectId: 'l1', newMessage: 'Hi', notes: [] })
		await ctx.fetchNotes()
		await ctx.addNote()
		await ctx.deleteNote('n1')
		expect(fetch.mock.calls).toEqual([
			['/index.php/apps/pipelinq/api/notes/lead/l1', { headers }],
			['/index.php/apps/pipelinq/api/notes/lead/l1', { method: 'POST', headers, body: JSON.stringify({ message: 'Hi' }) }],
			['/index.php/apps/pipelinq/api/notes/lead/l1', { headers }],
			['/index.php/apps/pipelinq/api/notes/single/n1', { method: 'DELETE', headers }],
			['/index.php/apps/pipelinq/api/notes/lead/l1', { headers }],
		])
	})
})

describe('ContactImportDialog', () => {
	it('searches the address books and imports a contact with the JSON and token headers', async () => {
		const ctx = bind(components.importDialog, { query: 'an na', importType: 'contact', clientId: 'c1', results: [], imported: [], $emit: vi.fn() })
		await ctx.doSearch()
		await ctx.importContact({ uid: 'u1', addressBookKey: 'ab' })
		expect(fetch.mock.calls[0]).toEqual(['/index.php/apps/pipelinq/api/contacts-sync/search?q=an%20na', { headers }])
		expect(fetch.mock.calls[1]).toEqual(['/index.php/apps/pipelinq/api/contacts-sync/import', { method: 'POST', headers, body: JSON.stringify({ uid: 'u1', addressBookKey: 'ab', type: 'contact', clientId: 'c1' }) }])
	})
})

describe('MyWorkWidget', () => {
	it('reads the worklist with the JSON and token headers', async () => {
		const ctx = bind(components.myWork, { items: [] })
		await ctx.load()
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/worklist/mine?limit=5', { headers })
	})
})

describe('PipelineBoard', () => {
	it('reads every lead and a pipeline\'s items with the JSON and token headers', async () => {
		const registry = { lead: { register: 'r', schema: 'lead' }, ticket: { register: 'r', schema: 'ticket' } }
		const ctx = bind(components.board, { objectStore: { objectTypeRegistry: registry }, selectedPipelineId: 'p1' })
		await ctx.fetchAllLeads()
		await ctx.fetchSchemaItems('request')
		expect(fetch.mock.calls).toEqual([
			['/index.php/apps/openregister/api/objects/r/lead?_limit=500', { headers }],
			['/index.php/apps/openregister/api/objects/r/ticket?ticketType=request&pipeline=p1&_limit=200', { headers }],
		])
	})
})
