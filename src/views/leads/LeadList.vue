<template>
	<CnIndexPage
		:title="t('pipelinq', 'Leads')"
		:description="t('pipelinq', 'Track and manage sales leads')"
		:schema="schema"
		:objects="objects"
		:pagination="pagination"
		:loading="loading"
		:sort-key="sortKey"
		:sort-order="sortOrder"
		:selectable="true"
		:include-columns="visibleColumns"
		@add="createNew"
		@refresh="refresh"
		@sort="onSort"
		@row-click="openLead"
		@page-changed="onPageChange">
		<template #column-value="{ value }">
			{{ formatValue(value) }}
		</template>

		<template #column-priority="{ value }">
			<span v-if="value && value !== 'normal'" class="priority-badge" :class="'priority-' + value">
				{{ value }}
			</span>
			<span v-else>{{ value || '-' }}</span>
		</template>
	</CnIndexPage>
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'

export default {
	name: 'LeadList',
	components: {
		CnIndexPage,
	},

	setup() {
		const sidebarState = inject('sidebarState', null)
		return useListView('lead', { sidebarState })
	},

	methods: {
		openLead(row) {
			this.$router.push({ name: 'LeadDetail', params: { id: row.id } })
		},
		createNew() {
			this.$router.push({ name: 'LeadDetail', params: { id: 'new' } })
		},
		formatValue(value) {
			if (value === null || value === undefined) return '-'
			return 'EUR ' + Number(value).toLocaleString('nl-NL')
		},
	},
}
</script>

<style scoped>
.priority-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: bold;
	text-transform: capitalize;
}

.priority-urgent {
	background: var(--color-error);
	color: white;
}

.priority-high {
	background: var(--color-warning);
	color: var(--color-warning-text);
}

.priority-low {
	color: var(--color-text-maxcontrast);
}
</style>
