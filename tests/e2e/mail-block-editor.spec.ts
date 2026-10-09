/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Build an email template from blocks (marketing-block-editor).
 *
 * The parts that fail silently: blocks that are saved but come back in a
 * different order, a move that loses keyboard focus, and an HTML template
 * that suddenly opens as blocks.
 *
 * @e2e mail-block-editor::a-marketer-builds-a-newsletter-without-html
 * @e2e mail-block-editor::a-marketer-reorders-blocks-with-the-keyboard
 * @e2e mail-block-editor::the-preview-follows-an-edit
 * @e2e mail-block-editor::an-existing-html-template-opens-as-before
 * @e2e mail-block-editor::reply-to-is-kept
 */
import { expect, test } from '@playwright/test'
import { gotoAppRoute, openApp } from './helpers/pipelinq.ts'

const STAMP = Date.now()

test.describe('mail block editor', () => {
	test.setTimeout(180000)

	test('a marketer builds, reorders and previews a newsletter from blocks', async ({
		page,
	}) => {
		await gotoAppRoute(page, '/templates')
		await page.getByTestId('cn-cta-primary').click()
		const editor = page.getByTestId('template-block-editor')
		await expect(editor).toBeVisible({ timeout: 30000 })

		await page.getByLabel('Template name').fill(`E2E blocks ${STAMP}`)
		await page.getByLabel('Reply-to email').fill('reply@example.nl')
		await editor
			.getByTestId('mail-block-heading')
			.locator('.mail-block-editor__select')
			.click()
		await page.getByLabel('Heading text').fill('Autumn news')
		await editor.getByTestId('mail-block-add-button').click()
		await page.getByLabel('Button text').fill('Read more')
		await page.getByLabel('Link (https://)').fill('https://www.example.nl')
		await editor.getByTestId('mail-block-add-articles').click()

		// Move the button up with the keyboard; focus stays on the moved block.
		const up = editor.getByRole('button', { name: 'Move Button up' })
		await up.focus()
		await page.keyboard.press('Enter')
		await expect(
			editor.getByRole('button', { name: /Move Button (up|down)/ }).first(),
		).toBeFocused()

		await page.getByRole('button', { name: 'Preview' }).click()
		const preview = page.frameLocator('iframe.template-form__preview')
		await expect(preview.getByText('Autumn news')).toBeVisible({
			timeout: 15000,
		})
		await expect(preview.getByRole('link', { name: 'Read more' })).toBeVisible()

		await page
			.getByLabel('Physical address')
			.fill('Voorbeeldstraat 1, 1234 AB Zuiddrecht')
		await page.getByRole('button', { name: 'Create template' }).click()
		await expect(page.getByText(`E2E blocks ${STAMP}`).first()).toBeVisible({
			timeout: 30000,
		})

		await page.getByText(`E2E blocks ${STAMP}`).first().click()
		await page.getByRole('button', { name: 'Edit' }).first().click()
		await expect(page.getByTestId('template-block-editor')).toBeVisible({
			timeout: 30000,
		})
		await expect(page.getByTestId('mail-block-button')).toBeVisible()
		await expect(page.getByLabel('Reply-to email')).toHaveValue(
			'reply@example.nl',
		)
	})

	test('a template saved as HTML opens as HTML', async ({ page }) => {
		await openApp(page)
		const res = await page.evaluate(async (stamp) => {
			const r = await fetch('/index.php/apps/pipelinq/api/templates', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					requesttoken:
						document
							.querySelector('head[data-requesttoken]')
							?.getAttribute('data-requesttoken') ?? '',
				},
				body: JSON.stringify({
					name: `E2E html ${stamp}`,
					channel: 'email',
					bodyHtml: '<p>Hello</p><p>{{unsubscribe_link}}</p>',
					footerOverride: 'Voorbeeldstraat 1',
				}),
			})
			return r.status
		}, STAMP)
		expect(res).toBeLessThan(400)

		await gotoAppRoute(page, '/templates')
		await page.getByText(`E2E html ${STAMP}`).first().click()
		await page.getByRole('button', { name: 'Edit' }).first().click()
		await expect(page.getByLabel('HTML body')).toHaveValue(/Hello/, {
			timeout: 30000,
		})
		await expect(page.getByTestId('template-block-editor')).toHaveCount(0)
	})
})
