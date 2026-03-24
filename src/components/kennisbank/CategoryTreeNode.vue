<template>
	<div class="category-node">
		<div
			class="category-node__item"
			:class="{ 'category-node__item--active': selectedCategory === category.id }"
			:style="{ paddingLeft: (depth * 16 + 12) + 'px' }"
			tabindex="0"
			role="treeitem"
			:aria-expanded="hasChildren ? String(expanded) : undefined"
			@click="onClick"
			@keydown.enter="onClick"
			@keydown.right.prevent="expand"
			@keydown.left.prevent="collapse">
			<span
				v-if="hasChildren"
				class="category-node__toggle"
				@click.stop="toggleExpand">
				{{ expanded ? '&#9660;' : '&#9654;' }}
			</span>
			<span class="category-node__name">{{ category.name }}</span>
			<span class="category-node__count">({{ articleCount }})</span>
		</div>
		<div v-if="expanded && hasChildren && depth < 2" class="category-node__children">
			<CategoryTreeNode
				v-for="child in category.children"
				:key="child.id"
				:category="child"
				:article-counts="articleCounts"
				:selected-category="selectedCategory"
				:depth="depth + 1"
				@select="$emit('select', $event)" />
		</div>
	</div>
</template>

<script>
export default {
	name: 'CategoryTreeNode',
	props: {
		category: {
			type: Object,
			required: true,
		},
		articleCounts: {
			type: Object,
			default: () => ({}),
		},
		selectedCategory: {
			type: String,
			default: null,
		},
		depth: {
			type: Number,
			default: 0,
		},
	},
	data() {
		return {
			expanded: this.depth === 0,
		}
	},
	computed: {
		hasChildren() {
			return this.category.children && this.category.children.length > 0
		},
		articleCount() {
			return this.articleCounts[this.category.id] || 0
		},
	},
	methods: {
		onClick() {
			this.$emit('select', this.category.id)
		},
		toggleExpand() {
			this.expanded = !this.expanded
		},
		expand() {
			if (this.hasChildren) {
				this.expanded = true
			}
		},
		collapse() {
			if (this.expanded) {
				this.expanded = false
			}
		},
	},
}
</script>

<style scoped>
.category-node__item {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 6px 12px;
	cursor: pointer;
	border-radius: var(--border-radius);
	font-size: 14px;
}

.category-node__item:hover {
	background: var(--color-background-hover);
}

.category-node__item--active {
	background: var(--color-primary-element-light);
	font-weight: 600;
}

.category-node__toggle {
	font-size: 10px;
	width: 16px;
	text-align: center;
	flex-shrink: 0;
	cursor: pointer;
}

.category-node__name {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.category-node__count {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	flex-shrink: 0;
}
</style>
