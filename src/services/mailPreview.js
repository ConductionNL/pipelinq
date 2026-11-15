// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * The sandboxed document a mail preview iframe shows: the body HTML under a
 * policy that loads no script and no remote style, on a plain white page.
 *
 * @param {string} bodyHtml The rendered mail body.
 * @return {string} The full HTML document.
 * @spec openspec/specs/marketing-ui/spec.md#requirement-blast-creation-wizard-gates-on-compliance
 */
export function mailPreviewDocument(bodyHtml) {
	const csp =
		"default-src 'none'; style-src 'unsafe-inline'; img-src data: https:; font-src data: https:"
	return (
		'<!DOCTYPE html><html><head><meta charset="utf-8">'
		+ `<meta http-equiv="Content-Security-Policy" content="${csp}">`
		+ '<style>body{margin:16px;font-family:sans-serif;color:#222;background:#fff}</style>'
		+ `</head><body>${bodyHtml}</body></html>`
	)
}
