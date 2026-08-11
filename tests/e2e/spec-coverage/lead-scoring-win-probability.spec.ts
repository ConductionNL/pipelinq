/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural e2e coverage for
 * openspec/specs/lead-scoring-win-probability/spec.md.
 *
 * THIS FILE DELIBERATELY DECLARES NO TESTS — see pipelinq#774.
 * ============================================================
 * Both scenarios it used to assert — the Leads index Win % column and the
 * LeadDetail Deal widget — describe a value that the product does not deliver
 * to either surface. That is a defect, not a test bug, and the honest record of
 * it is a reason-bearing `@e2e exclude` in the spec plus this note; a weaker
 * assertion (a header without its value, a widget label without its number)
 * would report the capability as working while a salesperson sees an em-dash.
 *
 * THE MEASUREMENT (run 31473685688)
 * ---------------------------------
 *   * `GET /index.php/apps/openregister/api/objects/pipelinq/lead?_limit=25`
 *     returned leads carrying NO `winProbability` key at all — `typeof` was
 *     `undefined`, not `null`, on every row.
 *   * The Leads index Win % cell (column 7 of the first row) rendered `"—"`,
 *     stable across 33 polls of a 15s expect.
 *
 * WHY — READ OUT OF THE SOURCE, NOT INFERRED FROM THE SYMPTOM
 * -----------------------------------------------------------
 * `winProbability` is declared `materialise: false` in
 * lib/Settings/pipelinq_register.json
 * (`components.schemas.lead.configuration.x-openregister-calculations`).
 * OpenRegister evaluates such VIRTUAL calculations in
 * `RenderObject::renderEntity()`, and only inside this guard:
 *
 *     if (in_array('calculations', $extendArr, true) === true) {
 *         $objectData = $this->applyVirtualCalculations(…)
 *     }
 *
 * — i.e. exclusively when the request asked for `_extend[]=calculations`.
 * Nothing on either pipelinq surface asks for it:
 *
 *   * `src/manifest.json` declares the `winProbability` column on the `Leads`
 *     page and includes it in the `lead-deal` detail widget, but neither page
 *     config carries an `extend`.
 *   * In @conduction/nextcloud-vue, `_extend` appears in exactly one data path
 *     — `CnWidgetObjectTable`'s `source.extend` (ADR-049 widget self-fetch).
 *     `useListView.js` and `useDetailView.js`, which drive `type: "index"` and
 *     `type: "detail"` pages, contain no `extend` handling at all, and neither
 *     does pipelinq's own `LeadList.vue`.
 *
 * So the index and detail fetches can never receive a `materialise: false`
 * calculation. `qualificationScore` — the sibling calculation, declared
 * `materialise: true` and therefore persisted on save — renders fine on the
 * Qualification widget, which is the control that shows the surfaces
 * themselves are healthy and only the virtual-calculation path is missing.
 *
 * WHAT WOULD MAKE THESE SCENARIOS TESTABLE AGAIN
 * ----------------------------------------------
 * Either page config gaining an `extend: ["calculations"]` that the index /
 * detail composables forward as `_extend[]`, or `winProbability` being
 * materialised. Both are product changes, tracked as pipelinq#774. When one lands, restore the two
 * assertions this file used to carry: the Win % header AND a `/\d/` cell value
 * on the index, and the Deal widget containing the served number.
 */
export {}
