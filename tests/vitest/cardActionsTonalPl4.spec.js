/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boards PqService and PqZRapport draw "Stappen bewerken" and "Opnieuw
 * doorgeven aan shillinq" as the light-blue tonal card pill, which the
 * library's `cn-button--tonal` draws. Read from the sources, since the look
 * itself is the library's stylesheet.
 *
 * @spec openspec/changes/detail-pages-read-at-a-glance/specs/client-management/spec.md
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

/**
 * The opening NcButton tag before a translated label.
 *
 * @param {string} file A component source path.
 * @param {string} label The label's source string.
 * @return {string} The opening tag.
 */
function buttonTag(file, label) {
	const source = fs.readFileSync(path.join(ROOT, file), 'utf8')
	const at = source.indexOf(`t('pipelinq', '${label}')`)
	expect(at, label).toBeGreaterThan(-1)
	const start = source.slice(0, at).lastIndexOf('<NcButton')
	return source.slice(start, source.indexOf('>', start + 1) + 1)
}

describe('the service and Z-report card actions', () => {
	it.each([
		['src/views/bookings/ServiceDetail.vue', 'Edit steps'],
		['src/components/pos/ZReportBookkeepingWidget.vue', 'Pass on to shillinq again'],
	])('%s draws "%s" as the tonal pill', (file, label) => {
		expect(buttonTag(file, label)).toContain('cn-button--tonal')
	})

	it('draws the pencil before "Edit steps", as PqService does', () => {
		const source = fs.readFileSync(path.join(ROOT, 'src/views/bookings/ServiceDetail.vue'), 'utf8')
		const at = source.indexOf("t('pipelinq', 'Edit steps')")
		const button = source.slice(source.slice(0, at).lastIndexOf('<NcButton'), at)
		expect(button).toMatch(/<template #icon>\s*<PencilOutline :size="16" \/>/)
	})
})
