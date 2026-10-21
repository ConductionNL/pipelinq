/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The cash register audit entry is a declared detail page over the app's own
 * endpoint, under an English address; the old addresses redirect.
 *
 * @spec openspec/changes/pl2-cash-register-audit/specs/pos-kassakoppeling-audit/spec.md#requirement-an-audit-entry-is-a-declared-detail-page-under-an-english-address
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
		expect(page('KassakoppelingAuditList')).toMatchObject({ route: '/cash-register-audit', component: 'CashRegisterAuditListView' })
		const main = readFileSync(resolve(root, 'src/main.js'), 'utf8')
		expect(main).toContain("routes.push({ path: '/kassakoppeling/audit', redirect: '/cash-register-audit' })")
		expect(main).toContain("path: '/kassakoppeling/audit/:id'")
	})

	it('has English UI names', () => {
		expect(existsSync(resolve(root, 'src/views/kassakoppeling'))).toBe(false)
		expect(existsSync(resolve(root, 'src/dialogs/BelastingdienstExportDialog.vue'))).toBe(false)
		const list = readFileSync(resolve(root, 'src/views/pos/CashRegisterAuditList.vue'), 'utf8')
		expect(list).not.toMatch(/Belastingdienst|kassakoppeling-audit-list/)
	})
})
