/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin page in the library admin shell, board PqBeheer: the shell draws
 * the title, the path and the version card, the sections follow in the
 * board's order, and every section the page had stays on it.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import sections from '../../src/views/settings/adminSections.json'

const source = readFileSync(
	resolve(__dirname, '../../src/views/settings/Settings.vue'),
	'utf8',
)
const template = source.slice(
	source.indexOf('<template>'),
	source.lastIndexOf('</template>', source.indexOf('<script>')),
)

/**
 * Where a tag first opens in the template.
 *
 * @param {string} tag The opening tag text.
 * @return {number} Its offset.
 */
function at(tag) {
	const index = template.indexOf(tag)
	expect(index, tag).toBeGreaterThan(-1)
	return index
}

describe('the admin page', () => {
	it('draws in the admin shell with the board look', () => {
		expect(template).toMatch(/<CnAdminSettingsShell[\s\S]*?look="board"/)
		expect(template).toMatch(/:breadcrumbs="breadcrumbs"/)
		expect(template).toMatch(/:reimportUrl="reimportUrl"/)
		expect(template).not.toContain('<CnVersionInfoCard')
	})

	it('puts the sections in the board order', () => {
		const order = [
			'<CnRegisterMapping',
			"t('pipelinq', 'Lead Sources')",
			"t('pipelinq', 'Request Channels')",
			'v-bind="sections.leadManagement"',
			'v-bind="lists.productCategory"',
			'v-bind="sections.menuStructure"',
			'v-bind="lists.skill"',
			'v-bind="lists.agentProfile"',
		].map(at)
		expect(order).toEqual([...order].sort((a, b) => a - b))
	})

	it('spans the menu structure across the grid, as the board draws it', () => {
		expect(sections.menuStructure.wide).toBe(true)
	})

	it('keeps every section it had', () => {
		for (const tag of [
			'v-bind="sections.provisionData"',
			':showSetup="isAdmin"',
			'<DetectedIntegrations',
			'v-bind="sections.vatRates"',
			'<PipelineManager',
			'v-bind="sections.forecast"',
			'v-bind="sections.prospect"',
			'v-bind="sections.exportConfiguration"',
			'<MailingListEmbedSettings',
			'v-bind="sections.shillinq"',
			'v-bind="sections.xwiki"',
			'<MessagingSettings',
			'<DeliverabilitySettings',
			'<CtiPage',
			'<PortalSettings',
			'<PaymentSettingsForm',
			'v-bind="sections.posCustomer"',
			'v-bind="lists.posTenderType"',
			'v-bind="lists.posStaff"',
			'v-bind="lists.posRole"',
			'v-bind="sections.marketingTraffic"',
			'v-bind="sections.marketingIntel"',
			'v-bind="sections.brpMonitor"',
		]) {
			at(tag)
		}
	})
})
