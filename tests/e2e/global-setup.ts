import { chromium, FullConfig } from '@playwright/test'
import { STORAGE_STATE } from './helpers/auth'

async function globalSetup(config: FullConfig) {
	const baseURL = config.projects[0].use.baseURL ?? 'http://localhost:8080'
	const user = process.env.ADMIN_USER ?? 'admin'
	const password = process.env.ADMIN_PASSWORD ?? 'admin'

	const browser = await chromium.launch()
	const page = await browser.newPage()

	await page.goto(`${baseURL}/login`)
	await page.fill('input[name="user"]', user)
	await page.fill('input[name="password"]', password)
	await page.click('button[type="submit"], input[type="submit"]')
	await page.waitForURL('**/apps/**', { timeout: 30000 })

	await page.context().storageState({ path: STORAGE_STATE })
	await browser.close()
}

export default globalSetup
