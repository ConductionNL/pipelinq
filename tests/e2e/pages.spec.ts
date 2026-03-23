import { test, expect } from '@playwright/test'

test.describe('Clients page', () => {

	test('renders list view with correct controls', async ({ page }) => {
		await page.goto('/apps/pipelinq/clients')
		await expect(page.getByRole('radio', { name: 'Cards' })).toBeVisible({ timeout: 10000 })
		await expect(page.getByRole('radio', { name: 'Table' })).toBeChecked()
		await expect(page.getByRole('button', { name: 'Add Item' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Actions' })).toBeVisible()
	})

	test('Actions menu contains Refresh, Import, Export', async ({ page }) => {
		await page.goto('/apps/pipelinq/clients')
		await page.getByRole('button', { name: 'Actions' }).click()
		await expect(page.getByRole('menuitem', { name: 'Refresh' })).toBeVisible()
		await expect(page.getByRole('menuitem', { name: 'Import' })).toBeVisible()
		await expect(page.getByRole('menuitem', { name: 'Export' })).toBeVisible()
	})

	test('Add Item navigates to new client form', async ({ page }) => {
		await page.goto('/apps/pipelinq/clients')
		await page.getByRole('button', { name: 'Add Item' }).click()
		await expect(page).toHaveURL(/.*clients\/new/)
		await expect(page.getByRole('heading', { name: 'New client', level: 2 })).toBeVisible()
	})

	test('new client form has correct fields', async ({ page }) => {
		await page.goto('/apps/pipelinq/clients/new')
		await expect(page.getByRole('textbox', { name: 'Name' })).toBeVisible({ timeout: 10000 })
		await expect(page.getByRole('combobox', { name: 'Type' })).toBeVisible()
		await expect(page.getByRole('textbox', { name: 'Email' })).toBeVisible()
		await expect(page.getByRole('textbox', { name: 'Phone' })).toBeVisible()
		await expect(page.getByRole('textbox', { name: 'Website' })).toBeVisible()
		await expect(page.getByRole('textbox', { name: 'Address' })).toBeVisible()
		await expect(page.getByRole('textbox', { name: 'Notes' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Save' })).toBeDisabled()
		await expect(page.getByRole('button', { name: 'Cancel' })).toBeEnabled()
	})

	test('Cancel returns to list', async ({ page }) => {
		await page.goto('/apps/pipelinq/clients/new')
		await page.getByRole('button', { name: 'Cancel' }).click()
		await expect(page).toHaveURL(/.*clients$/)
	})
})

test.describe('Leads page', () => {

	test('renders list view with correct controls', async ({ page }) => {
		await page.goto('/apps/pipelinq/leads')
		await expect(page.getByRole('radio', { name: 'Table' })).toBeChecked({ timeout: 10000 })
		await expect(page.getByRole('button', { name: 'Add Item' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Actions' })).toBeVisible()
	})

	test('new lead form has correct fields', async ({ page }) => {
		await page.goto('/apps/pipelinq/leads/new')
		await expect(page.getByRole('heading', { name: 'New lead', level: 2 })).toBeVisible({ timeout: 10000 })
		await expect(page.getByRole('textbox', { name: 'Title' })).toBeVisible()
		await expect(page.getByRole('textbox', { name: 'Description' })).toBeVisible()
		await expect(page.getByRole('spinbutton', { name: 'Value (EUR)' })).toBeVisible()
		await expect(page.getByRole('spinbutton', { name: 'Probability %' })).toBeVisible()
		await expect(page.getByRole('combobox', { name: 'Select source' })).toBeVisible()
		await expect(page.getByRole('textbox', { name: 'Expected close date' })).toBeVisible()
		await expect(page.getByRole('combobox', { name: 'Select client' })).toBeVisible()
		await expect(page.getByRole('combobox', { name: 'Select pipeline' })).toBeVisible()
		// Stage disabled until pipeline selected
		await expect(page.getByRole('combobox', { name: 'Select pipeline first' })).toBeDisabled()
		await expect(page.getByRole('button', { name: 'Create' })).toBeDisabled()
	})
})

test.describe('Requests page', () => {

	test('renders list view with correct controls', async ({ page }) => {
		await page.goto('/apps/pipelinq/requests')
		await expect(page.getByRole('radio', { name: 'Table' })).toBeChecked({ timeout: 10000 })
		await expect(page.getByRole('button', { name: 'Add Item' })).toBeVisible()
	})

	test('new request form has correct fields', async ({ page }) => {
		await page.goto('/apps/pipelinq/requests/new')
		await expect(page.getByRole('heading', { name: 'New request', level: 2 })).toBeVisible({ timeout: 10000 })
		await expect(page.getByRole('textbox', { name: 'Title' })).toBeVisible()
		await expect(page.getByRole('textbox', { name: 'Description' })).toBeVisible()
		await expect(page.getByRole('combobox', { name: 'Select channel' })).toBeVisible()
		await expect(page.getByRole('textbox', { name: 'Category' })).toBeVisible()
		await expect(page.getByRole('textbox', { name: 'Requested at' })).toBeVisible()
		await expect(page.getByRole('combobox', { name: 'Select client' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Create' })).toBeDisabled()
	})
})

test.describe('Products page', () => {

	test('renders list view with correct controls', async ({ page }) => {
		await page.goto('/apps/pipelinq/products')
		await expect(page.getByRole('radio', { name: 'Table' })).toBeChecked({ timeout: 10000 })
		await expect(page.getByRole('button', { name: 'Add Item' })).toBeVisible()
	})

	test('new product form has correct fields', async ({ page }) => {
		await page.goto('/apps/pipelinq/products/new')
		await expect(page.getByRole('heading', { name: 'New product', level: 2 })).toBeVisible({ timeout: 10000 })
		await expect(page.getByRole('textbox', { name: 'Name' })).toBeVisible()
		await expect(page.getByRole('textbox', { name: 'SKU' })).toBeVisible()
		await expect(page.getByRole('combobox', { name: 'Type' })).toBeVisible()
		await expect(page.getByRole('spinbutton', { name: 'Unit Price' })).toBeVisible()
		await expect(page.getByRole('spinbutton', { name: 'Cost' })).toBeVisible()
		await expect(page.getByRole('textbox', { name: 'Unit' })).toBeVisible()
		await expect(page.getByRole('spinbutton', { name: 'Tax Rate' })).toBeVisible()
		await expect(page.getByRole('combobox', { name: 'Category' })).toBeVisible()
		await expect(page.getByRole('textbox', { name: 'Description' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Save' })).toBeDisabled()
	})
})

test.describe('Contacts page', () => {

	test('renders list view with correct controls', async ({ page }) => {
		await page.goto('/apps/pipelinq/contacts')
		await expect(page.getByRole('radio', { name: 'Table' })).toBeChecked({ timeout: 10000 })
		await expect(page.getByRole('button', { name: 'Add Item' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Actions' })).toBeVisible()
	})
})

test.describe('Pipeline page', () => {

	test('renders pipeline view with correct controls', async ({ page }) => {
		await page.goto('/apps/pipelinq/pipeline')
		await expect(page.getByRole('heading', { name: 'Pipeline', level: 2 }).first()).toBeVisible({ timeout: 10000 })
		await expect(page.getByRole('combobox', { name: 'Select pipeline' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Kanban view' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'List view' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Pipeline settings' })).toBeVisible()
	})

	test('sidebar shows Details and Stages tabs', async ({ page }) => {
		await page.goto('/apps/pipelinq/pipeline')
		await expect(page.getByRole('tab', { name: 'Details' })).toBeVisible({ timeout: 10000 })
		await expect(page.getByRole('tab', { name: 'Stages' })).toBeVisible()
	})

	test('shows empty state with New pipeline button', async ({ page }) => {
		await page.goto('/apps/pipelinq/pipeline')
		await expect(page.getByText('No pipeline selected')).toBeVisible({ timeout: 10000 })
		await expect(page.getByRole('button', { name: 'New pipeline' })).toBeVisible()
	})
})

test.describe('My Work page', () => {

	test('renders with correct filter controls', async ({ page }) => {
		await page.goto('/apps/pipelinq/my-work')
		await expect(page.getByRole('heading', { name: 'My Work', level: 2 })).toBeVisible({ timeout: 10000 })
		await expect(page.getByRole('button', { name: 'All' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Leads' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Requests' })).toBeVisible()
		await expect(page.getByRole('checkbox', { name: 'Show completed' })).toBeVisible()
	})
})
