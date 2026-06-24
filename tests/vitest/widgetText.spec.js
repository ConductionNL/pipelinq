// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for src/utils/widgetText.js — `toText` coerces OpenRegister
 * property values to display strings for the String-typed NcDashboardWidgetItem
 * `mainText`/`subText` props, including object values that would otherwise trip
 * Vue's prop type check.
 */

import { describe, it, expect } from 'vitest'
import { toText } from '../../src/utils/widgetText.js'

describe('toText', () => {
	it('passes strings through unchanged', () => {
		expect(toText('Acme deal')).toBe('Acme deal')
	})

	it('returns empty string for null/undefined', () => {
		expect(toText(null)).toBe('')
		expect(toText(undefined)).toBe('')
	})

	it('stringifies numbers and booleans', () => {
		expect(toText(42)).toBe('42')
		expect(toText(true)).toBe('true')
	})

	it('extracts a display field from object values', () => {
		expect(toText({ title: 'From title' })).toBe('From title')
		expect(toText({ name: 'From name' })).toBe('From name')
		expect(toText({ label: 'From label' })).toBe('From label')
		expect(toText({ value: 'From value' })).toBe('From value')
	})

	it('falls back to the @self display fields', () => {
		expect(toText({ '@self': { title: 'Self title' } })).toBe('Self title')
		expect(toText({ '@self': { name: 'Self name' } })).toBe('Self name')
	})

	it('returns empty string for an object with no usable field', () => {
		expect(toText({ id: 7, foo: 'bar' })).toBe('')
	})

	it('always returns a string, even when the display field is itself an object', () => {
		// e.g. an expanded relation: title -> { value: { name: 'Acme' } }
		expect(toText({ value: { name: 'Acme' } })).toBe('Acme')
		// fully nested with nothing usable still yields a string, not an object
		expect(typeof toText({ value: { foo: { bar: 1 } } })).toBe('string')
	})

	it('joins array values', () => {
		expect(toText(['a', 'b'])).toBe('a, b')
	})

	it('extracts the value from an nl wrapper map', () => {
		// The "nl" key is not a language code — it is just how the value is
		// wrapped, so the string is pulled straight out.
		expect(toText({ nl: 'test' })).toBe('test')
	})
})
