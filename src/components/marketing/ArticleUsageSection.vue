<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Where one article has been used, as an in-body section (kind:'section')
  - on the declarative ArticleDetail page. Self-fetches
  - GET /api/articles/{id}/usages, which derives the answer at read time from
  - the campaign templates that name the article and the blasts built on
  - those templates (marketing-articles spec, "An Article Reports Where It
  - Has Been Used"). Nothing is written here.
  -
  - Not a declarative object-list widget: the rows come from TWO different
  - schemas (campaignTemplate and blast) joined server-side into one answer,
  - which the object-list widget (one schema per instance) cannot express,
  - and the section leads with per-kind counts the same way Subscriptions
  - does for per-state counts.
  -
  - @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
  -->
<template>
	<div class="article-usage">
		<NcLoadingIcon v-if="loading" :size="24" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-else>
			<ul class="article-usage__counts">
				<li class="article-usage__count">
					<strong>{{ counts.template || 0 }}</strong>
					<span>{{ t('pipelinq', 'Templates') }}</span>
				</li>
				<li class="article-usage__count">
					<strong>{{ counts.blast || 0 }}</strong>
					<span>{{ t('pipelinq', 'Blasts') }}</span>
				</li>
			</ul>

			<p v-if="rows.length === 0" class="article-usage__empty">
				{{ t('pipelinq', 'This article has not been used anywhere yet.') }}
			</p>

			<div v-else class="article-usage__groups">
				<section v-for="group in visibleGroups" :key="group.kind">
					<h3>{{ groupLabel(group.kind) }}</h3>
					<ul class="article-usage__list">
						<li
							v-for="item in group.items"
							:key="item.kind + ':' + item.id"
							class="article-usage__row">
							<span>{{ item.name || item.id }}</span>
							<span class="article-usage__status">{{
								item.status || '—'
							}}</span>
						</li>
					</ul>
				</section>
			</div>
		</template>
	</div>
</template>

<script>
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { fetchArticleUsages } from '../../services/articlesApi.js'
import { groupUsages } from '../../services/articleStatus.js'

export default {
	name: 'ArticleUsageSection',

	components: {
		NcLoadingIcon,
		NcNoteCard,
	},

	inject: {
		cnSectionContext: { default: null },
	},

	props: {
		/** The article id, on the article detail page (`@objectId`). */
		articleId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: false,
			error: '',
			rows: [],
			counts: { template: 0, blast: 0 },
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
		 * @return {Array<object>} Groups that carry at least one usage.
		 */
		visibleGroups() {
			return groupUsages(this.rows).filter((group) => group.items.length > 0)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * The id this section is bound to, either the prop or the section
		 * context the page host provides.
		 *
		 * @return {string} The article id, or an empty string.
		 */
		effectiveId() {
			if (this.articleId) {
				return this.articleId
			}
			const ctx = this.cnSectionContext
			const bag =
				ctx && typeof ctx === 'object' && 'value' in ctx ? ctx.value : ctx
			return (bag && (bag.objectId || bag.articleId)) || ''
		},

		/**
		 * Load the usages for this article.
		 *
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
		 * @return {Promise<void>} Resolves when the rows are in place.
		 */
		async load() {
			const id = this.effectiveId()
			if (!id) {
				return
			}
			this.loading = true
			this.error = ''
			try {
				const envelope = await fetchArticleUsages(id)
				this.rows = envelope.data || []
				this.counts = envelope.counts || { template: 0, blast: 0 }
			} catch {
				this.error = this.t('pipelinq', 'The usages could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Translated group heading. A lookup by the fixed kind (not the
		 * vocabulary's English label) keeps this `t()` call a literal.
		 *
		 * @param {string} kind Either `template` or `blast`.
		 * @return {string} The translated group heading.
		 */
		groupLabel(kind) {
			const labels = {
				template: this.t('pipelinq', 'Campaign templates'),
				blast: this.t('pipelinq', 'Blasts'),
			}
			return labels[kind] || kind
		},
	},
}
</script>

<style scoped>
.article-usage__counts {
	display: flex;
	gap: 1.5rem;
	list-style: none;
	margin: 0 0 1rem;
	padding: 0;
}

.article-usage__count {
	display: flex;
	align-items: center;
	gap: 0.4rem;
}

.article-usage__empty {
	color: var(--color-text-maxcontrast);
}

.article-usage__groups {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.article-usage__groups h3 {
	margin: 0 0 0.35rem;
}

.article-usage__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.article-usage__row {
	display: flex;
	justify-content: space-between;
	padding-block: 0.35rem;
	border-block-end: 1px solid var(--color-border);
}

.article-usage__status {
	color: var(--color-text-maxcontrast);
}
</style>
