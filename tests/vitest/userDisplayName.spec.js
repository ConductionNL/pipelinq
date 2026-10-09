/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A user field shows the user's display name, not the uid
 * (round3-review-points, review point 2). The task list and the task page
 * showed `cluade` where the picker had shown "claude".
 *
 * Two halves: the formatter itself, and every place the manifest shows a user
 * field (an index column, an object-list column, a data widget, the default
 * columns of an index page) wired to it. User fields are read from the merged
 * register, so a new user field on a shown schema fails here until it is wired.
 *
 * @spec openspec/changes/round3-review-points/specs/user-fields/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

const authMock = vi.hoisted(() => ({ user: null }))
const axiosMock = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => authMock.user }))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (p) => `/ocs/v2.php/${p}`,
}))

const { createUserDisplayNameFormatter, fetchUserDisplayName, PENDING_LABEL } =
	await import('../../src/services/userDisplayName.js')

const FORMATTER = 'userDisplayName'
const ROOT = path.resolve(__dirname, '../..')
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('the userDisplayName formatter', () => {
	it('shows the display name once it is known, never the uid while it loads', async () => {
		const lookup = vi.fn(async () => 'claude')
		const format = createUserDisplayNameFormatter(lookup)
		expect(format('cluade')).toBe(PENDING_LABEL)
		await flush()
		expect(format('cluade')).toBe('claude')
		expect(lookup).toHaveBeenCalledTimes(1)
	})

	it('leaves an empty value empty and joins a list of users', async () => {
		const format = createUserDisplayNameFormatter(async (uid) =>
			uid.toUpperCase(),
		)
		expect(format('')).toBe('')
		expect(format(null)).toBe('')
		format(['a', 'b'])
		await flush()
		expect(format(['a', 'b'])).toBe('A, B')
	})

	it('keeps the uid when the lookup fails', async () => {
		const format = createUserDisplayNameFormatter(async () => {
			throw new Error('down')
		})
		format('ghost')
		await flush()
		expect(format('ghost')).toBe('ghost')
	})

	it('names the signed-in user without a request', async () => {
		authMock.user = { uid: 'cluade', displayName: 'claude' }
		axiosMock.get.mockReset()
		expect(await fetchUserDisplayName('cluade')).toBe('claude')
		expect(axiosMock.get).not.toHaveBeenCalled()
	})

	it("takes another user's name from core autocomplete, on an exact uid match only", async () => {
		authMock.user = { uid: 'admin', displayName: 'Admin' }
		axiosMock.get.mockResolvedValue({
			data: {
				ocs: {
					data: [
						{ id: 'cluade2', label: 'Someone else' },
						{ id: 'cluade', label: 'claude' },
					],
				},
			},
		})
		expect(await fetchUserDisplayName('cluade')).toBe('claude')
		expect(await fetchUserDisplayName('nobody')).toBe('nobody')
	})
})

/** Schema slug to its user-field names, from the register and its fragments in load order. */
function userFields() {
	const files = [readJson('lib', 'Settings', 'pipelinq_register.json')]
	const dir = path.join(ROOT, 'lib', 'Settings', 'register.d')
	fs.readdirSync(dir)
		.filter((name) => name.endsWith('.json'))
		.sort()
		.forEach((name) =>
			files.push(readJson('lib', 'Settings', 'register.d', name)),
		)
	const props = {}
	for (const file of files) {
		for (const [slug, schema] of Object.entries(
			file.components?.schemas || {},
		)) {
			for (const [key, prop] of Object.entries(schema.properties || {})) {
				props[slug] = props[slug] || {}
				props[slug][key] = { ...(props[slug][key] || {}), ...prop }
			}
		}
	}
	const isUser = (p) =>
		!!p
		&& (p.referenceType === 'nextcloud-user'
			|| p.widget === 'user'
			|| ['user', 'username', 'nc-user'].includes(p.format || ''))
	const users = {}
	for (const [slug, fields] of Object.entries(props)) {
		const found = Object.entries(fields)
			.filter(([, p]) => isUser(p) || (p.type === 'array' && isUser(p.items)))
			.map(([key]) => key)
		if (found.length > 0) {
			users[slug] = found
		}
	}
	return users
}

/** Every place a manifest document shows a user field without the formatter. */
function unwired(users) {
	const docs = [['src/manifest.json', readJson('src', 'manifest.json')]]
	fs.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
		.filter((name) => name.endsWith('.json'))
		.forEach((name) =>
			docs.push([
				`src/manifest.d/${name}`,
				readJson('src', 'manifest.d', name),
			]),
		)
	const key = (c) => (typeof c === 'string' ? c : c?.key)
	const wired = (c) =>
		c && typeof c === 'object' && (c.formatter === FORMATTER || !!c.widget)
	const found = []

	const walk = (file, node, schema) => {
		if (Array.isArray(node)) {
			node.forEach((child) => walk(file, child, schema))
			return
		}
		if (!node || typeof node !== 'object') {
			return
		}
		const config =
			node.config && typeof node.config === 'object' ? node.config : null
		const pageSchema = config?.schema || schema
		if (config && node.type === 'index') {
			const fields = users[pageSchema] || []
			if (Array.isArray(config.columns)) {
				for (const column of config.columns) {
					if (fields.includes(key(column)) && !wired(column)) {
						found.push(`${file} ${node.id} column ${key(column)}`)
					}
				}
			} else {
				for (const field of fields) {
					if (config.columnOverrides?.[field]?.formatter !== FORMATTER) {
						found.push(`${file} ${node.id} default column ${field}`)
					}
				}
			}
		}
		if (node.type === 'data' && node.content) {
			const fields = users[node.content.schema || schema] || []
			const shown =
				node.content.include
				|| fields.filter((f) => !(node.content.exclude || []).includes(f))
			for (const field of fields) {
				if (
					shown.includes(field)
					&& node.content.overrides?.[field]?.formatter !== FORMATTER
				) {
					found.push(`${file} ${node.id} data ${field}`)
				}
			}
		}
		if (
			(node.type === 'object-list' || node.type === 'object-table')
			&& node.content
		) {
			const fields = users[node.content.schema] || []
			for (const column of node.content.columns || []) {
				if (fields.includes(key(column)) && !wired(column)) {
					found.push(`${file} ${node.id} list column ${key(column)}`)
				}
			}
		}
		for (const [k, v] of Object.entries(node)) {
			if (k !== 'properties') {
				walk(file, v, pageSchema)
			}
		}
	}

	for (const [file, doc] of docs) {
		walk(file, doc, null)
	}
	return found
}

describe('the manifest shows user fields by name', () => {
	const users = userFields()

	it('reads the user fields the review named', () => {
		expect(users.crmTask).toEqual(
			expect.arrayContaining(['assigneeUserId', 'createdBy']),
		)
		expect(users.lead).toContain('assignee')
	})

	it('wires every shown user field to the formatter', () => {
		expect(unwired(users)).toEqual([])
	})

	it('registers the formatter with the app', async () => {
		const { createAppFormatters } =
			await import('../../src/services/cellFormatters.js')
		const formatters = createAppFormatters({ fetchObject: async () => null })
		expect(typeof formatters[FORMATTER]).toBe('function')
	})
})
