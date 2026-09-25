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
			<div class="article-content__toolbar">
				<div class="article-content__meta">
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
				<div class="article-content__actions">
					<NcButton
						v-for="action in transitions"
						:key="action.id"
						variant="tertiary"
						:disabled="busy"
						:data-testid="'article-action-' + action.id"
						@click="runTransition(action)">
						{{ actionLabel(action.id) }}
					</NcButton>
					<NcButton
						variant="secondary"
						data-testid="article-edit"
						@click="showEdit = true">
						<template #icon>
							<PencilOutline :size="20" />
						</template>
						{{ t('pipelinq', 'Edit') }}
					</NcButton>
				</div>
			</div>

			<NcNoteCard v-if="actionError" type="error" class="article-content__note">
				{{ actionError }}
			</NcNoteCard>

			<!-- The article as a reader will see it, framed as a page of its
			     own so it does not blend into the app around it. -->
			<article class="article-preview" :lang="effectiveArticle.language || null">
				<template v-if="heroImageUrl">
					<img
						v-if="!heroFailed"
						:src="heroImageUrl"
						:alt="effectiveArticle.title || ''"
						class="article-preview__hero"
						@error="heroFailed = true">
					<div v-else class="article-preview__hero article-preview__hero--missing">
						<ImageOffOutline :size="32" />
						<span>{{ t('pipelinq', 'The hero image could not be loaded.') }}</span>
						<code>{{ effectiveArticle.heroImage }}</code>
					</div>
				</template>

				<div class="article-preview__page">
					<h1 class="article-preview__title">
						{{ effectiveArticle.title }}
					</h1>
					<p v-if="effectiveArticle.summary" class="article-preview__summary">
						{{ effectiveArticle.summary }}
					</p>

					<!-- eslint-disable-next-line vue/no-v-html -- renderedBody comes from cnRenderMarkdown(), which sanitises through DOMPurify -->
					<div class="article-content__body article-preview__body" v-html="renderedBody" />

					<footer
						v-if="effectiveArticle.links && effectiveArticle.links.length"
						class="article-preview__links">
						<h2 class="article-preview__links-title">
							{{ t('pipelinq', 'Links') }}
						</h2>
						<ul>
							<li v-for="(link, index) in effectiveArticle.links" :key="index">
								<a :href="link.url" target="_blank" rel="noopener noreferrer">
									{{ link.label || link.url }}
								</a>
							</li>
						</ul>
					</footer>
				</div>
			</article>
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
import ImageOffOutline from 'vue-material-design-icons/ImageOffOutline.vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
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
		ImageOffOutline,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		PencilOutline,
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
			heroFailed: false,
		}
	},

	computed: {
		/**
		 * The article record whichever way it arrived: as a resolved prop, or
		 * loaded here by id.
		 *
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
		 * @return {object|null} The article, or null while unresolved.
		 */
		effectiveArticle() {
			return this.article || this.resolvedArticle
		},

		/**
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
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
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
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
		heroImageUrl() {
			this.heroFailed = false
		},

		articleId: {
			immediate: true,
			/**
			 * Resolve the article by id when the host gave the section only an
			 * id (`@objectId`) rather than the whole record.
			 *
			 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
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
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
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
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
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
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
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
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
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
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
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
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
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
	gap: 16px;
}

.article-content__toolbar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px 16px;
}

.article-content__meta,
.article-content__actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.article-content__chip {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 2px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 16px);
}

.article-content__swatch {
	display: inline-block;
	width: 10px;
	height: 10px;
	border-radius: 50%;
}

.article-content__agent-mark {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.article-content__note {
	margin: 0;
}

.article-preview {
	width: 100%;
	max-width: 800px;
	margin: 0 auto;
	overflow: hidden;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container-large, 12px);
	background: var(--color-main-background);
	box-shadow: 0 2px 12px var(--color-box-shadow);
}

.article-preview__hero {
	display: block;
	width: 100%;
	aspect-ratio: 1200 / 630;
	object-fit: cover;
}

.article-preview__hero--missing {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 6px;
	padding: 16px;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.article-preview__hero--missing code {
	font-size: 0.85em;
	word-break: break-all;
}

.article-preview__page {
	padding: 32px 40px 40px;
	line-height: 1.6;
}

.article-preview__title {
	margin: 0 0 12px;
	font-size: 2em;
	font-weight: 700;
	line-height: 1.25;
}

.article-preview__summary {
	margin: 0 0 24px;
	color: var(--color-text-maxcontrast);
	font-size: 1.15em;
}

/* Nextcloud resets headings, lists and links, so the article's own
   typography is restored here. */
.article-preview__body :deep(h1),
.article-preview__body :deep(h2),
.article-preview__body :deep(h3),
.article-preview__body :deep(h4) {
	margin: 1.5em 0 0.5em;
	font-weight: 700;
	line-height: 1.3;
}

.article-preview__body :deep(h1) {
	font-size: 1.6em;
}

.article-preview__body :deep(h2) {
	font-size: 1.35em;
}

.article-preview__body :deep(h3) {
	font-size: 1.15em;
}

.article-preview__body :deep(p),
.article-preview__body :deep(ul),
.article-preview__body :deep(ol),
.article-preview__body :deep(blockquote),
.article-preview__body :deep(pre) {
	margin: 0 0 1em;
}

.article-preview__body :deep(ul),
.article-preview__body :deep(ol) {
	padding-inline-start: 1.5em;
}

.article-preview__body :deep(ul) {
	list-style: disc;
}

.article-preview__body :deep(ol) {
	list-style: decimal;
}

.article-preview__body :deep(li) {
	margin-bottom: 0.25em;
}

.article-preview__body :deep(a),
.article-preview__links a {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.article-preview__body :deep(blockquote) {
	padding-inline-start: 1em;
	border-inline-start: 4px solid var(--color-border-dark);
	color: var(--color-text-maxcontrast);
}

.article-preview__body :deep(code) {
	padding: 1px 4px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.9em;
}

.article-preview__body :deep(pre) {
	padding: 12px;
	overflow-x: auto;
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);
}

.article-preview__body :deep(pre code) {
	padding: 0;
	background: none;
}

.article-preview__body :deep(img) {
	max-width: 100%;
	height: auto;
	border-radius: var(--border-radius-large);
}

.article-preview__body :deep(hr) {
	margin: 2em 0;
	border: 0;
	border-top: 1px solid var(--color-border);
}

.article-preview__body :deep(table) {
	margin: 0 0 1em;
	border-collapse: collapse;
}

.article-preview__body :deep(th),
.article-preview__body :deep(td) {
	padding: 6px 10px;
	border: 1px solid var(--color-border);
}

.article-preview__body > :deep(:last-child) {
	margin-bottom: 0;
}

.article-preview__links {
	margin-top: 32px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.article-preview__links-title {
	margin: 0 0 8px;
	font-size: 1em;
	font-weight: 700;
}

.article-preview__links ul {
	margin: 0;
	padding-inline-start: 1.5em;
	list-style: disc;
}

@media (max-width: 720px) {
	.article-preview__page {
		padding: 20px 16px 24px;
	}

	.article-preview__title {
		font-size: 1.6em;
	}
}
</style>
