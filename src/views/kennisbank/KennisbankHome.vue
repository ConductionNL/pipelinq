<template>
	<div class="kennisbank-home">
		<div class="kennisbank-header">
			<h2>{{ t('pipelinq', 'Kennisbank') }}</h2>
			<NcButton type="primary" @click="$router.push({ name: 'KennisbankNew' })">
				<template #icon><Plus :size="20" /></template>
				{{ t('pipelinq', 'New Article') }}
			</NcButton>
		</div>
		<div class="kennisbank-search">
			<NcTextField ref="searchInput" :value.sync="searchQuery" :label="t('pipelinq', 'Search articles...')" :show-trailing-button="searchQuery.length > 0" trailing-button-icon="close" @trailing-button-click="clearSearch" @update:value="onSearchInput" />
			<div v-if="autocompleteResults.length > 0" class="autocomplete-dropdown">
				<div v-for="item in autocompleteResults" :key="item.id" class="autocomplete-item" @click="goToArticle(item.id)">
					<span class="autocomplete-title">{{ item.title }}</span>
					<span class="autocomplete-category">{{ getCategoryName(item.category) }}</span>
				</div>
			</div>
		</div>
		<div class="kennisbank-content">
			<div class="kennisbank-sidebar">
				<CategoryTree :categories="categoryTree" :article-counts="articleCountsByCategory" :selected-category="selectedCategory" @select="onCategorySelect" />
			</div>
			<div class="kennisbank-main">
				<div v-if="searchQuery && searchQuery.length >= 2">
					<div v-if="searchLoading" style="text-align:center;padding:40px"><NcLoadingIcon :size="32" /></div>
					<div v-else-if="searchResults.length === 0" style="text-align:center;padding:40px;color:var(--color-text-maxcontrast)">{{ t('pipelinq', 'No results found') }}</div>
					<div v-else class="article-list">
						<ArticleListItem v-for="a in searchResults" :key="a.id" :article="a" :category-name="getCategoryName(a.category)" :search-query="searchQuery" @click="goToArticle(a.id)" />
					</div>
				</div>
				<div v-else>
					<div v-if="recentlyViewed.length > 0 && !selectedCategory" style="margin-bottom:24px">
						<h3 style="font-size:14px;color:var(--color-text-maxcontrast)">{{ t('pipelinq', 'Recently viewed') }}</h3>
						<div v-for="item in recentlyViewed" :key="item.id" style="padding:8px 12px;cursor:pointer;border-radius:var(--border-radius)" @click="goToArticle(item.id)">{{ item.title }}</div>
					</div>
					<div v-if="loading" style="text-align:center;padding:40px"><NcLoadingIcon :size="32" /></div>
					<div v-else-if="visibleArticles.length === 0" style="text-align:center;padding:40px;color:var(--color-text-maxcontrast)">{{ t('pipelinq', 'No articles published yet') }}</div>
					<div v-else class="article-list">
						<ArticleListItem v-for="a in visibleArticles" :key="a.id" :article="a" :category-name="getCategoryName(a.category)" @click="goToArticle(a.id)" />
					</div>
				</div>
			</div>
		</div>
	</div>
</template>
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
