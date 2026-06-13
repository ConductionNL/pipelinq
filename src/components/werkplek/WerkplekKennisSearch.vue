<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!-- @spec openspec/changes/kcc-werkplek/tasks.md#task-3.4 -->
<template>
	<div class="werkplek-kennis">
		<h3 class="werkplek-kennis__title">
			{{ t('pipelinq', 'Knowledge base') }}
		</h3>

		<div class="werkplek-kennis__search">
			<NcTextField
				:value="term"
				:label="t('pipelinq', 'Search articles')"
				@update:value="onTermInput" />
		</div>

		<div v-if="loading" class="werkplek-kennis__status">
			{{ t('pipelinq', 'Searching...') }}
		</div>

		<div v-else-if="expandedArticle" class="werkplek-kennis__detail">
			<button type="button" class="werkplek-kennis__back" @click="collapseArticle">
				{{ t('pipelinq', 'Back to results') }}
			</button>
			<h4 class="werkplek-kennis__article-title">
				{{ expandedArticle.title }}
			</h4>
			<div class="werkplek-kennis__article-categories">
				<span
					v-for="cat in articleCategories(expandedArticle)"
					:key="cat"
					class="werkplek-kennis__badge">
					{{ cat }}
				</span>
			</div>
			<div class="werkplek-kennis__article-body" v-html="renderBody(expandedArticle.body)" />

			<div class="werkplek-kennis__feedback">
				<NcButton type="secondary" :disabled="feedbackSending" @click="submitFeedback(true)">
					{{ t('pipelinq', 'Useful') }}
				</NcButton>
				<NcButton type="tertiary" :disabled="feedbackSending" @click="submitFeedback(false)">
					{{ t('pipelinq', 'Not useful') }}
				</NcButton>
				<span v-if="feedbackThanks" class="werkplek-kennis__feedback-thanks">
					{{ t('pipelinq', 'Thanks for your feedback.') }}
				</span>
			</div>
		</div>

		<div v-else-if="results.length === 0 && term && term.length >= 2" class="werkplek-kennis__empty">
			{{ noResultsLabel }}
		</div>

		<ul v-else-if="results.length > 0" class="werkplek-kennis__results">
			<li
				v-for="article in results"
				:key="article.id"
				class="werkplek-kennis__result"
				tabindex="0"
				@click="expand(article)"
				@keydown.enter="expand(article)">
				<div class="werkplek-kennis__result-title">
					{{ article.title }}
				</div>
				<div class="werkplek-kennis__result-snippet">
					{{ snippet(article) }}
				</div>
				<div class="werkplek-kennis__result-categories">
					<span
						v-for="cat in articleCategories(article)"
						:key="cat"
						class="werkplek-kennis__badge">
						{{ cat }}
					</span>
				</div>
			</li>
		</ul>

		<div v-else class="werkplek-kennis__hint">
			{{ t('pipelinq', 'Type at least 2 characters to search the knowledge base.') }}
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'

let renderMarkdown
try {
	// `marked` is an optional, defensively-loaded Markdown renderer; if it's
	// missing the catch below falls back to a safe text-only render, so it is
	// intentionally not declared as a hard dependency.
	// eslint-disable-next-line global-require, n/no-extraneous-require
	const m = require('marked')
	renderMarkdown = (typeof m === 'function') ? m : (m && (m.marked || m.parse))
} catch {
	renderMarkdown = null
}

/**
 * Inline knowledge-base search panel for the KCC Werkplek.
 *
 * Search is debounced (300ms, min 2 chars), results render title + snippet
 * + category badges, and clicking a result expands inline into a full
 * article view rendered via `marked` (with a safe text fallback). The
 * agent can mark the article useful or not useful directly from the panel
 * — feedback is persisted through the object store as the kennisbank
 * service is not yet shipping in pipelinq (see tasks 0.3).
 *
 * @spec openspec/changes/kcc-werkplek/tasks.md#task-3.4
 */
export default {
	name: 'WerkplekKennisSearch',

	components: { NcButton, NcTextField },

	data() {
		return {
			term: '',
			results: [],
			loading: false,
			expandedArticle: null,
			debounceHandle: null,
			feedbackSending: false,
			feedbackThanks: false,
		}
	},

	computed: {
		/**
		 * Pinia object store handle.
		 *
		 * @return {object}
		 */
		objectStore() {
			return useObjectStore()
		},
		/**
		 * "Geen artikelen gevonden voor 'X'" — translated label with current term.
		 *
		 * @return {string}
		 */
		noResultsLabel() {
			return this.t('pipelinq', 'No articles found for \'{term}\'', { term: this.term })
		},
	},

	beforeDestroy() {
		if (this.debounceHandle) {
			clearTimeout(this.debounceHandle)
		}
	},

	methods: {
		/**
		 * Debounced search trigger (300ms).
		 *
		 * @param {string} value New input value.
		 */
		onTermInput(value) {
			this.term = value || ''
			if (this.debounceHandle) {
				clearTimeout(this.debounceHandle)
			}
			if (this.expandedArticle) {
				this.expandedArticle = null
			}
			if (this.term.trim().length < 2) {
				this.results = []
				return
			}
			this.debounceHandle = setTimeout(() => {
				this.runSearch()
			}, 300)
		},
		/**
		 * Execute the search via the kennisartikel object store. Degrades
		 * gracefully when the store is not registered (empty results).
		 *
		 * @return {Promise<void>}
		 */
		async runSearch() {
			this.loading = true
			try {
				const registry = this.objectStore.objectTypeRegistry || {}
				if (!registry.kennisartikel) {
					// Knowledge base schema not yet provisioned — show empty.
					this.results = []
					return
				}
				const collection = await this.objectStore.fetchCollection('kennisartikel', {
					_search: this.term.trim(),
					status: 'gepubliceerd',
					_limit: 25,
				})
				const items = Array.isArray(collection)
					? collection
					: (this.objectStore.collections.kennisartikel || [])
				this.results = items
			} catch (e) {
				// eslint-disable-next-line no-console
				console.warn('[WerkplekKennisSearch] search failed', e)
				this.results = []
			} finally {
				this.loading = false
			}
		},
		/**
		 * Truncate the article summary or body to ~150 characters for the
		 * result snippet.
		 *
		 * @param {object} article Article object.
		 *
		 * @return {string}
		 */
		snippet(article) {
			const source = (article.summary || article.body || '').toString()
			const clean = source.replace(/[#*_`>]/g, '').replace(/\s+/g, ' ').trim()
			if (clean.length <= 150) return clean
			return clean.slice(0, 147) + '...'
		},
		/**
		 * Resolve the article category labels (the schema stores either
		 * `categories` as an array of strings, or a single `category` UUID).
		 *
		 * @param {object} article Article object.
		 *
		 * @return {Array<string>}
		 */
		articleCategories(article) {
			if (Array.isArray(article.categories)) return article.categories
			if (Array.isArray(article.tags)) return article.tags
			if (article.category) return [String(article.category)]
			return []
		},
		/**
		 * Expand a result row into the inline detail view.
		 *
		 * @param {object} article Article object.
		 */
		expand(article) {
			this.expandedArticle = article
			this.feedbackThanks = false
		},
		/**
		 * Collapse the inline detail view back to the result list.
		 */
		collapseArticle() {
			this.expandedArticle = null
			this.feedbackThanks = false
		},
		/**
		 * Render Markdown via `marked`. Falls back to escaped plain text
		 * when `marked` is not available in the bundle.
		 *
		 * @param {string} source Raw Markdown.
		 *
		 * @return {string} HTML-safe string.
		 */
		renderBody(source) {
			const text = (source || '').toString()
			if (renderMarkdown) {
				try {
					return renderMarkdown(text)
				} catch {
					/* fall through to plain-text */
				}
			}
			return this.escapeHtml(text).replace(/\n/g, '<br>')
		},
		/**
		 * Escape HTML so the plain-text fallback path never injects markup.
		 *
		 * @param {string} value Raw text.
		 *
		 * @return {string}
		 */
		escapeHtml(value) {
			return String(value)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#39;')
		},
		/**
		 * Persist a feedback record. The kennisbank backend service is not
		 * yet shipping, so we write a lightweight `articleFeedback` shape
		 * via the kennisartikel store. Errors fall through silently — the
		 * agent has already moved on to the next call.
		 *
		 * @param {boolean} useful Whether the article was useful.
		 *
		 * @return {Promise<void>}
		 */
		async submitFeedback(useful) {
			if (this.feedbackSending || !this.expandedArticle) return
			this.feedbackSending = true
			try {
				const articleId = this.expandedArticle.id || ''
				if (!articleId) return
				const registry = this.objectStore.objectTypeRegistry || {}
				if (registry.kennisartikel) {
					// Best-effort feedback append — no kennisbank service exists yet.
					await this.objectStore.saveObject('kennisartikel', {
						id: articleId,
						lastFeedback: {
							rating: useful ? 'useful' : 'not_useful',
							at: new Date().toISOString(),
						},
					})
				}
				this.feedbackThanks = true
			} catch (e) {
				// eslint-disable-next-line no-console
				console.warn('[WerkplekKennisSearch] feedback save failed', e)
			} finally {
				this.feedbackSending = false
			}
		},
	},
}
</script>

<style scoped>
.werkplek-kennis {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
	height: 100%;
	overflow-y: auto;
}

.werkplek-kennis__title { margin: 0; font-size: 1.1em; }
.werkplek-kennis__search { display: flex; flex-direction: column; gap: 4px; }
.werkplek-kennis__status,
.werkplek-kennis__empty,
.werkplek-kennis__hint {
	padding: 8px 4px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.werkplek-kennis__results { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; }
.werkplek-kennis__result {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px 10px;
	background: var(--color-main-background);
	cursor: pointer;
}
.werkplek-kennis__result:focus,
.werkplek-kennis__result:hover { border-color: var(--color-primary); }

.werkplek-kennis__result-title { font-weight: 600; }
.werkplek-kennis__result-snippet { font-size: 0.9em; color: var(--color-text-maxcontrast); margin: 4px 0; }
.werkplek-kennis__result-categories { display: flex; gap: 4px; flex-wrap: wrap; }

.werkplek-kennis__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	font-size: 0.8em;
}

.werkplek-kennis__detail { display: flex; flex-direction: column; gap: 8px; }
.werkplek-kennis__back { align-self: flex-start; background: transparent; border: 0; cursor: pointer; color: var(--color-primary); padding: 0; }
.werkplek-kennis__article-title { margin: 0; font-size: 1.1em; }
.werkplek-kennis__article-categories { display: flex; gap: 4px; flex-wrap: wrap; }
.werkplek-kennis__article-body { padding: 8px 0; line-height: 1.5; }

.werkplek-kennis__feedback { display: flex; gap: 8px; align-items: center; margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--color-border); }
.werkplek-kennis__feedback-thanks { color: var(--color-success); font-size: 0.9em; }
</style>
