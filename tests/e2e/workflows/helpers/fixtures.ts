/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Seeded-fixture helper for the DEEP, data-dependent workflow suite.
 *
 * Pipelinq objects live in the OpenRegister register configured for the app
 * (`occ config:app:get pipelinq register` → 16 on the dev box). The generic OR
 * object API is:
 *
 *   GET    /apps/openregister/api/objects/{register}/{schema}
 *   POST   /apps/openregister/api/objects/{register}/{schema}
 *   PUT    /apps/openregister/api/objects/{register}/{schema}/{id}
 *   DELETE /apps/openregister/api/objects/{register}/{schema}/{id}
 *
 * Schemas are addressed by their canonical slug (client, product,
 * posTransaction, …) — the OR router resolves the slug against the register, so
 * the helper never needs the numeric schema id (which is not even present in
 * the app-config for the POS schemas). All requests are issued from inside the
 * authenticated browser context via `page.evaluate(fetch …)` so they ride the
 * same session + `OC.requestToken` the real app uses; there is no second auth
 * path to keep in sync.
 *
 * Every seeded object is tagged with a unique, run-scoped name prefix
 * (`E2E-DEEP-<runId>-…`). `cleanupFixtures()` deletes exactly the objects this
 * run created (tracked by id), so a crashed run never leaves the register dirty
 * and a parallel run never deletes another run's data.
 */
import { Page } from '@playwright/test'

/** The OpenRegister register id that holds pipelinq objects (dev box: 16). */
export const PIPELINQ_REGISTER = process.env.PIPELINQ_REGISTER || '16'

/** Stable, unique prefix for everything this run creates. */
export const RUN_ID = `${Date.now().toString(36)}-${Math.floor(Math.random() * 1e4)}`
export const TEST_PREFIX = `E2E-DEEP-${RUN_ID}`

/** A created object we are responsible for deleting in afterAll. */
interface TrackedObject {
	schema: string
	id: string
}

/**
 * A fixture session bound to one authenticated Playwright page. Tracks every
 * object it creates so cleanup is exact.
 */
export class FixtureSession {
	private readonly page: Page
	private readonly tracked: TrackedObject[] = []

	constructor(page: Page) {
		this.page = page
	}

	/** Build the OR objects endpoint for a schema slug (+ optional id / query). */
	private url(schema: string, idOrQuery = ''): string {
		const base = `/index.php/apps/openregister/api/objects/${PIPELINQ_REGISTER}/${schema}`
		if (!idOrQuery) return base
		if (idOrQuery.startsWith('?')) return base + idOrQuery
		return `${base}/${idOrQuery}`
	}

	/**
	 * Issue an authenticated OR API request from inside the browser context.
	 * Runs in the page so `OC.requestToken` + the session cookie are present.
	 */
	private async apiFetch(method: string, path: string, body?: unknown): Promise<any> {
		return await this.page.evaluate(
			async ({ method, path, body }) => {
				const res = await fetch(path, {
					method,
					headers: {
						'Content-Type': 'application/json',
						// eslint-disable-next-line no-undef
						requesttoken: (window as any).OC?.requestToken || '',
						'OCS-APIREQUEST': 'true',
					},
					body: body === undefined ? undefined : JSON.stringify(body),
				})
				const text = await res.text()
				let json: any = null
				try { json = text ? JSON.parse(text) : null } catch { /* non-JSON */ }
				return { ok: res.ok, status: res.status, json, text: text.slice(0, 300) }
			},
			{ method, path, body },
		)
	}

	/**
	 * Create an object of `schema` with the given properties. Returns the
	 * created object (incl. its server id). Throws on a non-2xx so a broken
	 * fixture fails loudly rather than silently seeding nothing.
	 */
	async create(schema: string, props: Record<string, unknown>): Promise<any> {
		const res = await this.apiFetch('POST', this.url(schema), props)
		if (!res.ok || !res.json) {
			throw new Error(`fixture create(${schema}) failed: ${res.status} ${res.text}`)
		}
		const obj = res.json
		const id = obj.id || obj['@self']?.id || obj.uuid
		if (id) this.tracked.push({ schema, id: String(id) })
		return obj
	}

	/** Fetch the collection for a schema with optional query params. */
	async list(schema: string, params: Record<string, string | number> = {}): Promise<any[]> {
		const qs = new URLSearchParams()
		for (const [k, v] of Object.entries(params)) qs.set(k, String(v))
		const res = await this.apiFetch('GET', this.url(schema, qs.toString() ? `?${qs}` : ''))
		if (!res.ok) throw new Error(`fixture list(${schema}) failed: ${res.status} ${res.text}`)
		return res.json?.results || res.json || []
	}

	/** Fetch a single object by id. */
	async get(schema: string, id: string): Promise<any> {
		const res = await this.apiFetch('GET', this.url(schema, id))
		if (!res.ok) throw new Error(`fixture get(${schema}/${id}) failed: ${res.status} ${res.text}`)
		return res.json
	}

	/**
	 * Update an object via the same `PUT /objects/{reg}/{schema}/{id}` verb the
	 * app's own store.saveObject() uses (the manifest list does not expose an
	 * in-UI edit path for these schemas). Merges `patch` onto the current object
	 * and returns the updated object. Returns null on a non-2xx.
	 */
	async update(schema: string, id: string, patch: Record<string, unknown>): Promise<any | null> {
		const current = await this.get(schema, id)
		const merged = { ...current, ...patch, id }
		// Strip OR-internal metadata so the PUT body mirrors a real edit payload.
		delete (merged as any)['@self']
		const res = await this.apiFetch('PUT', this.url(schema, id), merged)
		return res.ok ? res.json : null
	}

	/** Convenience: rename an object's `name` field. Returns true on success. */
	async apiUpdateName(schema: string, id: string, name: string): Promise<boolean> {
		const res = await this.update(schema, id, { name })
		return res !== null
	}

	/**
	 * Track an object this run is responsible for cleaning up, even if it was
	 * created through the UI rather than the API (so afterAll deletes it).
	 */
	track(schema: string, id: string): void {
		if (id) this.tracked.push({ schema, id: String(id) })
	}

	/** Delete one object now (best-effort) and drop it from the tracked list. */
	async remove(schema: string, id: string): Promise<void> {
		await this.apiFetch('DELETE', this.url(schema, id)).catch(() => undefined)
		const i = this.tracked.findIndex(t => t.schema === schema && t.id === String(id))
		if (i >= 0) this.tracked.splice(i, 1)
	}

	/**
	 * Delete every object this run created. Best-effort: a failed delete is
	 * logged but never throws, so one stuck object cannot fail the suite or
	 * block the rest of the cleanup. Children (lines) are deleted before parents
	 * by reverse-insertion order.
	 */
	async cleanup(): Promise<void> {
		for (const { schema, id } of [...this.tracked].reverse()) {
			const res = await this.apiFetch('DELETE', this.url(schema, id)).catch(() => undefined)
			if (!res || !res.ok) {
				// eslint-disable-next-line no-console
				console.warn(`[fixtures] cleanup failed for ${schema}/${id}: ${res?.status ?? 'no-response'}`)
			}
		}
		this.tracked.length = 0
	}

	/** Number of objects still tracked for cleanup (test introspection). */
	get trackedCount(): number {
		return this.tracked.length
	}
}
