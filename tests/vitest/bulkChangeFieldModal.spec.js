// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Change a field dialog: which properties it lists, that Preview creates a
 * previewed job and commits nothing, that it shows the change and skip counts,
 * that a refusal shows its reason, and that Confirm commits and refreshes the
 * list. The component logic runs over a plain context; HTTP and the schema
 * fetch are mocked.
 *
 * @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const http = { get: vi.fn(), post: vi.fn() }
const toasts = { showError: vi.fn(), showSuccess: vi.fn() }
const bus = { emit: vi.fn() }
const schemaFetch = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => http.get(...a), post: (...a) => http.post(...a) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (...a) => toasts.showError(...a),
	showSuccess: (...a) => toasts.showSuccess(...a),
}))
vi.mock('@nextcloud/event-bus', () => ({ emit: (...a) => bus.emit(...a) }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: {}, CnStatusBadge: {} }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {}, NcCheckboxRadioSwitch: {}, NcLoadingIcon: {}, NcNoteCard: {}, NcSelect: {}, NcTextField: {},
}))
vi.mock('vue-material-design-icons/Check.vue', () => ({ default: {} }))
vi.mock('../../src/services/viewService.js', () => ({ getSchemaProperties: (...a) => schemaFetch(...a) }))

const modal = (await import('../../src/modals/BulkChangeFieldModal.vue')).default

const SCHEMA = {
	properties: {
		id: { type: 'string' },
		contactsUid: { type: 'string', title: 'Contacts link' },
		name: { type: 'string', title: 'Name' },
		industry: { type: 'string', title: 'Industry', enum: ['Healthcare', 'Retail'] },
		score: { type: 'number', title: 'Score', readOnly: true },
		active: { type: 'boolean', title: 'Active' },
	},
}

/**
 * A component context for the given props, with computeds as getters and
 * methods bound. The schema is injected to skip the fetch unless a test wants
 * the fetch path.
 *
 * @param {object} props The props.
 * @return {object} The context.
 */
function vm(props = {}) {
	const ctx = {
		selectedIds: ['a', 'b', 'c'],
		count: 3,
		register: 'pipelinq',
		schema: 'client',
		schemaObject: SCHEMA,
		emitted: [],
		...props,
	}
	ctx.t = (app, text) => text
	ctx.n = (app, s, p, count) => (count === 1 ? s : p).replace('%n', String(count))
	ctx.$emit = (event, payload) => ctx.emitted.push([event, payload])
	Object.assign(ctx, modal.data.call(ctx))
	for (const [name, getter] of Object.entries(modal.computed)) {
		Object.defineProperty(ctx, name, { get: () => getter.call(ctx) })
	}
	for (const [name, method] of Object.entries(modal.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	toasts.showError.mockReset()
	toasts.showSuccess.mockReset()
	bus.emit.mockReset()
	schemaFetch.mockReset()
})

afterEach(() => vi.restoreAllMocks())

describe('loading the fields', () => {
	it('lists only the writable properties, carrying the enum through', async () => {
		const form = vm()
		await form.loadSchema()
		expect(form.properties.map((p) => p.id)).toEqual(['active', 'industry', 'name'])
		expect(form.properties.find((p) => p.id === 'industry').enum).toEqual(['Healthcare', 'Retail'])
		expect(form.loadingSchema).toBe(false)
	})

	it('fetches the schema when none is injected', async () => {
		schemaFetch.mockResolvedValue(SCHEMA)
		const form = vm({ schemaObject: null })
		await form.loadSchema()
		expect(schemaFetch).toHaveBeenCalledWith('client')
		expect(form.properties.map((p) => p.id)).toContain('industry')
	})

	it('shows an error and lists nothing when the schema cannot be read', async () => {
		schemaFetch.mockRejectedValue(new Error('boom'))
		const form = vm({ schemaObject: null })
		await form.loadSchema()
		expect(toasts.showError).toHaveBeenCalled()
		expect(form.properties).toEqual([])
	})
})

describe('the value input', () => {
	it('is an enum select, a switch, a number or text, by the property', async () => {
		const form = vm()
		await form.loadSchema()
		form.onPropertyPick({ id: 'industry' })
		expect(form.valueKind).toBe('enum')
		expect(form.enumOptions).toEqual(['Healthcare', 'Retail'])
		form.onPropertyPick({ id: 'active' })
		expect(form.valueKind).toBe('boolean')
		expect(form.value).toBe(false)
		form.onPropertyPick({ id: 'name' })
		expect(form.valueKind).toBe('text')
	})

	it('types a number value as a number', async () => {
		const form = vm({ schemaObject: { properties: { age: { type: 'integer', title: 'Age' } } } })
		await form.loadSchema()
		form.onPropertyPick({ id: 'age' })
		form.value = '42'
		expect(form.typedValue()).toBe(42)
	})
})

describe('preview', () => {
	it('resolves the scope, creates a previewed job and commits nothing', async () => {
		http.get.mockResolvedValue({ data: { '@self': { register: 7, schema: 12 } } })
		http.post.mockResolvedValue({ data: { id: 9, processed: { counts: { applied: 2, skipped: 1 } } } })
		const form = vm()
		await form.loadSchema()
		form.onPropertyPick({ id: 'industry' })
		form.value = 'Healthcare'

		await form.runPreview()

		expect(http.get).toHaveBeenCalledWith('/apps/openregister/api/objects/pipelinq/client/a')
		expect(http.post).toHaveBeenCalledTimes(1)
		expect(http.post).toHaveBeenCalledWith('/apps/openregister/api/bulk-jobs', {
			action: 'openregister:set-properties',
			parameters: { properties: { industry: 'Healthcare' } },
			selection: { ids: ['a', 'b', 'c'] },
			register: 7,
			schema: 12,
		})
		expect(form.summary).toEqual({ changed: 2, skipped: 1, refused: 0, failed: 0 })
		expect(form.refusal).toBe('')
	})

	it('shows OpenRegister\'s refusal reason and writes nothing', async () => {
		http.get.mockResolvedValue({ data: { '@self': { register: 7, schema: 12 } } })
		http.post.mockRejectedValue({ response: { data: { reason: 'selection-too-large' } } })
		const form = vm()
		await form.loadSchema()
		form.onPropertyPick({ id: 'industry' })
		form.value = 'Healthcare'

		await form.runPreview()

		expect(form.refusal).toBe('selection-too-large')
		expect(form.preview).toBeNull()
	})
})

describe('confirm', () => {
	it('commits the previewed job, tells the list and closes', async () => {
		http.post.mockResolvedValue({ data: { id: 9, state: 'applied' } })
		const form = vm()
		form.preview = { id: 9, processed: { counts: { applied: 5 } } }

		await form.confirm()

		expect(http.post).toHaveBeenCalledWith('/apps/openregister/api/bulk-jobs/9/commit')
		expect(toasts.showSuccess).toHaveBeenCalled()
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(form.emitted).toContainEqual(['close', undefined])
	})

	it('does nothing without a previewed job', async () => {
		const form = vm()
		form.preview = null
		await form.confirm()
		expect(http.post).not.toHaveBeenCalled()
	})

	it('shows the refusal and keeps the dialog open when the commit fails', async () => {
		http.post.mockRejectedValue({ response: { data: { reason: 'conflict' } } })
		const form = vm()
		form.preview = { id: 9, processed: { counts: { applied: 5 } } }

		await form.confirm()

		expect(form.refusal).toBe('conflict')
		expect(form.preview).toBeNull()
		expect(form.emitted).not.toContainEqual(['close', undefined])
	})
})

describe('canPreview', () => {
	it('needs a property and a selection', () => {
		const form = vm()
		expect(form.canPreview).toBe(false)
		form.property = 'industry'
		expect(form.canPreview).toBe(true)
		form.property = ''
		expect(form.canPreview).toBe(false)
	})
})
