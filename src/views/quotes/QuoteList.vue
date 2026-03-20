<template>
	<CnIndexPage
		:title="t('pipelinq', 'Quotes')"
		object-type="quote"
		:columns="columns"
		:create-route="{ name: 'QuoteDetail', params: { id: 'new' } }"
		:create-label="t('pipelinq', 'New Quote')"
		:detail-route-name="'QuoteDetail'"
		:formatters="formatters" />
</template>

<script>
import { CnIndexPage } from '@conduction/nextcloud-vue'

export default {
	name: 'QuoteList',
	components: {
		CnIndexPage,
	},
	data() {
		return {
			columns: [
				{ key: 'quoteNumber', label: t('pipelinq', 'Quote Number'), sortable: true },
				{ key: 'title', label: t('pipelinq', 'Title'), sortable: true },
				{ key: 'status', label: t('pipelinq', 'Status'), sortable: true },
				{ key: 'subtotal', label: t('pipelinq', 'Subtotal'), sortable: true },
				{ key: 'total', label: t('pipelinq', 'Total'), sortable: true },
				{ key: 'expiryDate', label: t('pipelinq', 'Expiry Date'), sortable: true },
			],
			formatters: {
				subtotal: (val) => this.formatCurrency(val),
				total: (val) => this.formatCurrency(val),
			},
		}
	},
	methods: {
		formatCurrency(value) {
			if (!value && value !== 0) return '-'
			return 'EUR ' + Number(value).toLocaleString('nl-NL', { minimumFractionDigits: 2 })
		},
	},
}
</script>
