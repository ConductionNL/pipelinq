// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Round-4 cloud check, pipelinq items 1 to 5.
 *
 * 1. "Add contact person" on a client's Contacts tab came back 400 "The
 *    required property (contactsUid) is missing": the library widget POSTs
 *    the form straight to OpenRegister. The tab now uses a widget whose
 *    create provisions the Nextcloud contact first.
 * 3. The "Task changed" notification linked to OpenRegister's generic object
 *    view: the manifest declared no deep link for crmTask.
 * 4, 5. A task's name was its uuid (no objectNameField, and `subject` is not
 *    in OpenRegister's name fallback), so the activity read "Task <uuid>
 *    updated" and the page heading fell back to "Task".
 *
 * @spec openspec/changes/round5-contact-create-and-task-links/specs/client-management/spec.md
 * @spec openspec/changes/round5-contact-create-and-task-links/specs/notifications-activity/spec.md
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const libraryCreate = vi.fn()
vi.mock('@conduction/nextcloud-vue', () => ({
	CnObjectListWidget: {
		name: 'CnObjectListWidget',
		methods: { onCreateConfirm: libraryCreate },
	},
}))
const createWithContact = vi.fn()
vi.mock('../../src/services/contactSyncApi.js', () => ({ createWithContact }))

const { default: ContactAwareObjectListWidget } =
	await import('../../src/components/widgets/ContactAwareObjectListWidget.js')

const ROOT = path.resolve(__dirname, '../..')
/**
 * Read a JSON file under the app root.
 *
 * @param {...string} parts Path parts.
 * @return {object} The parsed JSON.
 */
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}
const manifest = readJson('src', 'manifest.json')

/**
 * Deep-merge like OpenRegister's RegisterDocumentLoader::deepMergeConfig.
 *
 * @param {object} base The base.
 * @param {object} overlay The fragment.
 * @return {object} The base, merged in place.
 */
function merge(base, overlay) {
	const isObject = (v) => v && typeof v === 'object' && !Array.isArray(v)
	for (const [key, value] of Object.entries(overlay)) {
		if (isObject(value) && isObject(base[key])) {
			merge(base[key], value)
		} else {
			base[key] = value
		}
	}
	return base
}

/** The register as OpenRegister imports it: base plus every fragment. */
function mergedRegister() {
	const register = readJson('lib', 'Settings', 'pipelinq_register.json')
	const dir = path.join(ROOT, 'lib', 'Settings', 'register.d')
	fs.readdirSync(dir)
		.filter((name) => name.endsWith('.json'))
		.sort()
		.forEach((name) =>
			merge(register, readJson('lib', 'Settings', 'register.d', name)),
		)
	return register
}

/** Every widget definition in the manifest, with its page id. */
function widgets() {
	const out = []
	for (const page of manifest.pages) {
		for (const widget of page.config?.widgets || []) {
			out.push({ page: page.id, widget })
		}
	}
	return out
}

/**
 * A fake widget instance for the create method.
 *
 * @param {string} schema The list's schema.
 * @return {object} The instance.
 */
function instance(schema) {
	return {
		content: { register: 'pipelinq', schema },
		resolvedFilter: { client: 'client-1' },
		$refs: { createDialog: { setResult: vi.fn() } },
		$emit: vi.fn(),
		fetchRows: vi.fn(),
	}
}

const create = ContactAwareObjectListWidget.methods.onCreateConfirm

describe('adding a contact person on a client page', () => {
	beforeEach(() => {
		createWithContact.mockReset()
		libraryCreate.mockReset()
	})

	it('the Contacts tab on the client page uses the contact-aware list', () => {
		const tab = widgets().find((w) => w.widget.id === 'client-quick-contacts')
		expect(tab.widget.type).toBe('ContactAwareObjectList')
		expect(tab.widget.content.allowCreate).toBe(true)
		const registry = fs.readFileSync(
			path.join(ROOT, 'src', 'registry.js'),
			'utf8',
		)
		expect(registry).toMatch(
			/ContactAwareObjectList: \{\n\t\tkind: 'widget',\n\t\tcomponent: ContactAwareObjectListWidget,/,
		)
	})

	it('no client or contact list offers the library create, which cannot provision contactsUid', () => {
		const offenders = widgets()
			.filter(
				({ widget }) =>
					widget.type === 'object-list'
					&& ['client', 'contact'].includes(widget.content?.schema)
					&& widget.content?.allowCreate !== false,
			)
			.map(({ page, widget }) => `${page}/${widget.id}`)
		expect(offenders).toEqual([])
	})

	it('extends the library widget, so it draws the same list and dialog', () => {
		expect(ContactAwareObjectListWidget.extends.name).toBe('CnObjectListWidget')
	})

	it('creates through the contact-first path, linked to the client', async () => {
		createWithContact.mockResolvedValue({ id: 'new' })
		const vm = instance('contact')
		await create.call(vm, { name: 'Ruub', role: 'Architect' })
		expect(createWithContact).toHaveBeenCalledWith('contact', {
			name: 'Ruub',
			role: 'Architect',
			client: 'client-1',
		})
		expect(vm.$refs.createDialog.setResult).toHaveBeenCalledWith({
			success: true,
		})
		expect(vm.fetchRows).toHaveBeenCalled()
		expect(libraryCreate).not.toHaveBeenCalled()
	})

	it('a failed create shows the backend message and never success', async () => {
		createWithContact.mockRejectedValue(
			Object.assign(new Error('Request failed with status code 400'), {
				response: { data: { error: 'Name is required' } },
			}),
		)
		const vm = instance('contact')
		await create.call(vm, { name: '' })
		expect(vm.$refs.createDialog.setResult.mock.calls).toEqual([
			[{ error: 'Name is required' }],
		])
		expect(vm.$emit).not.toHaveBeenCalled()
	})

	it('any other schema keeps the library create', async () => {
		const vm = instance('ticket')
		await create.call(vm, { title: 'x' })
		expect(libraryCreate).toHaveBeenCalledWith({ title: 'x' })
		expect(createWithContact).not.toHaveBeenCalled()
	})
})

describe('notification links and task names', () => {
	it('every schema that sends a notification and has a detail page has a deep link to it', () => {
		const register = mergedRegister()
		const notifying = Object.entries(register.components.schemas)
			.filter(([, schema]) => schema['x-openregister-notifications'])
			.map(([key, schema]) => schema.slug || key)
		const detailRoute = {}
		for (const page of manifest.pages) {
			if ((page.route || '').endsWith('/:id') && page.config?.schema) {
				detailRoute[page.config.schema] = page.route
			}
		}
		const links = Object.fromEntries(
			manifest.deepLinks
				.filter((l) => l.registerSlug === 'pipelinq')
				.map((l) => [l.schemaSlug, l.urlTemplate]),
		)
		const expected = {}
		const actual = {}
		for (const slug of notifying.filter((s) => detailRoute[s])) {
			expected[slug] =
				'/apps/pipelinq' + detailRoute[slug].replace(':id', '{uuid}')
			actual[slug] = links[slug]
		}
		expect(expected.crmTask).toBe('/apps/pipelinq/tasks/{uuid}')
		expect(actual).toEqual(expected)
	})

	it('a task is named after its subject', () => {
		const task = mergedRegister().components.schemas.crmTask
		expect(task.configuration.objectNameField).toBe('subject')
		expect(task.properties.subject.type).toBe('string')
		// The lifecycle the base declares survives the overlay.
		expect(task.configuration['x-openregister-lifecycle'].field).toBe('status')
	})
})
