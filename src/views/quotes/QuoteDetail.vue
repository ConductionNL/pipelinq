<template>
	<CnDetailPage
		:title="quoteData.title || t('pipelinq', 'Quote')"
		:subtitle="quoteData.quoteNumber || t('pipelinq', 'New Quote')"
		:back-route="{ name: 'Quotes' }"
		:back-label="t('pipelinq', 'Back to list')"
		:loading="loading"
		:sidebar="!isNew && !loading"
		object-type="pipelinq_quote"
		:object-id="quoteId"
		:sidebar-props="sidebarProps">
		<template #header-actions>
			<template v-if="!isNew">
				<NcButton v-if="!editing" type="primary" @click="editing = true">
					{{ t('pipelinq', 'Edit') }}
				</NcButton>
				<NcButton type="error" @click="confirmDelete">
					{{ t('pipelinq', 'Delete') }}
				</NcButton>
			</template>
		</template>

		<!-- New/Edit mode -->
		<CnDetailCard v-if="isNew || editing" :title="t('pipelinq', 'Quote Details')">
			<div class="form-grid">
				<div class="form-group">
					<label>{{ t('pipelinq', 'Title') }} *</label>
					<NcTextField
						:value="formData.title || ''"
						@update:value="v => formData.title = v" />
				</div>
				<div class="form-group">
					<label>{{ t('pipelinq', 'Status') }}</label>
					<NcSelect
						v-model="formData.status"
						:options="statusOptions"
						:reduce="opt => opt.id" />
				</div>
				<div class="form-group">
					<label>{{ t('pipelinq', 'Tax Rate (%)') }}</label>
					<NcTextField
						:value="String(formData.taxRate || 21)"
						type="number"
						@update:value="v => formData.taxRate = Number(v)" />
				</div>
				<div class="form-group">
					<label>{{ t('pipelinq', 'Expiry Date') }}</label>
					<NcTextField
						:value="formData.expiryDate || ''"
						type="date"
						@update:value="v => formData.expiryDate = v" />
				</div>
			</div>
			<div class="form-group">
				<label>{{ t('pipelinq', 'Payment Terms') }}</label>
				<textarea
					v-model="formData.paymentTerms"
					rows="3"
					class="form-textarea" />
			</div>
			<div class="form-group">
				<label>{{ t('pipelinq', 'Internal Notes') }}</label>
				<textarea
					v-model="formData.notes"
					rows="2"
					class="form-textarea" />
			</div>
			<div class="form-actions">
				<NcButton type="primary" :disabled="!formData.title" @click="saveQuote">
					{{ t('pipelinq', 'Save') }}
				</NcButton>
				<NcButton @click="cancelEdit">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
			</div>
		</CnDetailCard>

		<!-- View mode -->
		<template v-if="!isNew && !editing">
			<CnDetailCard :title="t('pipelinq', 'Quote Information')">
				<div class="info-grid">
					<div class="info-field">
						<label>{{ t('pipelinq', 'Quote Number') }}</label>
						<span>{{ quoteData.quoteNumber || '-' }}</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Status') }}</label>
						<span class="status-badge" :class="'status--' + (quoteData.status || 'concept')">
							{{ quoteData.status || 'concept' }}
						</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Version') }}</label>
						<span>{{ quoteData.version || 1 }}</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Tax Rate') }}</label>
						<span>{{ quoteData.taxRate || 21 }}%</span>
					</div>
					<div v-if="quoteData.expiryDate" class="info-field">
						<label>{{ t('pipelinq', 'Expiry Date') }}</label>
						<span>{{ quoteData.expiryDate }}</span>
					</div>
					<div v-if="quoteData.sentDate" class="info-field">
						<label>{{ t('pipelinq', 'Sent Date') }}</label>
						<span>{{ quoteData.sentDate }}</span>
					</div>
				</div>
				<div v-if="quoteData.paymentTerms" class="info-field info-field--full">
					<label>{{ t('pipelinq', 'Payment Terms') }}</label>
					<p>{{ quoteData.paymentTerms }}</p>
				</div>
			</CnDetailCard>

			<CnDetailCard :title="t('pipelinq', 'Line Items & Totals')">
				<QuoteLineItems
					:quote-id="quoteId"
					:tax-rate="quoteData.taxRate || 21"
					:editable="quoteData.status === 'concept'"
					@totals-changed="onTotalsChanged" />
			</CnDetailCard>
		</template>
	</CnDetailPage>
</template>

<script>
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'
import QuoteLineItems from '../../components/QuoteLineItems.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'QuoteDetail',
	components: {
		NcButton,
		NcSelect,
		NcTextField,
		CnDetailPage,
		CnDetailCard,
		QuoteLineItems,
	},
	props: {
		quoteId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			editing: false,
			formData: {},
			statusOptions: [
				{ id: 'concept', label: t('pipelinq', 'Concept') },
				{ id: 'verzonden', label: t('pipelinq', 'Sent') },
				{ id: 'geaccepteerd', label: t('pipelinq', 'Accepted') },
				{ id: 'afgewezen', label: t('pipelinq', 'Declined') },
				{ id: 'verlopen', label: t('pipelinq', 'Expired') },
			],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isNew() {
			return !this.quoteId || this.quoteId === 'new'
		},
		loading() {
			return this.objectStore.loading.quote || false
		},
		quoteData() {
			if (this.isNew) return {}
			return this.objectStore.getObject('quote', this.quoteId) || {}
		},
		sidebarProps() {
			const config = this.objectStore.objectTypeRegistry.quote || {}
			return {
				register: config.register || '',
				schema: config.schema || '',
				hiddenTabs: ['tasks'],
			}
		},
	},
	async mounted() {
		if (this.isNew) {
			this.formData = {
				title: '',
				status: 'concept',
				taxRate: 21,
				version: 1,
				paymentTerms: '',
				notes: '',
				expiryDate: '',
			}
			this.editing = true
		} else {
			await this.objectStore.fetchObject('quote', this.quoteId)
			this.formData = { ...this.quoteData }
		}
	},
	methods: {
		async saveQuote() {
			if (!this.formData.title) return

			const result = await this.objectStore.saveObject('quote', this.formData)
			if (result) {
				if (this.isNew) {
					this.$router.push({ name: 'QuoteDetail', params: { id: result.id } })
				} else {
					await this.objectStore.fetchObject('quote', this.quoteId)
					this.editing = false
				}
			} else {
				const error = this.objectStore.getError('quote')
				showError(error?.message || t('pipelinq', 'Failed to save quote.'))
			}
		},
		cancelEdit() {
			if (this.isNew) {
				this.$router.push({ name: 'Quotes' })
			} else {
				this.formData = { ...this.quoteData }
				this.editing = false
			}
		},
		async confirmDelete() {
			if (!confirm(t('pipelinq', 'Are you sure you want to delete this quote?'))) return

			const success = await this.objectStore.deleteObject('quote', this.quoteId)
			if (success) {
				this.$router.push({ name: 'Quotes' })
			} else {
				showError(t('pipelinq', 'Failed to delete quote.'))
			}
		},
		async onTotalsChanged(totals) {
			if (!this.isNew && totals) {
				await this.objectStore.saveObject('quote', {
					id: this.quoteId,
					subtotal: totals.subtotal,
					taxAmount: totals.taxAmount,
					total: totals.total,
				})
			}
		},
	},
}
</script>

<style scoped>
.info-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.info-field {
	margin-bottom: 8px;
}

.info-field label {
	display: block;
	font-weight: bold;
	margin-bottom: 2px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.info-field span,
.info-field p {
	margin: 0;
}

.info-field--full {
	margin-top: 16px;
}

.form-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
	margin-bottom: 16px;
}

.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
	font-size: 13px;
}

.form-textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
	font-family: inherit;
}

.form-actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
}

.status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
}

.status--concept {
	background: #e0f2fe;
	color: #0369a1;
	border: 1px solid #7dd3fc;
}

.status--verzonden {
	background: #fef3c7;
	color: #92400e;
	border: 1px solid #fbbf24;
}

.status--geaccepteerd {
	background: #dcfce7;
	color: #166534;
	border: 1px solid #86efac;
}

.status--afgewezen {
	background: #fecaca;
	color: #991b1b;
	border: 1px solid #fca5a5;
}

.status--verlopen {
	background: #f3f4f6;
	color: #6b7280;
	border: 1px solid #d1d5db;
}
</style>
