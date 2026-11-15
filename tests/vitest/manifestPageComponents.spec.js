// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A custom page must name a component that renders as a page. The TaskNew
 * page named CnWizardDialog, a dialog, so /tasks/new rendered "This page is
 * empty" (openspec/changes/review-part-two). New tasks are made through the
 * Tasks index's create dialog.
 */

import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'

describe('custom pages', () => {
	it('never use a dialog component as a page', () => {
		const dialogPages = manifest.pages
			.filter((p) => p.type === 'custom')
			.filter((p) => /Dialog$|Modal$/.test(p.component || ''))
			.map((p) => p.id)
		expect(dialogPages).toEqual([])
	})

	it('has no separate new-task page', () => {
		expect(manifest.pages.find((p) => p.id === 'TaskNew')).toBeUndefined()
		expect(manifest.pages.find((p) => p.route === '/tasks/new')).toBeUndefined()
		const tasks = manifest.pages.find((p) => p.route === '/tasks')
		expect(tasks.type).toBe('index')
	})
})
