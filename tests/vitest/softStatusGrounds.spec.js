/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Status pills and notes stand on the boards' soft grounds (#e7f3ea green,
 * #fff4de amber, #fcedec red). Nextcloud's --color-success-hover,
 * --color-warning-hover and --color-error-hover are the hover shades of the
 * solid status buttons: on the instance the green one is 189,235,192
 * (PqBeheerPortaal "Live") and the red one so dark that the forecast's
 * "Moved out of commit" pill could not be read (PqForecast).
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const files = [
	'src/components/admin/PortalSettings.vue',
	'src/views/werkplek/widgets/WerkplekHeaderActions.vue',
	'src/views/forecast/ForecastTrend.vue',
	'src/modals/LetterFromTemplateModal.vue',
]
const style = (file) => readFileSync(resolve(__dirname, '../..', file), 'utf8').split('<style')[1]

describe('soft status grounds', () => {
	it.each(files)('%s paints no ground with a status hover shade', (file) => {
		expect(style(file)).not.toMatch(/background: var\(--color-(success|warning|error)-hover/)
	})

	it('draws the portal Live pill on the soft green', () => {
		const live = style(files[0]).match(/\.portal-status--live \{([^}]*)\}/)[1]
		expect(live).toMatch(/background: var\(--cn-board-success-tint, #e7f3ea\);/)
		expect(live).toMatch(/color: var\(--cn-board-success-ink, #1e6b2a\);/)
	})

	it('draws the forecast move pills on the soft green and red', () => {
		const css = style(files[2])
		expect(css.match(/\.forecast-trend__move--into \{([^}]*)\}/)[1]).toMatch(/background: var\(--cn-board-success-tint, #e7f3ea\);/)
		expect(css.match(/\.forecast-trend__move--out \{([^}]*)\}/)[1]).toMatch(/background: var\(--cn-board-red-tag-ground, #fcedec\);/)
	})
})
