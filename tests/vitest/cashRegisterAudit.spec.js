/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The cash register audit entry is a declared detail page over the app's own
 * endpoint, under an English address; the old addresses redirect.
 *
 * @spec openspec/specs/pos-kassakoppeling-audit/spec.md#requirement-an-audit-entry-is-a-declared-detail-page-under-an-english-address
 */
import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(__dirname, '../..')
const manifest = JSON.parse(readFileSync(resolve(root, 'src/manifest.json'), 'utf8'))
const page = (id) => manifest.pages.find((p) => p.id === id)

describe('the cash register audit', () => {
	it('reads an entry from the endpoint on a detail page', () => {
		const detail = page('KassakoppelingAuditDetail')
		expect(detail).toMatchObject({ type: 'detail', route: '/cash-register-audit/:id' })
		expect(detail.component).toBeUndefined()
		expect(detail.config.entitySource).toBe('endpoint')
		expect(detail.config.sourceConfig).toEqual({ url: '/apps/pipelinq/api/kassakoppeling/audit/{id}', recordPath: 'entry' })
		const verify = detail.config.headerActions.find((a) => a.id === 'audit-verify')
		expect(verify.url).toBe('/apps/pipelinq/api/kassakoppeling/audit/@objectId/verify')
		expect(verify.visibleWhen).toEqual({ field: 'verified', op: 'neq', value: true })
		const summary = detail.config.widgets.find((w) => w.id === 'audit-summary')
		expect(summary.content.fields.find((f) => f.path === 'amount')).toMatchObject({ format: 'currency', minorUnits: true })
	})

	it('lists under /cash-register-audit, and the old addresses redirect', () => {
		expect(page('KassakoppelingAuditList')).toMatchObject({ route: '/cash-register-audit', type: 'index' })
		const main = readFileSync(resolve(root, 'src/main.js'), 'utf8')
		expect(main).toContain("routes.push({ path: '/kassakoppeling/audit', redirect: '/cash-register-audit' })")
		expect(main).toContain("path: '/kassakoppeling/audit/:id'")
	})

	it('has English UI names', () => {
		expect(existsSync(resolve(root, 'src/views/kassakoppeling'))).toBe(false)
		expect(existsSync(resolve(root, 'src/dialogs/BelastingdienstExportDialog.vue'))).toBe(false)
	})
})

describe('the cash register audit log (PqKassaAudit)', () => {
	const list = page('KassakoppelingAuditList')

	it('is a declared index over the audit endpoint, newest first', () => {
		expect(list.component).toBeUndefined()
		expect(existsSync(resolve(root, 'src/views/pos/CashRegisterAuditList.vue'))).toBe(false)
		expect(list.config.entitySource).toBe('endpoint')
		expect(list.config.sourceConfig).toMatchObject({
			url: '/apps/pipelinq/api/kassakoppeling/audit',
			rowsPath: 'entries',
			searchParam: 'search',
			order: 'desc',
		})
		expect(Object.keys(list.config.sourceConfig.searchFields)).toEqual(
			expect.arrayContaining(['from', 'to', 'registerNumber', 'operatorId', 'action']),
		)
	})

	it('draws the board columns: time, operator, register, action, amount, check', () => {
		expect(list.config.columns.map((c) => c.key)).toEqual(['timestamp', 'operatorId', 'registerNumber', 'action', 'amount', 'verification'])
		expect(list.config.columns.find((c) => c.key === 'amount').format).toEqual({ style: 'currency', currency: 'EUR', minorUnits: true })
	})

	it('has the lenses All, Invalid signature and Not exported, counted', () => {
		expect(list.config.quickFilters.map((q) => q.label)).toEqual(['All', 'Invalid signature', 'Not exported'])
		expect(list.config.quickFilters[1].filter).toEqual({ verified: 'false' })
		expect(list.config.quickFilters[2].filter).toEqual({ exported: 'false' })
		expect(list.config.quickFilters.every((q) => q.showCount === true)).toBe(true)
	})

	it('opens an entry beside the list, in the declared detail page', () => {
		expect(list.splitView).toMatchObject({ enabled: true })
		expect(list.config.rowRoute).toBe('KassakoppelingAuditDetail')
		const main = readFileSync(resolve(root, 'src/main.js'), 'utf8')
		expect(main).toMatch(/buildManifestRoutes\(/)
	})

	it('offers the tax authority export to administrators only', () => {
		const exportAction = list.config.headerActions.find((a) => a.id === 'audit-export')
		expect(exportAction).toMatchObject({
			label: 'Export for the tax authority',
			icon: 'Download',
			type: 'open-modal',
			target: 'TaxAuthorityExportModal',
			adminOnly: true,
		})
		const registry = readFileSync(resolve(root, 'src/registry.js'), 'utf8')
		expect(registry).toMatch(/TaxAuthorityExportModal: \{\s*kind: 'modal'/)
		expect(registry).not.toContain('CashRegisterAuditListView')
	})
})
