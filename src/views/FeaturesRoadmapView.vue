<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Features & roadmap page.
  -
  - WHY THIS WRAPPER EXISTS. The page used to be `type: "roadmap"`, which
  - CnPageRenderer dispatches straight to the library's
  - CnFeaturesAndRoadmapPage. That component renders exactly two tabs
  - (features, roadmap) and declares NO slots, so there is no way to add a
  - third surface to it from the manifest. dossiq hit the same wall and solved
  - it the same way; this is a port of that solution, not a second design.
  -
  - So the page is now `type: "custom"` and this component owns it. It renders
  - the library page UNCHANGED as the default section, which keeps the existing
  - selectors (.cn-features-and-roadmap-view, .cn-features-tab__card, the
  - "Show roadmap" button, the "Suggest feature" link) resolving exactly as
  - before, and adds the help desk comparison as a second section.
  -
  - The manifest `config` keys are declared as props here because
  - CnPageRenderer spreads `pages[].config` onto the dispatched component:
  - anything not declared would fall through onto the root element as a stray
  - HTML attribute instead of reaching the library page.
-->

<template>
	<div class="features-roadmap">
		<!-- A toggle group, and deliberately NOT an ARIA tab widget. A real tab
		     widget owes the keyboard the arrow-key roving focus the ARIA
		     practices describe, and the tab role without it promises a
		     screen-reader user a keyboard behaviour that is not there. Two plain
		     buttons carrying aria-pressed keep native button semantics, which
		     already work for everyone. -->
		<div
			class="features-roadmap__sections"
			role="group"
			:aria-label="t('pipelinq', 'Page sections')">
			<NcButton
				id="features-roadmap-tab-product"
				:aria-pressed="String(section === 'product')"
				:variant="section === 'product' ? 'primary' : 'tertiary'"
				@click="section = 'product'">
				{{ t('pipelinq', 'What pipelinq does') }}
			</NcButton>
			<NcButton
				id="features-roadmap-tab-comparison"
				:aria-pressed="String(section === 'comparison')"
				:variant="section === 'comparison' ? 'primary' : 'tertiary'"
				@click="section = 'comparison'">
				{{ t('pipelinq', 'How pipelinq compares') }}
			</NcButton>
		</div>

		<!-- The library page. `v-show`, not `v-if`: CnFeaturesAndRoadmapPage
		     publishes its sidebar into CnAppRoot's holder on mounted() and
		     clears it on beforeUnmount(), so toggling with v-if would tear the
		     Suggest/Support sidebar down and rebuild it on every switch. -->
		<div
			v-show="section === 'product'"
			id="features-roadmap-panel-product"
			role="region"
			aria-labelledby="features-roadmap-tab-product">
			<CnFeaturesAndRoadmapPage
				:repo="repo"
				:documentationUrl="documentationUrl"
				:openbuiltUrl="openbuiltUrl"
				:llmSkillsUrl="llmSkillsUrl"
				:suggestUrl="suggestUrl" />
		</div>

		<section
			v-if="section === 'comparison'"
			id="features-roadmap-panel-comparison"
			role="region"
			aria-labelledby="features-roadmap-tab-comparison"
			class="features-roadmap__comparison">
			<h2>{{ t('pipelinq', 'How pipelinq compares') }}</h2>

			<p class="features-roadmap__lead">
				{{ leadText }}
			</p>

			<NcNoteCard
				type="info"
				:heading="t('pipelinq', 'Before you use this table')">
				<p>
					{{
						t(
							'pipelinq',
							'We only compared open source software we could install and run ourselves. Closed and hosted products are not in this table. Their absence is not a verdict on them.',
						)
					}}
				</p>
				<p>{{ readingDateText }}</p>
				<p>
					{{
						t(
							'pipelinq',
							'A rating is our reading of software we did not write. It is not proof that a product does or does not have a capability.',
						)
					}}
				</p>
				<p>
					{{
						t(
							'pipelinq',
							'We strongly advise you to run your own evaluation. This table does not replace testing against your own requirements.',
						)
					}}
				</p>
				<p>
					{{
						t(
							'pipelinq',
							'Pick the capabilities your service desk needs. Then test all three systems against that shortlist yourself.',
						)
					}}
				</p>
				<p>{{ reratedText }}</p>
				<p>{{ addedRowsText }}</p>
				<p>
					{{
						t(
							'pipelinq',
							'When we correct a rating, we correct our own column only. Re-reading a rival costs another install, and a correction we guessed is worse than one we never made.',
						)
					}}
				</p>
				<p>
					{{
						t(
							'pipelinq',
							'Pipelinq runs inside Nextcloud and keeps its data in OpenRegister. Where a capability comes from one of those rather than from pipelinq itself, we still count it, because that is what you get when you install pipelinq.',
						)
					}}
				</p>
				<p>
					{{
						t(
							'pipelinq',
							'The list asks what a service desk does, in the shape we do it. A capability none of the three has is missing from the list, not from the market. A product that splits the work differently scores low without being worse, and that bias runs in our favour. We add rows as we read more systems, so a lower score in a later release can mean the list grew rather than the product shrank.',
						)
					}}
				</p>
			</NcNoteCard>

			<h3>
				{{
					t('pipelinq', 'Totals over all {count} capabilities', {
						count: total,
					})
				}}
			</h3>
			<div class="features-roadmap__scroller">
				<table class="features-roadmap__table">
					<caption class="features-roadmap__caption">
						{{
							t(
								'pipelinq',
								'How many of the {count} capabilities each system has.',
								{ count: total },
							)
						}}
					</caption>
					<thead>
						<tr>
							<th scope="col">
								{{ t('pipelinq', 'System') }}
							</th>
							<th
								v-for="rating in ratingColumns"
								:key="rating"
								scope="col">
								{{ ratingLabel(rating) }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="system in systems"
							:key="system.key"
							:class="{
								'features-roadmap__row--self': system.isSelf,
							}">
							<th scope="row">
								{{ system.name }}
							</th>
							<td v-for="rating in ratingColumns" :key="rating">
								{{ totals[system.key][rating] }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<h3>{{ t('pipelinq', 'Per area') }}</h3>
			<p class="features-roadmap__legend">
				<span
					v-for="rating in ratingColumns"
					:key="rating"
					class="features-roadmap__legend-item">
					<span
						class="features-roadmap__chip"
						:class="'features-roadmap__chip--' + rating">
						{{ ratingLabel(rating) }}
					</span>
					{{ ratingMeaning(rating) }}
				</span>
			</p>

			<details
				v-for="area in areas"
				:key="area.key"
				class="features-roadmap__area">
				<summary class="features-roadmap__area-summary">
					<span class="features-roadmap__area-name">{{ area.label }}</span>
					<span class="features-roadmap__area-count">
						{{ areaSummary(area) }}
					</span>
				</summary>
				<div class="features-roadmap__scroller">
					<table class="features-roadmap__table">
						<caption class="features-roadmap__caption">
							{{
								areaCaption(area)
							}}
						</caption>
						<thead>
							<tr>
								<th scope="col" class="features-roadmap__num">
									{{ t('pipelinq', 'No.') }}
								</th>
								<th scope="col">
									{{ t('pipelinq', 'Capability') }}
								</th>
								<th
									v-for="system in systems"
									:key="system.key"
									scope="col"
									:class="{
										'features-roadmap__col--self': system.isSelf,
									}">
									{{ system.name }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in area.capabilities" :key="row.id">
								<td class="features-roadmap__num">
									{{ row.id }}
								</td>
								<th scope="row" class="features-roadmap__cap">
									{{ row.label }}
								</th>
								<td
									v-for="system in systems"
									:key="system.key"
									:class="{
										'features-roadmap__col--self': system.isSelf,
									}">
									<span
										class="features-roadmap__chip"
										:class="
											'features-roadmap__chip--'
											+ row[system.key]
										">
										{{ ratingLabel(row[system.key]) }}
									</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</details>
		</section>
	</div>
</template>

<script>
import { CnFeaturesAndRoadmapPage } from '@conduction/nextcloud-vue'
import { getLanguage, translate as t } from '@nextcloud/l10n'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import comparison from '../data/capabilityComparison.json'
import {
	formatComparedOn,
	groupByArea,
	overallTallies,
	RATING_COLUMNS,
} from '../utils/capabilityComparison.js'

export default {
	name: 'FeaturesRoadmapView',

	components: {
		CnFeaturesAndRoadmapPage,
		NcButton,
		NcNoteCard,
	},

	props: {
		/** `<owner>/<repo>` on the forge, forwarded to the library page. */
		repo: { type: String, default: '' },
		/** Public documentation site, forwarded to the library page. */
		documentationUrl: { type: String, default: '' },
		/** OpenBuilt CTA override, forwarded to the library page. */
		openbuiltUrl: { type: String, default: '' },
		/** LLM-skills CTA override, forwarded to the library page. */
		llmSkillsUrl: { type: String, default: '' },
		/** Suggest-a-feature CTA override, forwarded to the library page. */
		suggestUrl: { type: String, default: '' },
	},

	data() {
		return {
			// The product surface stays the landing section: this page's first
			// job is still "what does pipelinq do", and the comparison is the
			// context for that answer.
			section: 'product',
			// Ratings PLUS `unknown`. The totals table and the legend both
			// render this list, because an unrated cell is a claim about what
			// we did, not a hole in the data, and a reader has to be able to
			// see it.
			ratingColumns: RATING_COLUMNS,
			systems: comparison.systems,
		}
	},

	computed: {
		/**
		 * @return {string} The reader's locale, e.g. `nl`.
		 * @spec openspec/specs/features-roadmap/spec.md#requirement-every-user-visible-string-must-exist-in-dutch
		 */
		locale() {
			// One source for the language, so the chrome and the table cannot
			// end up in different ones. `t()` falls back to its English source
			// string, and this falls back to `en`, so a missing runtime gives
			// an all-English page rather than a mixed one.
			return getLanguage() || 'en'
		},

		/**
		 * @return {Array<object>} Areas with localised labels and tallies.
		 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-page-must-present-the-capability-comparison-by-area
		 */
		areas() {
			return groupByArea(comparison, this.locale)
		},

		/**
		 * @return {object} Per-system tallies over all rows.
		 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-page-must-present-the-capability-comparison-by-area
		 */
		totals() {
			return overallTallies(comparison)
		},

		/**
		 * @return {number} How many capabilities the comparison covers.
		 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-page-must-present-the-capability-comparison-by-area
		 */
		total() {
			return comparison.capabilities.length
		},

		/**
		 * @return {string} The opening claim, with the real row count in it.
		 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-comparison-must-state-its-own-limits
		 */
		leadText() {
			return t(
				'pipelinq',
				'We rated pipelinq and two other help desk systems on {count} capabilities: {systems}.',
				{
					count: comparison.capabilities.length,
					systems: comparison.systems
						.filter((s) => !s.isSelf)
						.map((s) => s.name)
						.join(', '),
				},
			)
		},

		/**
		 * The sentence that says we correct our own column and nobody else's.
		 *
		 * A rating claiming we lack something we shipped is the one error on
		 * this page a reader cannot check for themselves, so we fix ours
		 * between rounds and say when. We do not touch the rival columns that
		 * way: re-rating someone else's product without a new reading date
		 * would be the same dishonesty pointed outward.
		 *
		 * @return {string} The correction sentence, empty when nothing was corrected.
		 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-comparison-must-state-its-own-limits
		 */
		reratedText() {
			const corrections = comparison._rerated ?? []
			if (corrections.length === 0 || !comparison.reratedOn) {
				return ''
			}
			return t(
				'pipelinq',
				'We corrected {count} of our own ratings on {date}, because we had shipped the capability since the reading. The rival columns are as we read them on the date above.',
				{
					count: corrections.length,
					date: formatComparedOn(comparison.reratedOn, this.locale),
				},
			)
		},

		/**
		 * The sentence covering rows a later round added to the list.
		 *
		 * A round can ask a new question without re-reading the products an
		 * earlier round rated. Those rows carry `addedOn`, we rate ourselves
		 * on them, and the rival columns stay `unknown`. Saying so is the
		 * point: without it a reader sees rivals scored over fewer rows than
		 * us and has no way to learn why.
		 *
		 * @return {string} The sentence, empty when no row was added this way.
		 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-comparison-must-state-its-own-limits
		 */
		addedRowsText() {
			const added = comparison.capabilities.filter((row) => row.addedOn)
			if (added.length === 0 || !comparison.rowsAddedOn) {
				return ''
			}
			return t(
				'pipelinq',
				'On {date} we added {count} capabilities to the list from a later round of reading. We rated ourselves on them. The rival columns read Unknown, because we did not read those products again, and a guessed rating is worse than an empty cell.',
				{
					count: added.length,
					date: formatComparedOn(comparison.rowsAddedOn, this.locale),
				},
			)
		},

		/**
		 * @return {string} The when-and-how-stale sentence.
		 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-comparison-must-state-its-own-limits
		 */
		readingDateText() {
			return t(
				'pipelinq',
				'We installed both systems and drove them on {date}. Open source moves fast, so some of these ratings are already out of date. Check that date before you rely on them.',
				{
					date: formatComparedOn(comparison.comparedOn, this.locale),
				},
			)
		},
	},

	methods: {
		t,

		/**
		 * Short label for a rating. Never colour alone: the word carries the
		 * meaning so the table survives a greyscale print and a screen reader.
		 *
		 * @param {string} rating One of `yes`, `partial`, `no`, `unknown`.
		 * @return {string} Translated label.
		 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-page-must-present-the-capability-comparison-by-area
		 */
		ratingLabel(rating) {
			if (rating === 'yes') {
				return t('pipelinq', 'Yes')
			}
			if (rating === 'partial') {
				return t('pipelinq', 'Partly')
			}
			if (rating === 'no') {
				return t('pipelinq', 'Not found')
			}
			return t('pipelinq', 'Unknown')
		},

		/**
		 * What a rating means, for the legend.
		 *
		 * `Not found` rather than `not built`: we read other people's code, and
		 * saying we did not find a thing is the honest claim. Saying it is
		 * absent is not.
		 *
		 * @param {string} rating One of `yes`, `partial`, `no`, `unknown`.
		 * @return {string} Translated explanation.
		 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-page-must-present-the-capability-comparison-by-area
		 */
		ratingMeaning(rating) {
			if (rating === 'yes') {
				return t('pipelinq', 'we found the whole capability')
			}
			if (rating === 'partial') {
				return t('pipelinq', 'we found part of it, and part is missing')
			}
			if (rating === 'unknown') {
				return t('pipelinq', 'we have not read that system on this row')
			}
			return t('pipelinq', 'we did not find it')
		},

		/**
		 * One-line score for an area, on the disclosure summary.
		 *
		 * @param {object} area Grouped area from `groupByArea`.
		 * @return {string} Translated summary.
		 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-page-must-present-the-capability-comparison-by-area
		 */
		areaSummary(area) {
			const self = area.tallies.pipelinq
			return t(
				'pipelinq',
				'{total} capabilities. Pipelinq has {yes}, partly has {partial}, is missing {no}.',
				{
					total: area.capabilities.length,
					yes: self.yes,
					partial: self.partial,
					no: self.no,
				},
			)
		},

		/**
		 * Table caption for one area.
		 *
		 * @param {object} area Grouped area from `groupByArea`.
		 * @return {string} Translated caption.
		 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-page-must-present-the-capability-comparison-by-area
		 */
		areaCaption(area) {
			return t(
				'pipelinq',
				'Capabilities in {area}, rated for each of the three systems.',
				{
					area: area.label,
				},
			)
		},
	},
}
</script>

<style scoped>
.features-roadmap__sections {
	display: flex;
	gap: 8px;
	padding: 12px 12px 0;
	flex-wrap: wrap;
}

.features-roadmap__comparison {
	padding: 12px 12px 32px;
	max-width: 1100px;
}

.features-roadmap__lead {
	margin: 8px 0 16px;
	max-width: 70ch;
}

.features-roadmap__legend {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	margin: 8px 0 16px;
}

.features-roadmap__legend-item {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	color: var(--color-text-maxcontrast);
}

/* Wide tables scroll inside their own box, so the page body never does. */
.features-roadmap__scroller {
	overflow-x: auto;
}

.features-roadmap__table {
	width: 100%;
	border-collapse: collapse;
}

.features-roadmap__caption {
	text-align: start;
	padding-block: 8px;
	color: var(--color-text-maxcontrast);
}

.features-roadmap__table th,
.features-roadmap__table td {
	text-align: start;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: top;
}

.features-roadmap__table thead th {
	white-space: nowrap;
}

.features-roadmap__cap {
	font-weight: normal;
	min-width: 22ch;
}

.features-roadmap__num {
	white-space: nowrap;
	color: var(--color-text-maxcontrast);
}

.features-roadmap__col--self,
.features-roadmap__row--self {
	background-color: var(--color-background-hover);
}

.features-roadmap__area {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	margin-bottom: 8px;
	padding: 4px 12px;
}

.features-roadmap__area-summary {
	cursor: pointer;
	padding: 8px 0;
	display: flex;
	flex-wrap: wrap;
	gap: 4px 12px;
	align-items: baseline;
}

.features-roadmap__area-name {
	font-weight: bold;
}

.features-roadmap__area-count {
	color: var(--color-text-maxcontrast);
}

.features-roadmap__chip {
	display: inline-block;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 16px);
	white-space: nowrap;
	border: 1px solid var(--color-border-dark);
}

.features-roadmap__chip--yes {
	background-color: var(--color-success, #2d7b2d);
	border-color: var(--color-success, #2d7b2d);
	color: var(--color-primary-text, #fff);
}

.features-roadmap__chip--partial {
	background-color: var(--color-warning, #c98200);
	border-color: var(--color-warning, #c98200);
	color: var(--color-primary-text, #fff);
}

.features-roadmap__chip--no {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

/* Unrated, and deliberately so. Dashed, because an empty cell and a cell we
   looked at and could not answer are different claims. */
.features-roadmap__chip--unknown {
	background-color: transparent;
	border-style: dashed;
	color: var(--color-text-maxcontrast);
}
</style>
