/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */
module.exports = {
	extends: '@nextcloud/stylelint-config',
	overrides: [
		{
			// The look comes from the theme, not the code: a colour in a style is a
			// Nextcloud theme variable, with a hex only as the fallback of a var().
			// @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-006
			files: ['src/**/*.vue', 'css/**/*.css'],
			rules: {
				'declaration-property-value-disallowed-list': [
					{
						'/.*/': ['/^(?![\\s\\S]*var\\(--)[\\s\\S]*#[0-9a-fA-F]{3,8}\\b/'],
					},
					{
						message: (property, value) => `Hard-coded colour in "${property}: ${value}". Use a Nextcloud theme variable such as var(--color-primary-element), var(--color-main-text), var(--color-main-background), var(--color-border), var(--color-error), var(--color-warning) or var(--color-success); keep a hex only as the fallback of a var().`,
					},
				],
			},
		},
	],
}
