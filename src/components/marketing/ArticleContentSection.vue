<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - An article's rendered body as an in-body section (kind:'section') on the
  - declarative ArticleDetail page.
  -
  - Why this is not the built-in `text` widget: that widget renders markdown
  - from a literal `text` prop in the manifest, and only `config.bodyWidgets[]`
  - props carry `@object.<field>` token resolution on a detail page —
  - `config.fieldWidgets[]` is validated by the v2 schema but nothing in the
  - library renders it. So the rendered body, the hero image, the
  - agent-authored mark and the Edit action live here, in one registered
  - section, as design.md ("The body renders through an in-body section, not
  - a declarative widget") states plainly.
  -
  - The lifecycle actions are here too rather than driven by `lifecycleActions`
  - (ADR-062 rule 10): OR's TransitionEngine would flip `status` but publish
  - has a side effect the grammar cannot express — stamping `publishedAt`
  - once and never moving it — so the real moves go through
  - ArticleService::publish() / archive() / applyTransition() instead.
  -
  - @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
  -->
<template>
	<div class="article-content">
		<NcLoadingIcon v-if="loading" :size="24" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-else-if="effectiveArticle">
			<div class="article-content__header">
				<span
					class="article-content__chip"
					:style="{ borderColor: statusChip.color }">
					<span
						class="article-content__swatch"
						:style="{ backgroundColor: statusChip.color }"
						aria-hidden="true" />
					{{ statusLabel }}
				</span>
				<span
					v-if="effectiveArticle.agentAuthored"
					class="article-content__agent-mark">
					{{
						t('pipelinq', 'Drafted by {agent}', {
							agent:
								effectiveArticle.agentAuthoredBy
								|| t('pipelinq', 'an agent'),
						})
					}}
				</span>
			</div>

			<img
				v-if="heroImageUrl"
				:src="heroImageUrl"
				:alt="effectiveArticle.title || ''"
				class="article-content__hero" />

			<p v-if="effectiveArticle.summary" class="article-content__summary">
				{{ effectiveArticle.summary }}
			</p>

			<!-- eslint-disable-next-line vue/no-v-html -- renderedBody comes from cnRenderMarkdown(), which sanitises through DOMPurify -->
			<div class="article-content__body" v-html="renderedBody" />

			<ul
				v-if="effectiveArticle.links && effectiveArticle.links.length"
				class="article-content__links">
				<li v-for="(link, index) in effectiveArticle.links" :key="index">
					<a :href="link.url" target="_blank" rel="noopener noreferrer">
						{{ link.label || link.url }}
					</a>
				</li>
			</ul>

			<p v-if="actionError" class="article-content__error" role="alert">
				{{ actionError }}
			</p>

			<div class="article-content__actions">
				<NcButton
					variant="secondary"
					data-testid="article-edit"
					@click="showEdit = true">
					{{ t('pipelinq', 'Edit') }}
				</NcButton>
				<NcButton
					v-for="action in transitions"
					:key="action.id"
					variant="tertiary"
					:disabled="busy"
					:data-testid="'article-action-' + action.id"
					@click="runTransition(action)">
					{{ actionLabel(action.id) }}
				</NcButton>
			</div>
		</template>

		<ArticleEditModal
			v-if="showEdit && effectiveArticle"
			:article="effectiveArticle"
			@close="showEdit = false"
			@saved="onSaved" />
	</div>
</template>

<script>
import { cnRenderMarkdown } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import ArticleEditModal from '../../modals/ArticleEditModal.vue'
import {
	archiveArticle,
	publishArticle,
	transitionArticle,
} from '../../services/articlesApi.js'
import { chipForStatus, transitionsForStatus } from '../../services/articleStatus.js'

export default {
	name: 'ArticleContentSection',

	components: {
		ArticleEditModal,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},

	inject: {
		cnSectionContext: { default: null },
	},

	props: {
		/** The article, when the page host already resolved it. */
		article: {
			type: Object,
			default: null,
		},

		/** The article id, when only the id was resolved (`@objectId`). */
		articleId: {
			type: String,
			default: '',
		},
	},

	emits: ['refresh'],

	data() {
		return {
			loading: false,
			error: '',
			actionError: '',
			busy: false,
			showEdit: false,
			resolvedArticle: null,
		}
	},

	computed: {
		/**
		 * The article record whichever way it arrived: as a resolved prop, or
		 * loaded here by id.
		 *
		 * @return {object|null} The article, or null while unresolved.
		 */
		effectiveArticle() {
			return this.article || this.resolvedArticle
		},

		/**
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-the-detail-page-renders-the-body-as-formatted-text
		 * @return {string} The article body as sanitised HTML.
		 */
		renderedBody() {
			return cnRenderMarkdown(
				(this.effectiveArticle && this.effectiveArticle.body) || '',
			)
		},

		/**
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
		 * @return {{label: string, color: string}} The status chip.
		 */
		statusChip() {
			return chipForStatus(
				this.effectiveArticle && this.effectiveArticle.status,
			)
		},

		/**
		 * Translated status label. A lookup by the fixed status value (not
		 * the vocabulary's English label) keeps this `t()` call a literal.
		 *
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
		 * @return {string} The translated status label.
		 */
		statusLabel() {
			const labels = {
				draft: this.t('pipelinq', 'Draft'),
				review: this.t('pipelinq', 'In review'),
				published: this.t('pipelinq', 'Published'),
				archived: this.t('pipelinq', 'Archived'),
			}
			return (
				labels[this.effectiveArticle && this.effectiveArticle.status]
				|| this.t('pipelinq', 'Unknown')
			)
		},

		/**
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
		 * @return {Array<object>} The lifecycle moves legal from the current status.
		 */
		transitions() {
			return transitionsForStatus(
				this.effectiveArticle && this.effectiveArticle.status,
			)
		},

		/**
		 * A Files path renders through Nextcloud's legacy `file=`-addressed
		 * preview endpoint; an absolute URL (an image hosted elsewhere) is
		 * used as-is.
		 *
		 * @return {string} The hero image URL, or an empty string.
		 */
		heroImageUrl() {
			const path = this.effectiveArticle && this.effectiveArticle.heroImage
			if (!path) {
				return ''
			}
			if (/^https?:\/\//.test(path)) {
				return path
			}
			return `${generateUrl('/core/preview.png')}?file=${encodeURIComponent(path)}&x=1200&y=630&a=1`
		},
	},

	watch: {
		articleId: {
			immediate: true,
			/**
			 * Resolve the article by id when the host gave the section only an
			 * id (`@objectId`) rather than the whole record.
			 *
			 * @return {void}
			 */
			handler() {
				if (!this.article && this.effectiveId()) {
					this.load()
				}
			},
		},
	},

	methods: {
		/**
		 * The id this section is bound to, either the prop or the section
		 * context the page host provides.
		 *
		 * @return {string} The article id, or an empty string.
		 */
		effectiveId() {
			return (
				this.articleId
				|| (this.article && (this.article.id || this.article.uuid))
				|| this.contextId()
			)
		},

		/**
		 * The object id the page host resolved, when no prop carries one.
		 *
		 * @return {string} The id, or an empty string.
		 */
		contextId() {
			const ctx = this.cnSectionContext
			const bag =
				ctx && typeof ctx === 'object' && 'value' in ctx ? ctx.value : ctx
			return (bag && (bag.objectId || bag.articleId)) || ''
		},

		/**
		 * Load the article when only its id is known.
		 *
		 * @return {Promise<void>} Resolves when the article is in place.
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(
					generateUrl(`/apps/pipelinq/api/articles/${this.effectiveId()}`),
				)
				this.resolvedArticle = data?.article || null
			} catch {
				this.error = this.t('pipelinq', 'This article could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Refresh after a save or a lifecycle move, whichever way the host
		 * expects it.
		 *
		 * @return {Promise<void>} Resolves when the article is current again.
		 */
		async refresh() {
			this.$emit('refresh')
			if (!this.article) {
				await this.load()
			}
		},

		/**
		 * Close the edit modal and refresh.
		 *
		 * @return {Promise<void>} Resolves when the section reflects the save.
		 */
		async onSaved() {
			this.showEdit = false
			await this.refresh()
		},

		/**
		 * Translated label for one transition button. A lookup by the fixed
		 * transition id (not the vocabulary's English label) keeps every
		 * `t()` call here a literal, which is what the l10n extraction
		 * tooling requires.
		 *
		 * @param {string} id The transition id from `transitionsForStatus()`.
		 * @return {string} The translated button label.
		 */
		actionLabel(id) {
			const labels = {
				submitForReview: this.t('pipelinq', 'Submit for review'),
				publish: this.t('pipelinq', 'Publish'),
				returnToDraft: this.t('pipelinq', 'Return to draft'),
				archive: this.t('pipelinq', 'Archive'),
				restore: this.t('pipelinq', 'Restore as draft'),
			}
			return labels[id] || id
		},

		/**
		 * Run one lifecycle move.
		 *
		 * @param {object} action One entry from `transitionsForStatus()`.
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
		 * @return {Promise<void>} Resolves when the move has been applied.
		 */
		async runTransition(action) {
			const id = this.effectiveId()
			if (!id) {
				return
			}
			this.busy = true
			this.actionError = ''
			try {
				if (action.endpoint === 'publish') {
					await publishArticle(id)
				} else if (action.endpoint === 'archive') {
					await archiveArticle(id)
				} else {
					await transitionArticle(id, action.id)
				}
				await this.refresh()
			} catch (e) {
				this.actionError =
					e?.response?.data?.error
					|| this.t('pipelinq', 'That move could not be applied.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.article-content {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.article-content__header {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	flex-wrap: wrap;
}

.article-content__chip {
	display: inline-flex;
	align-items: center;
	gap: 0.35rem;
	padding: 0.1rem 0.5rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 1rem);
}

.article-content__swatch {
	display: inline-block;
	width: 0.6rem;
	height: 0.6rem;
	border-radius: 50%;
}

.article-content__agent-mark {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.article-content__hero {
	width: 100%;
	max-height: 320px;
	object-fit: cover;
	border-radius: var(--border-radius-large, 8px);
}

.article-content__summary {
	color: var(--color-text-maxcontrast);
}

.article-content__body :deep(h1),
.article-content__body :deep(h2),
.article-content__body :deep(h3) {
	margin-block-start: 1rem;
}

.article-content__links {
	margin: 0;
	padding-inline-start: 1.25rem;
}

.article-content__error {
	color: var(--color-error);
	font-weight: 600;
	margin: 0;
}

.article-content__actions {
	display: flex;
	gap: 0.5rem;
	flex-wrap: wrap;
}
</style>
