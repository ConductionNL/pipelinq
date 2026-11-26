/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The fixed back office sample of the accessibility suite (design D2 of
 * platform-accessibility-statement): the fourteen daily pages, with the
 * records the four detail pages open seeded through OpenRegister and removed
 * afterwards.
 *
 * @spec openspec/changes/platform-accessibility-statement/specs/accessibility-conformance/spec.md#requirement-the-back-office-is-checked-for-wcag-21-aa-on-every-nightly-run-req-pas-001
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const OBJECTS = '/index.php/apps/openregister/api/objects/pipelinq'
const ADMIN = {
	username: process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin',
	password: process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin',
}

/** One sample page: the name the known-issues list uses and its app route. */
export interface SamplePage {
	name: string
	route: string
}

/** The records a sample run seeded, by schema. */
export interface SampleRecords {
	client: string
	contact: string
	ticket: string
	lead: string
}

/**
 * The fourteen pages, in the order of design D2. Detail routes take the ids
 * of the seeded records.
 *
 * @param ids The seeded records.
 */
export function samplePages(ids: SampleRecords): SamplePage[] {
	return [
		{ name: 'Dashboard', route: '/' },
		{ name: 'KccWerkplek', route: '/werkplek' },
		{ name: 'MyWork', route: '/my-work' },
		{ name: 'Queue', route: '/queue' },
		{ name: 'Tickets', route: '/tickets' },
		{ name: 'TicketDetail', route: `/tickets/${ids.ticket}` },
		{ name: 'Clients', route: '/clients' },
		{ name: 'ClientDetail', route: `/clients/${ids.client}` },
		{ name: 'Contacts', route: '/contacts' },
		{ name: 'ContactDetail', route: `/contacts/${ids.contact}` },
		{ name: 'Leads', route: '/leads' },
		{ name: 'LeadDetail', route: `/leads/${ids.lead}` },
		{ name: 'Pipeline', route: '/pipeline' },
		{ name: 'Tasks', route: '/tasks' },
	]
}

/** Seeds the sample's records and removes them again. */
export class BackofficeSample {
	private api: APIRequestContext | null = null
	private made: Array<{ schema: string; id: string }> = []

	/**
	 * Seed one client, a contact on it, a ticket and a lead.
	 *
	 * @param run A run stamp, so parallel runs do not share names.
	 */
	async seed(run: string): Promise<SampleRecords> {
		this.api = await request.newContext({
			baseURL: resolveBaseUrl(),
			httpCredentials: ADMIN,
			extraHTTPHeaders: {
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
			},
		})
		const client = await this.create('client', {
			name: `E2E-A11Y-${run} client`,
			type: 'organization',
			email: `a11y-${run}@example.nl`,
			// Required since clients and contacts carry their Contacts card uid.
			contactsUid: `e2e-a11y-${run}-client`,
		})
		const contact = await this.create('contact', {
			name: `E2E-A11Y-${run} contact`,
			client,
			contactsUid: `e2e-a11y-${run}-contact`,
		})
		const ticket = await this.create('ticket', {
			title: `E2E-A11Y-${run} ticket`,
			ticketType: 'request',
			client,
			channel: 'phone',
			priority: 'normal',
		})
		// A lead sits on a pipeline; take the instance's first one.
		const pipelines = await (await this.api.get(`${OBJECTS}/pipeline`, { params: { _limit: '1' } })).json()
		const pipeline = (pipelines.results ?? [])[0]
		expect(pipeline, 'the instance has a pipeline to put the sample lead on').toBeTruthy()
		const lead = await this.create('lead', {
			title: `E2E-A11Y-${run} lead`,
			client,
			pipeline: String(pipeline.id ?? pipeline['@self']?.id),
			status: 'open',
		})
		return { client, contact, ticket, lead }
	}

	/** Remove what `seed()` made, newest first. */
	async dispose(): Promise<void> {
		if (!this.api) {
			return
		}
		for (const { schema, id } of this.made.reverse()) {
			await this.api.delete(`${OBJECTS}/${schema}/${id}`)
		}
		await this.api.dispose()
		this.api = null
	}

	/**
	 * Create one object and remember it.
	 *
	 * @param schema The pipelinq schema.
	 * @param data The fields.
	 */
	private async create(
		schema: string,
		data: Record<string, unknown>,
	): Promise<string> {
		const res = await (this.api as APIRequestContext).post(
			`${OBJECTS}/${schema}`,
			{ data },
		)
		expect(
			res.ok(),
			`seeding ${schema}: ${res.status()} ${(await res.text()).slice(0, 300)}`,
		).toBeTruthy()
		const body = await res.json()
		const id = String(body.id ?? body['@self']?.id ?? body.uuid)
		this.made.push({ schema, id })
		return id
	}
}
