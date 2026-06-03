<template>
	<div class="category-tree">
		<h3 class="category-tree__title">{{ t('pipelinq', 'Categories') }}</h3>
		<div
			class="category-tree__item category-tree__item--all"
			:class="{ 'category-tree__item--active': !selectedCategory }"
			tabindex="0"
			role="button"
			@click="$emit('select', null)"
			@keydown.enter="$emit('select', null)">
			{{ t('pipelinq', 'All articles') }}
		</div>
		<CategoryTreeNode
			v-for="category in categories"
			:key="category.id"
			:category="category"
			:article-counts="articleCounts"
			:selected-category="selectedCategory"
			:depth="0"
			@select="$emit('select', $event)" />
		<p v-if="categories.length === 0" class="category-tree__empty">
			{{ t('pipelinq', 'No categories yet') }}
		</p>
	</div>
</template>

<script>
import CategoryTreeNode from './CategoryTreeNode.vue'

export default {
	name: 'CategoryTree',
	components: {
		CategoryTreeNode,
	},
	props: {
		categories: {
			type: Array,
			default: () => [],
		},
		articleCounts: {
			type: Object,
			default: () => ({}),
		},
		selectedCategory: {
			type: String,
			default: null,
		},
	},
}
</script>

<style scoped>
.category-tree__title {
	font-size: 14px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}

.category-tree__item {
	padding: 8px 12px;
	cursor: pointer;
	border-radius: var(--border-radius);
	font-size: 14px;
}

.category-tree__item:hover {
	background: var(--color-background-hover);
}

.category-tree__item--active {
	background: var(--color-primary-element-light);
	font-weight: 600;
}

.category-tree__empty {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	padding: 8px 12px;
}
</style>
