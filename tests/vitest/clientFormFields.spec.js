// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The create client form: industry as a list, and the language it
 * preselects (pipelinq-forms-review, D2 and D6).
 */

import { describe, expect, it } from 'vitest'
import { defaultLanguage, industryList } from '../../src/utils/clientFormFields.js'

describe('industryList', () => {
	it('wraps a string stored before industry became a list', () => {
		expect(industryList('Retail')).toEqual(['Retail'])
	})

	it('keeps a list and drops empty items', () => {
		expect(industryList(['Retail', '', 'ICT'])).toEqual(['Retail', 'ICT'])
	})

	it('turns nothing into an empty list', () => {
		expect(industryList('')).toEqual([])
		expect(industryList(undefined)).toEqual([])
	})
})

describe('defaultLanguage', () => {
	it('prefers the user language when the instance can write it', () => {
		expect(defaultLanguage(['en', 'nl'], 'en', 'nl')).toBe('nl')
	})

	it('falls back from a regional tag to its base language', () => {
		expect(defaultLanguage(['en', 'nl'], 'en', 'nl_NL')).toBe('nl')
	})

	it('falls back to the instance default, then to nothing', () => {
		expect(defaultLanguage(['en'], 'en', 'de')).toBe('en')
		expect(defaultLanguage([], '', 'de')).toBe('')
	})
})
