/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Ask Navi" on the operational dashboard as the PqOperationeel board draws
 * it: a label above the question field and an "Ask" pill; the conversation
 * appears once a question is asked. Read as text.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const src = readFileSync(resolve(__dirname, '../../src/views/dashboard/widgets/NaviAnalyticsWidget.vue'), 'utf8')

describe('the Ask Navi card', () => {
	it('labels the field above it and asks with a pill', () => {
		expect(src).toContain("t('pipelinq', 'Ask a question about these figures')")
		expect(src).toMatch(/<label :for="inputId"/)
		expect(src).toContain("t('pipelinq', 'Ask')")
	})

	it('draws Ask as the shared tonal pill, so the light primary ground is the body tint', () => {
		// NcButton redefines --color-primary-element-light inside the button.
		expect(src).toMatch(/class="navi-widget__ask cn-button--tonal"/)
		expect(src).not.toMatch(/\.navi-widget__ask\.button-vue[^{]*\{[^}]*background-color/)
	})

	it('shows no welcome text and draws the conversation only once there is one', () => {
		expect(src).not.toContain('navi-widget__welcome')
		expect(src).toContain('v-if="messages.length || loading || error" class="navi-widget__history"')
	})
})
