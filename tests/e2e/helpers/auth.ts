import { Page } from '@playwright/test'
import path from 'path'

export const STORAGE_STATE = path.join(__dirname, '..', '.auth', 'user.json')

export async function login(page: Page, user?: string, password?: string) {
	const username = user ?? process.env.ADMIN_USER ?? 'admin'
	const pass = password ?? process.env.ADMIN_PASSWORD ?? 'admin'

	await page.goto('/login')
	await page.fill('input[name="user"]', username)
	await page.fill('input[name="password"]', pass)
	await page.click('button[type="submit"], input[type="submit"]')
	await page.waitForURL('**/apps/**', { timeout: 30000 })
}
