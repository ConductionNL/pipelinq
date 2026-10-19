/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Retired page addresses redirect to the default view that replaced them.
 *
 * @spec openspec/changes/queue-as-default-view/specs/retire-queue-concept/spec.md
 */
import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { RETIRED_ROUTES, retiredRedirects } from '../../src/retiredRoutes.js'

const Stub = { render: () => null }

describe('retiredRedirects', () => {
	it('redirects /queue to the queue view on All tickets', () => {
		expect(retiredRedirects([{ id: 'Tickets' }])).toEqual([
			{ path: '/queue', redirect: { name: 'Tickets', query: { savedView: 'default:queue' } } },
		])
	})

	it('leaves out a redirect whose target page is missing', () => {
		expect(retiredRedirects([{ id: 'Clients' }])).toEqual([])
		expect(retiredRedirects(undefined)).toEqual([])
	})

	it('hands out copies, so a router cannot change the table', () => {
		const [route] = retiredRedirects([{ id: 'Tickets' }])
		route.redirect.query.savedView = 'changed'
		expect(RETIRED_ROUTES[0].redirect.query.savedView).toBe('default:queue')
	})

	it('lands a router on the view', async () => {
		const router = createRouter({
			history: createMemoryHistory(),
			routes: [
				{ name: 'Tickets', path: '/tickets', component: Stub },
				...retiredRedirects([{ id: 'Tickets' }]),
			],
		})
		await router.push('/queue')
		expect(router.currentRoute.value.name).toBe('Tickets')
		expect(router.currentRoute.value.query).toEqual({ savedView: 'default:queue' })
	})
})
