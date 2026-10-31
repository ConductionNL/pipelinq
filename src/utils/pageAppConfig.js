/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Page-level app config for manifest pages.
 *
 * The `config` initial state (Application::boot) carries the settings a page
 * formats with: the reporting `currency` and the `pipelineTarget`. This module
 * hands that map to every dashboard and detail page as `config.appConfig`, so
 * a widget's `@config.currency` token formats in the configured currency.
 *
 * It also resolves a gauge's `target.value` when it is an `@config.<key>`
 * token. The library's gauge reads a static target with `Number()`, which
 * turns a token into NaN, so the value is filled in here before render.
 *
 * Pure: no imports, so it runs under Vitest without a DOM.
 */

/** The page types that read `@config.<key>` tokens from `config.appConfig`. */
const CONFIG_PAGE_TYPES = ['dashboard', 'detail']

/** `@config.<key>`, with an optional trailing `?`. */
const CONFIG_TOKEN = /^@config\.([A-Za-z0-9_]+)\??$/

/**
 * Resolve a gauge widget's `@config.<key>` target against the app config.
 *
 * A target that resolves to a positive number replaces the token. Anything
 * else (unset, zero, not a number) becomes 0, which the gauge renders as a
 * dash, and the label switches to `content.labelWithoutTarget` when the widget
 * declares one, so the reader sees why there is no percentage.
 *
 * @param {object} widget The manifest widget definition.
 * @param {object} appConfig The page-level app config map.
 * @return {object} The widget, with its target resolved (a copy when changed).
 *
 * @spec openspec/changes/pipeline-numbers-tell-the-truth/specs/commercial-dashboard/spec.md
 */
export function resolveGaugeTarget(widget, appConfig) {
	const content = widget?.content
	const raw = content?.target?.value
	if (widget?.type !== 'gauge' || typeof raw !== 'string') {
		return widget
	}
	const match = CONFIG_TOKEN.exec(raw)
	if (!match) {
		return widget
	}
	const value = Number(appConfig?.[match[1]])
	const hasTarget = Number.isFinite(value) && value > 0
	const next = {
		...content,
		target: { ...content.target, value: hasTarget ? value : 0 },
	}
	if (!hasTarget && content.labelWithoutTarget) {
		next.label = content.labelWithoutTarget
	}
	return { ...widget, content: next }
}

/** The keys of the `case_target` initial state a page may read. */
const CASE_TARGET_KEYS = ['caseRegister', 'caseSchema']

/**
 * The app config a page reads: the `config` initial state, plus where the
 * installed case app keeps its cases (`case_target`, set by the page
 * controller from OpenRegister at run time).
 *
 * The case target counts only when it is whole. Half a target would point a
 * widget at a register without a schema, so both keys are left out and the
 * `@config.caseRegister` / `@config.caseSchema` tokens stay unresolved, which
 * keeps the widget off the page.
 *
 * @param {object} config The `config` initial state.
 * @param {object} caseTarget The `case_target` initial state.
 * @return {object} The merged map, a new object.
 *
 * @spec openspec/specs/ticket-decisions/spec.md#requirement-decisions-on-the-linked-case-show-on-the-ticket-req-tdt-002
 */
export function pageAppConfig(config, caseTarget) {
	const merged = { ...(config || {}) }
	const whole = CASE_TARGET_KEYS.every(
		(key) => typeof caseTarget?.[key] === 'string' && caseTarget[key] !== '',
	)
	for (const key of CASE_TARGET_KEYS) {
		delete merged[key]
		if (whole) {
			merged[key] = caseTarget[key]
		}
	}
	return merged
}

/**
 * Seed the page-level app config onto every dashboard and detail page.
 *
 * CnPageRenderer forwards each `config.*` key to the page component's props,
 * so this lands on the `appConfig` prop of CnDashboardPage and CnDetailPage.
 * That prop is what the library's `@config.<key>` resolver reads. An explicit
 * per-page `config.appConfig` still wins.
 *
 * @param {object} manifest The merged manifest (with `pages[]`).
 * @param {object} appConfig The app config map from the `config` initial state.
 * @return {object} The same manifest, with those pages' config seeded.
 *
 * @spec openspec/changes/pipeline-numbers-tell-the-truth/specs/commercial-dashboard/spec.md
 */
export function seedPageAppConfig(manifest, appConfig) {
	const config = appConfig || {}
	for (const page of manifest.pages || []) {
		if (!CONFIG_PAGE_TYPES.includes(page.type)) {
			continue
		}
		page.config = { appConfig: config, ...(page.config || {}) }
		if (Array.isArray(page.config.widgets)) {
			page.config.widgets = page.config.widgets.map((widget) =>
				resolveGaugeTarget(widget, page.config.appConfig),
			)
		}
	}
	return manifest
}
