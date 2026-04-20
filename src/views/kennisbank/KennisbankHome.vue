<template>
	<div class="kennisbank-home">
		<div class="kennisbank-home__header">
			<h2>{{ t('pipelinq', 'Knowledge Base') }}</h2>
		</div>

		<div class="kennisbank-home__search">
			<NcTextField
				ref="searchInput"
				:value.sync="searchQuery"
				:label="t('pipelinq', 'Search articles...')"
				:show-trailing-button="searchQuery !== ''"
				trailing-button-icon="close"
				@trailing-button-click="searchQuery = ''"
				@input="onSearch" />
		</div>

		<div class="kennisbank-home__content">
			<div class="kennisbank-home__sidebar">
				<h3>{{ t('pipelinq', 'Categories') }}</h3>
				<NcLoadingIcon v-if="loadingCategories" />
				<ul v-else class="category-tree">
					<li
						v-for="cat in rootCategories"
						:key="cat.id"
						class="category-tree__item"
						:class="{ 'category-tree__item--active': selectedCategory === cat.id }"
						@click="selectCategory(cat.id)">
						{{ cat.name }}
						<span class="category-count">({{ getCategoryCount(cat.id) }})</span>
					</li>
				</ul>
				<NcButton
					v-if="selectedCategory"
					type="tertiary"
					@click="selectCategory(null)">
					{{ t('pipelinq', 'Show all') }}
				</NcButton>
			</div>

			<div class="kennisbank-home__main">
				<NcLoadingIcon v-if="loading" />

				<div v-else-if="articles.length === 0" class="kennisbank-home__empty">
					<NcEmptyContent
						:name="searchQuery ? t('pipelinq', 'No results found') : t('pipelinq', 'No articles yet')"
						:description="searchQuery ? t('pipelinq', 'Try different search terms or browse categories') : t('pipelinq', 'Create your first knowledge base article')">
						<template #action>
							<NcButton type="primary" @click="$router.push({ name: 'KennisbankNew' })">
								{{ t('pipelinq', 'New article') }}
							</NcButton>
						</template>
					</NcEmptyContent>
				</div>

				<div v-else class="article-grid">
					<div
						v-for="article in articles"
						:key="article.id"
						class="article-card"
						tabindex="0"
						@click="$router.push({ name: 'KennisbankDetail', params: { id: article.id } })"
						@keydown.enter="$router.push({ name: 'KennisbankDetail', params: { id: article.id } })">
						<div class="article-card__top">
							<span
								class="status-badge"
								:class="'status-badge--' + (article.status || 'concept')">
								{{ article.status || 'concept' }}
							</span>
							<span
								class="visibility-badge"
								:class="'visibility-badge--' + (article.visibility || 'intern')">
								{{ article.visibility === 'openbaar' ? t('pipelinq', 'Public') : t('pipelinq', 'Internal') }}
							</span>
						</div>
						<h3 class="article-card__title">
							{{ article.title }}
						</h3>
						<p v-if="article.summary" class="article-card__summary">
							{{ article.summary }}
						</p>
						<div class="article-card__meta">
							<span v-if="article.tags && article.tags.length">
								{{ article.tags.slice(0, 3).join(', ') }}
							</span>
						</div>
					</div>
				</div>

				<NcButton
					v-if="hasMore"
					type="secondary"
					@click="loadMore">
					{{ t('pipelinq', 'Load more') }}
				</NcButton>
				<div class="kennisbank-header">
					<h2>{{ t('pipelinq', 'Kennisbank') }}</h2>
					<NcButton type="primary" @click="$router.push({ name: 'KennisbankNew' })">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('pipelinq', 'New Article') }}
					</NcButton>
				</div>
				<div class="kennisbank-search">
					<NcTextField ref="searchInput"
						:value.sync="searchQuery"
						:label="t('pipelinq', 'Search articles...')"
						:show-trailing-button="searchQuery.length > 0"
						trailing-button-icon="close"
						@trailing-button-click="clearSearch"
						@update:value="onSearchInput" />
					<div v-if="autocompleteResults.length > 0" class="autocomplete-dropdown">
						<div v-for="item in autocompleteResults"
							:key="item.id"
							class="autocomplete-item"
							@click="goToArticle(item.id)">
							<span class="autocomplete-title">{{ item.title }}</span>
							<span class="autocomplete-category">{{ getCategoryName(item.category) }}</span>
						</div>
					</div>
				</div>
				<div class="kennisbank-content">
					<div class="kennisbank-sidebar">
						<CategoryTree :categories="categoryTree"
							:article-counts="articleCountsByCategory"
							:selected-category="selectedCategory"
							@select="onCategorySelect" />
					</div>
					<div class="kennisbank-main">
						<div v-if="searchQuery && searchQuery.length >= 2">
							<div v-if="searchLoading" style="text-align:center;padding:40px">
								<NcLoadingIcon :size="32" />
							</div>
							<div v-else-if="searchResults.length === 0" style="text-align:center;padding:40px;color:var(--color-text-maxcontrast)">
								{{ t('pipelinq', 'No results found') }}
							</div>
							<div v-else class="article-list">
								<ArticleListItem v-for="a in searchResults"
									:key="a.id"
									:article="a"
									:category-name="getCategoryName(a.category)"
									:search-query="searchQuery"
									@click="goToArticle(a.id)" />
							</div>
						</div>
						<div v-else>
							<div v-if="recentlyViewed.length > 0 && !selectedCategory" style="margin-bottom:24px">
								<h3 style="font-size:14px;color:var(--color-text-maxcontrast)">
									{{ t('pipelinq', 'Recently viewed') }}
								</h3>
								<div v-for="item in recentlyViewed"
									:key="item.id"
									style="padding:8px 12px;cursor:pointer;border-radius:var(--border-radius)"
									@click="goToArticle(item.id)">
									{{ item.title }}
								</div>
							</div>
							<div v-if="loading" style="text-align:center;padding:40px">
								<NcLoadingIcon :size="32" />
							</div>
							<div v-else-if="visibleArticles.length === 0" style="text-align:center;padding:40px;color:var(--color-text-maxcontrast)">
								{{ t('pipelinq', 'No articles published yet') }}
							</div>
							<div v-else class="article-list">
								<ArticleListItem v-for="a in visibleArticles"
									:key="a.id"
									:article="a"
									:category-name="getCategoryName(a.category)"
									@click="goToArticle(a.id)" />
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcTextField, NcButton, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'

export default {
	name: 'KennisbankHome',
	components: {
		NcTextField,
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
	},
	data() {
		return {
			searchQuery: '',
			selectedCategory: null,
			articles: [],
			rootCategories: [],
			loading: false,
			loadingCategories: false,
			hasMore: false,
			offset: 0,
			limit: 20,
			searchTimeout: null,
		}
	},
	mounted() {
		this.fetchCategories()
		this.fetchArticles()
	},
	methods: {
		onSearch() {
			clearTimeout(this.searchTimeout)
			this.searchTimeout = setTimeout(() => {
				this.offset = 0
				this.articles = []
				this.fetchArticles()
			}, 300)
		},
		selectCategory(categoryId) {
			this.selectedCategory = categoryId
			this.offset = 0
			this.articles = []
			this.fetchArticles()
		},
		async fetchCategories() {
			this.loadingCategories = true
			try {
				// Categories are fetched from OpenRegister directly
				this.rootCategories = []
			} catch (error) {
				console.error('Failed to fetch categories:', error)
			} finally {
				this.loadingCategories = false
			}
		},
		async fetchArticles() {
			this.loading = true
			try {
				// Articles are fetched from OpenRegister directly via the object store
				this.articles = []
				this.hasMore = false
			} catch (error) {
				console.error('Failed to fetch articles:', error)
			} finally {
				this.loading = false
			}
		},
		loadMore() {
			this.offset += this.limit
			this.fetchArticles()
		},
		getCategoryCount(categoryId) {
			return this.articles.filter(a =>
				a.categories && a.categories.includes(categoryId),
			).length
		},
	},
}
</script>

<script>
import { NcButton, NcTextField, NcLoadingIcon } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { useKennisbankStore } from '../../store/modules/kennisbank.js'
import CategoryTree from '../../components/kennisbank/CategoryTree.vue'
import ArticleListItem from '../../components/kennisbank/ArticleListItem.vue'
let debounce = null
export default {
	name: 'KennisbankHome',
	components: { NcButton, NcTextField, NcLoadingIcon, Plus, CategoryTree, ArticleListItem },
	data() { return { searchQuery: '' } },
	computed: {
		store() { return useKennisbankStore() },
		visibleArticles() { return this.store.visibleArticles },
		searchResults() { return this.store.searchResults },
		autocompleteResults() { return this.store.autocompleteResults },
		searchLoading() { return this.store.searchLoading },
		loading() { return this.store.loading },
		categoryTree() { return this.store.categoryTree },
		articleCountsByCategory() { return this.store.articleCountsByCategory },
		selectedCategory() { return this.store.selectedCategory },
		recentlyViewed() { return this.store.recentlyViewed },
	},
	mounted() { this.store.fetchCategories(); this.store.fetchArticles(); this.$nextTick(() => { const el = this.$refs.searchInput?.$el?.querySelector('input'); if (el) el.focus() }) },
	methods: {
		getCategoryName(id) { return this.store.getCategoryName(id) },
		onSearchInput(v) { this.searchQuery = v; clearTimeout(debounce); debounce = setTimeout(() => { if (v.length >= 3) this.store.autocompleteArticles(v); else this.store.autocompleteResults = []; if (v.length >= 2) this.store.searchArticles(v) }, 300) },
		clearSearch() { this.searchQuery = ''; this.store.searchResults = []; this.store.autocompleteResults = [] },
		onCategorySelect(id) { this.store.selectedCategory = id === this.store.selectedCategory ? null : id; this.store.fetchArticles() },
		goToArticle(id) { this.store.autocompleteResults = []; this.$router.push({ name: 'KennisbankDetail', params: { id } }) },
	},
}
</script>
<style scoped>
.kennisbank-home {
	padding: 20px;
	max-width: 1200px;
	margin: 0 auto;
}

.kennisbank-home__header {
	margin-bottom: 20px;
}

.kennisbank-home__search {
	margin-bottom: 20px;
	max-width: 600px;
}

.kennisbank-home__content {
	display: flex;
	gap: 24px;
}

.kennisbank-home__sidebar {
	width: 250px;
	flex-shrink: 0;
}

.kennisbank-home__main {
	flex: 1;
	min-width: 0;
}

.category-tree {
	list-style: none;
	padding: 0;
	margin: 0;
}

.category-tree__item {
	padding: 8px 12px;
	cursor: pointer;
	border-radius: var(--border-radius);
}

.category-tree__item:hover {
	background: var(--color-background-hover);
}

.category-tree__item--active {
	background: var(--color-primary-element-light);
	font-weight: bold;
}

.category-count {
	color: var(--color-text-lighter);
	font-size: 0.85em;
}

.article-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
	gap: 16px;
}

.article-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	cursor: pointer;
	transition: box-shadow 0.2s;
}

.article-card:hover,
.article-card:focus {
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
	outline: none;
}

.article-card__top {
	display: flex;
	gap: 8px;
	margin-bottom: 8px;
}

.article-card__title {
	margin: 0 0 8px;
	font-size: 1.1em;
}

.article-card__summary {
	color: var(--color-text-lighter);
	font-size: 0.9em;
	margin: 0 0 8px;
	display: -webkit-box;
	-webkit-line-clamp: 3;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

.article-card__meta {
	font-size: 0.8em;
	color: var(--color-text-lighter);
}

.status-badge {
	padding: 2px 8px;
	border-radius: var(--border-radius);
	font-size: 0.75em;
	font-weight: 600;
	text-transform: uppercase;
}

.status-badge--concept { background: var(--color-warning); color: #000; }

.status-badge--gepubliceerd { background: var(--color-success); color: #fff; }

.status-badge--gearchiveerd { background: var(--color-text-lighter); color: #fff; }

.visibility-badge {
	padding: 2px 8px;
	border-radius: var(--border-radius);
	font-size: 0.75em;
}

.visibility-badge--openbaar { background: var(--color-primary-element-light); }

.visibility-badge--intern { background: var(--color-background-dark); }

.kennisbank-home__empty {
	padding: 40px 0;
}
</style>
<style scoped>
.kennisbank-home { padding: 20px }

.kennisbank-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px }

.kennisbank-search { position: relative; margin-bottom: 16px }

.autocomplete-dropdown { position: absolute; top: 100%; left: 0; right: 0; background: var(--color-main-background); border: 1px solid var(--color-border); border-radius: var(--border-radius); box-shadow: 0 2px 8px rgba(0,0,0,0.1); z-index: 100 }

.autocomplete-item { display: flex; justify-content: space-between; padding: 8px 12px; cursor: pointer }

.autocomplete-item:hover { background: var(--color-background-hover) }

.autocomplete-category { color: var(--color-text-maxcontrast); font-size: 12px }

.kennisbank-content { display: flex; gap: 20px }

.kennisbank-sidebar { width: 250px; flex-shrink: 0 }

.kennisbank-main { flex: 1; min-width: 0 }

.article-list { display: flex; flex-direction: column; gap: 8px }
</style>
