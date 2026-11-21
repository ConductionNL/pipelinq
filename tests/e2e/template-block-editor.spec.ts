/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A blocks template survives a save: the blocks come back in the order they
 * were saved, with their content (marketing-block-editor task 2.2).
 *
 *   @e2e mail-block-editor::a-marketer-builds-a-newsletter-without-html
 *   @e2e mail-block-editor::the-preview-follows-an-edit
 *
 * `mail-block-editor.spec.ts` covers the keyboard move and the HTML template.
 * This one covers the round trip: a heading, a text, a button and the articles
 * block are created, saved, and read back after reopening, in that order, and
 * the preview shows the button label.
 *
 * Written from the change's scenarios; not yet run (it needs a seeded instance).
 *
 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
 */
import type { Locator } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { gotoAppRoute } from './helpers/pipelinq.ts'

const STAMP = Date.now().toString(36)
const NAME = `E2E round trip ${STAMP}`
const BLOCK_TYPES = [
	'heading',
	'text',
	'image',
	'button',
	'articles',
	'divider',
	'spacer',
]

/**
 * The block types in the editor's list, top to bottom, leaving out the palette
 * buttons and the fixed footer.
 *
 * @param editor The block editor.
 */
async function blockOrder(editor: Locator): Promise<string[]> {
	const ids = await editor
		.locator('[data-testid^="mail-block-"]')
		.evaluateAll((nodes) =>
			nodes.map((node) => node.getAttribute('data-testid') ?? ''),
		)
	return ids
		.map((id) => id.replace(/^mail-block-/, ''))
		.filter((type) => BLOCK_TYPES.includes(type))
}

test.describe('template block editor round trip', () => {
	test.setTimeout(180_000)

	test('a heading, a text, a button and the articles block come back in order after a save', async ({
		page,
	}) => {
		await gotoAppRoute(page, '/templates')
		await page.getByTestId('cn-cta-primary').click()
		const editor = page.getByTestId('template-block-editor')
		await expect(editor).toBeVisible({ timeout: 30_000 })

		await page.getByLabel('Template name').fill(NAME)

		// A new template starts with a heading, a text and the footer.
		await editor
			.getByTestId('mail-block-heading')
			.locator('.mail-block-editor__select')
			.click()
		await page.getByLabel('Heading text').fill(`Autumn news ${STAMP}`)

		// It also starts with a text block, below the heading.
		await editor
			.getByTestId('mail-block-text')
			.locator('.mail-block-editor__select')
			.click()
		const text = page.getByLabel('Text', { exact: true })
		await text.click()
		await page.keyboard.type(`Our opening hours change ${STAMP}`)

		await editor.getByTestId('mail-block-add-button').click()
		await page.getByLabel('Button text').fill('Read more')
		await page.getByLabel('Link (https://)').fill('https://www.example.nl')

		await editor.getByTestId('mail-block-add-articles').click()

		const before = await blockOrder(editor)
		expect(before).toEqual(['heading', 'text', 'button', 'articles'])

		await page.getByRole('button', { name: 'Preview' }).click()
		const preview = page.frameLocator('iframe.template-form__preview')
		await expect(preview.getByText(`Autumn news ${STAMP}`)).toBeVisible({
			timeout: 15_000,
		})
		await expect(preview.getByRole('link', { name: 'Read more' })).toBeVisible()

		await page
			.getByLabel('Physical address')
			.fill('Voorbeeldstraat 1, 1234 AB Zuiddrecht')
		await page.getByRole('button', { name: 'Create template' }).click()
		await expect(page.getByText(NAME).first()).toBeVisible({ timeout: 30_000 })

		// Reopen and read the same blocks back, in the same order.
		await page.getByText(NAME).first().click()
		await page.getByRole('button', { name: 'Edit' }).first().click()
		const reopened = page.getByTestId('template-block-editor')
		await expect(reopened).toBeVisible({ timeout: 30_000 })
		expect(await blockOrder(reopened)).toEqual(before)

		await reopened
			.getByTestId('mail-block-heading')
			.locator('.mail-block-editor__select')
			.click()
		await expect(page.getByLabel('Heading text')).toHaveValue(
			`Autumn news ${STAMP}`,
		)
		await reopened
			.getByTestId('mail-block-button')
			.locator('.mail-block-editor__select')
			.click()
		await expect(page.getByLabel('Button text')).toHaveValue('Read more')
		await expect(page.getByLabel('Link (https://)')).toHaveValue(
			'https://www.example.nl',
		)
	})
})
