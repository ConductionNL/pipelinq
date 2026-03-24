<template>
	<div class="kennisbank-home">
		<div class="kennisbank-header">
			<h2>{{ t('pipelinq', 'Knowledge Base') }}</h2>
			<NcButton type="primary" @click="$router.push({ name: 'KennisbankNew' })">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('pipelinq', 'New Article') }}
			</NcButton>
		</div>

		<div class="kennisbank-search">
			<NcTextField
				ref="searchInput"
				:value.sync="searchQuery"
				:label="t('pipelinq', 'Search articles...')"
				:show-trailing-button="searchQuery.length > 0"
				trailing-button-icon="close"
				@trailing-button-click="clearSearch"
				@update:value="onSearchInput" />
			<div v-if="autocompleteResults.length > 0" class="autocomplete-dropdown">
				<div
					v-for="item in autocompleteResults"
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
				<CategoryTree
					:categories="categoryTree"
					:article-counts="articleCountsByCategory"
					:selected-category="selectedCategory"
					@select="onCategorySelect" />
			</div>

			<div class="kennisbank-main">
				<div v-if="searchQuery && searchQuery.length >= 2">
					<NcLoadingIcon v-if="searchLoading" :size="32" />
					<NcEmptyContent
						v-else-if="searchResults.length === 0"
						:name="t('pipelinq', 'No results found')"
						:description="t('pipelinq', 'Try different search terms or browse categories')" />
					<div v-else class="article-list">
						<ArticleListItem
							v-for="article in searchResults"
							:key="article.id"
							:article="article"
							:category-name="getCategoryName(article.category)"
							:search-query="searchQuery"
							@click="goToArticle(article.id)" />
					</div>
				</div>

				<div v-else>
					<div v-if="recentlyViewed.length > 0 && !selectedCategory" class="recent-section">
						<h3 class="section-title">{{ t('pipelinq', 'Recently viewed') }}</h3>
						<div
							v-for="item in recentlyViewed"
							:key="item.id"
							class="recent-item"
							@click="goToArticle(item.id)">
							{{ item.title }}
						</div>
					</div>

					<NcLoadingIcon v-if="loading" :size="32" />
					<NcEmptyContent
						v-else-if="visibleArticles.length === 0"
						:name="t('pipelinq', 'No articles yet')"
						:description="t('pipelinq', 'Create your first knowledge base article')">
						<template #action>
							<NcButton type="primary" @click="$router.push({ name: 'KennisbankNew' })">
								{{ t('pipelinq', 'New article') }}
							</NcButton>
						</template>
					</NcEmptyContent>
					<div v-else class="article-list">
						<ArticleListItem
							v-for="article in visibleArticles"
							:key="article.id"
							:article="article"
							:category-name="getCategoryName(article.category)"
							@click="goToArticle(article.id)" />
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { useKennisbankStore } from '../../store/modules/kennisbank.js'
import CategoryTree from '../../components/kennisbank/CategoryTree.vue'
import ArticleListItem from '../../components/kennisbank/ArticleListItem.vue'

let debounce = null

export default {
	name: 'KennisbankHome',
	components: {
		NcButton,
		NcTextField,
		NcLoadingIcon,
		NcEmptyContent,
		Plus,
		CategoryTree,
		ArticleListItem,
	},
	data() {
		return {
			searchQuery: '',
		}
	},
	computed: {
		store() {
			return useKennisbankStore()
		},
		visibleArticles() {
			return this.store.visibleArticles
		},
		searchResults() {
			return this.store.searchResults
		},
		autocompleteResults() {
			return this.store.autocompleteResults
		},
		searchLoading() {
			return this.store.searchLoading
		},
		loading() {
			return this.store.loading
		},
		categoryTree() {
			return this.store.categoryTree
		},
		articleCountsByCategory() {
			return this.store.articleCountsByCategory
		},
		selectedCategory() {
			return this.store.selectedCategory
		},
		recentlyViewed() {
			return this.store.recentlyViewed
		},
	},
	mounted() {
		this.store.fetchCategories()
		this.store.fetchArticles()
		this.$nextTick(() => {
			const el = this.$refs.searchInput?.$el?.querySelector('input')
			if (el) {
				el.focus()
			}
		})
	},
	methods: {
		getCategoryName(id) {
			return this.store.getCategoryName(id)
		},
		onSearchInput(value) {
			this.searchQuery = value
			clearTimeout(debounce)
			debounce = setTimeout(() => {
				if (value.length >= 3) {
					this.store.autocompleteArticles(value)
				} else {
					this.store.autocompleteResults = []
				}
				if (value.length >= 2) {
					this.store.searchArticles(value)
				}
			}, 300)
		},
		clearSearch() {
			this.searchQuery = ''
			this.store.searchResults = []
			this.store.autocompleteResults = []
		},
		onCategorySelect(id) {
			this.store.selectedCategory = id === this.store.selectedCategory ? null : id
			this.store.fetchArticles()
		},
		goToArticle(id) {
			this.store.autocompleteResults = []
			this.$router.push({ name: 'KennisbankDetail', params: { id } })
		},
	},
}
</script>

<style scoped>
.kennisbank-home {
	padding: 20px;
}

.kennisbank-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.kennisbank-search {
	position: relative;
	margin-bottom: 16px;
	max-width: 600px;
}

.autocomplete-dropdown {
	position: absolute;
	top: 100%;
	left: 0;
	right: 0;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
	z-index: 100;
}

.autocomplete-item {
	display: flex;
	justify-content: space-between;
	padding: 8px 12px;
	cursor: pointer;
}

.autocomplete-item:hover {
	background: var(--color-background-hover);
}

.autocomplete-category {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.kennisbank-content {
	display: flex;
	gap: 20px;
}

.kennisbank-sidebar {
	width: 250px;
	flex-shrink: 0;
}

.kennisbank-main {
	flex: 1;
	min-width: 0;
}

.article-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.recent-section {
	margin-bottom: 24px;
}

.section-title {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}

.recent-item {
	padding: 8px 12px;
	cursor: pointer;
	border-radius: var(--border-radius);
}

.recent-item:hover {
	background: var(--color-background-hover);
}
</style>
