/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A pipeline stage with a limit refuses a third item (pipeline-stage-limit
 * tasks 1.4 and 1.5): the board header reads `count / max` and marks the
 * column full, a drop into the full column is refused with the server's
 * message and the card stays where it was, the lead edit form is held to the
 * same limit, and moving out of the full column still works.
 *
 *   @e2e pipeline-stage-limit::a-stage-above-its-limit-stays-marked-full
 *   @e2e pipeline-stage-limit::a-drop-into-a-full-column-is-refused-and-explained
 *   @e2e pipeline-stage-limit::the-edit-form-is-held-to-the-same-limit
 *   @e2e pipeline-stage-limit::moving-out-of-a-full-stage-works
 *
 * A run-unique pipeline is seeded with a stage "Proposal" limited to 2 and an
 * open stage "Qualified" without a limit, two leads in Proposal and one in
 * Qualified. The refusal is StageLimitGuard's "Stage %1$s is full (%2$s of
 * %3$s)"; the board shows it as an error toast (PipelineBoard.vue
 * moveItemToStage), the edit form as its save error. Task 1.5 leaves open
 * whether the form shows it under the stage field itself; that placement is
 * a soft assertion, the refusal itself is a hard one.
 *
 * @spec openspec/changes/pipeline-stage-limit/specs/pipeline-stage-limits/spec.md#requirement-a-stage-can-carry-a-limit-req-psl-001
 * @spec openspec/changes/pipeline-stage-limit/specs/pipeline-stage-limits/spec.md#requirement-no-write-puts-an-item-into-a-full-stage-req-psl-002
 */
import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseUrl } from './base-url.ts'
import { gotoAppRoute } from './helpers/pipelinq.ts'

const BASE_URL = resolveBaseUrl()
const RUN = Date.now().toString(36)
const OR = '/index.php/apps/openregister/api/objects/pipelinq'
const ADMIN = {
	username: process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin',
	password: process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin',
}
const HEADERS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }
const FULL = /Stage Proposal is full \(2 of 2\)|Fase Proposal is vol \(2 van 2\)/
const PIPELINE_TITLE = `E2E-PSL-${RUN} sales`

let admin: APIRequestContext
let pipelineId = ''
const leads: Record<string, string> = {}

/**
 * The id of an object as OpenRegister returns it.
 *
 * @param body The response body.
 */
function idOf(body: Record<string, unknown>): string {
	const self = body['@self'] as { id?: string } | undefined
	return String(body.id ?? self?.id ?? body.uuid)
}

/**
 * Read a lead's stored stage.
 *
 * @param key The seeded lead's key.
 */
async function storedStage(key: string): Promise<string> {
	const read = await admin.get(`${OR}/lead/${leads[key]}`)
	return String((await read.json()).stage ?? '')
}

/**
 * The board column of a stage.
 *
 * @param page The page.
 * @param stage The stage name.
 */
function column(page: Page, stage: string): Locator {
	return page
		.locator('.kanban-column')
		.filter({ has: page.locator('.column-title', { hasText: stage }) })
}

/**
 * Drag a card onto another column with real mouse steps (the columns are
 * SortableJS lists, which ignore a synthetic drop).
 *
 * @param page The page.
 * @param card The card.
 * @param target The column to drop on.
 */
async function drag(page: Page, card: Locator, target: Locator): Promise<void> {
	const from = await card.boundingBox()
	const to = await target.locator('.kanban-column__body').boundingBox()
	expect(from && to, 'card and column are on screen').toBeTruthy()
	if (!from || !to) {
		return
	}
	await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2)
	await page.mouse.down()
	await page.mouse.move(
		from.x + from.width / 2 + 10,
		from.y + from.height / 2 + 10,
		{ steps: 5 },
	)
	await page.mouse.move(to.x + to.width / 2, to.y + 40, { steps: 20 })
	await page.mouse.move(to.x + to.width / 2, to.y + 50, { steps: 5 })
	await page.mouse.up()
}

/**
 * Open the board on the seeded pipeline.
 *
 * @param page The page.
 */
async function openBoard(page: Page): Promise<void> {
	await gotoAppRoute(page, '/pipeline')
	await page
		.getByTestId('pipeline-select')
		.locator('select')
		.selectOption(pipelineId)
	await expect(column(page, 'Proposal')).toBeVisible({ timeout: 30_000 })
}

test.beforeAll(async () => {
	admin = await request.newContext({
		baseURL: BASE_URL,
		httpCredentials: ADMIN,
		extraHTTPHeaders: HEADERS,
	})
	const pipeline = await admin.post(`${OR}/pipeline`, {
		data: {
			title: PIPELINE_TITLE,
			stages: [
				{ name: 'Qualified', order: 1, probability: 20 },
				{ name: 'Proposal', order: 2, probability: 50, maxItems: 2 },
				{
					name: 'Won',
					order: 3,
					probability: 100,
					isClosed: true,
					isWon: true,
				},
			],
		},
	})
	expect(
		pipeline.ok(),
		`creating the pipeline: ${pipeline.status()} ${(await pipeline.text()).slice(0, 300)}`,
	).toBeTruthy()
	pipelineId = idOf(await pipeline.json())

	for (const [key, stage, stageOrder] of [
		['p1', 'Proposal', 2],
		['p2', 'Proposal', 2],
		['q1', 'Qualified', 1],
	] as const) {
		const lead = await admin.post(`${OR}/lead`, {
			data: {
				title: `E2E-PSL-${RUN} ${key}`,
				pipeline: pipelineId,
				stage,
				stageOrder,
				status: 'open',
			},
		})
		expect(
			lead.ok(),
			`creating lead ${key}: ${lead.status()} ${(await lead.text()).slice(0, 300)}`,
		).toBeTruthy()
		leads[key] = idOf(await lead.json())
	}
})

test.afterAll(async () => {
	for (const id of Object.values(leads)) {
		await admin.delete(`${OR}/lead/${id}`).catch(() => undefined)
	}
	if (pipelineId !== '') {
		await admin.delete(`${OR}/pipeline/${pipelineId}`).catch(() => undefined)
	}
	await admin.dispose()
})

test.describe.serial('stage limit', () => {
	test.setTimeout(150_000)

	test('the full column reads 2 / 2 and a drop into it is refused and explained', async ({
		page,
	}) => {
		await openBoard(page)

		const proposal = column(page, 'Proposal')
		const count = proposal.getByTestId('column-count')
		await expect(count).toHaveText('2 / 2')
		await expect(count).toHaveClass(/column-count--full/)
		await expect(
			column(page, 'Qualified').getByTestId('column-count'),
		).toHaveText('1')

		const card = column(page, 'Qualified').locator('.pipeline-card', {
			hasText: `E2E-PSL-${RUN} q1`,
		})
		await expect(card).toBeVisible()
		await drag(page, card, proposal)

		await expect(
			page.locator('.toastify').filter({ hasText: FULL }),
		).toBeVisible({ timeout: 15_000 })
		await expect(
			column(page, 'Qualified').locator('.pipeline-card', {
				hasText: `E2E-PSL-${RUN} q1`,
			}),
		).toBeVisible()
		await expect(count).toHaveText('2 / 2')
		expect(await storedStage('q1')).toBe('Qualified')
	})

	test('the lead edit form is held to the same limit', async ({ page }) => {
		await gotoAppRoute(page, `/leads/${leads.q1}`)

		const edit = page.getByTestId('cn-detail-page-edit')
		if (await edit.isVisible({ timeout: 15_000 }).catch(() => false)) {
			await edit.click()
		} else {
			await page
				.getByRole('button', { name: /^(Actions|Acties)$/ })
				.first()
				.click()
			await page.getByRole('menuitem', { name: /^(Edit|Bewerken)$/ }).click()
		}

		const dialog = page
			.getByRole('dialog')
			.filter({ has: page.locator('[data-cn-field="stage"]') })
		await expect(dialog).toBeVisible({ timeout: 20_000 })
		const stageField = dialog.locator('[data-cn-field="stage"]')
		const combobox = stageField.getByRole('combobox')
		if ((await combobox.count()) > 0) {
			await combobox.first().click()
			await combobox.first().fill('Proposal')
			await page.getByRole('option', { name: 'Proposal' }).first().click()
		} else {
			await stageField.locator('input, select').first().fill('Proposal')
		}
		await dialog.getByRole('button', { name: /^(Save|Opslaan)$/ }).click()

		await expect(page.getByText(FULL).first()).toBeVisible({ timeout: 15_000 })
		await expect
			.soft(
				stageField.getByText(FULL),
				'task 1.5: the message sits under the stage field',
			)
			.toBeVisible()
		expect(await storedStage('q1')).toBe('Qualified')
	})

	test('moving out of the full column works', async ({ page }) => {
		await openBoard(page)

		const card = column(page, 'Proposal').locator('.pipeline-card', {
			hasText: `E2E-PSL-${RUN} p1`,
		})
		await drag(page, card, column(page, 'Qualified'))

		await expect(
			column(page, 'Proposal').getByTestId('column-count'),
		).toHaveText('1 / 2', { timeout: 15_000 })
		await expect(
			column(page, 'Proposal').getByTestId('column-count'),
		).not.toHaveClass(/column-count--full/)
		await expect
			.poll(() => storedStage('p1'), { timeout: 15_000 })
			.toBe('Qualified')
	})
})
