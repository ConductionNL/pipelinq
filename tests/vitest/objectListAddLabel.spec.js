// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The library's CnObjectListWidget translates `content.addLabel` through the
 * app's translate function (since @conduction/nextcloud-vue 2.76.0), so
 * pipelinq hands it the English source text and does not translate it first.
 * On 2.73.1 the label came back as written.
 *
 * @spec openspec/changes/round4-nextcloud-vue-2-76/specs/client-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import CnObjectListWidget from '@conduction/nextcloud-vue/src/components/CnObjectListWidget/CnObjectListWidget.vue'

const ROOT = path.resolve(__dirname, '../..')
const catalogue = (lang) =>
	JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', `${lang}.json`), 'utf8')).translations

describe('CnObjectListWidget Add label', () => {
	const addLabel = CnObjectListWidget.computed.addLabel
	const nl = catalogue('nl')
	const en = catalogue('en')

	it('reads the Dutch text in a Dutch interface', () => {
		const label = addLabel.call({
			content: { addLabel: 'Add contact person' },
			cnTranslate: (text) => nl[text] || text,
		})
		expect(label).toBe('Contactpersoon toevoegen')
	})

	it('reads the English text in an English interface', () => {
		const label = addLabel.call({
			content: { addLabel: 'Add contact person' },
			cnTranslate: (text) => en[text] || text,
		})
		expect(label).toBe('Add contact person')
	})
})
