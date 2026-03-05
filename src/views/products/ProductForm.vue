<template>
	<div class="product-form">
		<div class="form-row">
			<div class="form-group">
				<label for="product-name">{{ t('pipelinq', 'Name') }} *</label>
				<NcTextField
					id="product-name"
					:value="form.name"
					:error="!!errors.name"
					:helper-text="errors.name"
					:maxlength="255"
					@update:value="v => { form.name = v; validateField('name') }" />
			</div>
			<div class="form-group">
				<label for="product-sku">{{ t('pipelinq', 'SKU') }}</label>
				<NcTextField
					id="product-sku"
					:value="form.sku"
					:maxlength="100"
					@update:value="v => form.sku = v" />
			</div>
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="product-type">{{ t('pipelinq', 'Type') }} *</label>
				<NcSelect
					v-model="form.type"
					input-id="product-type"
					:options="typeOptions"
					:placeholder="t('pipelinq', 'Select type')"
					@input="validateField('type')" />
				<p v-if="errors.type" class="field-error">
					{{ errors.type }}
				</p>
			</div>
			<div class="form-group">
				<label for="product-status">{{ t('pipelinq', 'Status') }}</label>
				<NcSelect
					v-model="form.status"
					input-id="product-status"
					:options="statusOptions"
					:placeholder="t('pipelinq', 'Select status')" />
			</div>
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="product-unitPrice">{{ t('pipelinq', 'Unit Price') }} *</label>
				<NcTextField
					id="product-unitPrice"
					:value="form.unitPrice"
					:error="!!errors.unitPrice"
					:helper-text="errors.unitPrice"
					type="number"
					@update:value="v => { form.unitPrice = v; validateField('unitPrice') }" />
			</div>
			<div class="form-group">
				<label for="product-cost">{{ t('pipelinq', 'Cost') }}</label>
				<NcTextField
					id="product-cost"
					:value="form.cost"
					type="number"
					@update:value="v => form.cost = v" />
			</div>
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="product-unit">{{ t('pipelinq', 'Unit') }}</label>
				<NcTextField
					id="product-unit"
					:value="form.unit"
					:placeholder="t('pipelinq', 'e.g. piece, hour, license')"
					@update:value="v => form.unit = v" />
			</div>
			<div class="form-group">
				<label for="product-taxRate">{{ t('pipelinq', 'Tax Rate (%)') }}</label>
				<NcTextField
					id="product-taxRate"
					:value="form.taxRate"
					type="number"
					@update:value="v => form.taxRate = v" />
			</div>
		</div>

		<div class="form-group">
			<label for="product-category">{{ t('pipelinq', 'Category') }}</label>
			<NcSelect
				v-model="form.category"
				input-id="product-category"
				:options="categoryOptions"
				:placeholder="t('pipelinq', 'Select category')"
				label="name"
				:reduce="opt => opt.id" />
		</div>

		<div class="form-group">
			<label for="product-description">{{ t('pipelinq', 'Description') }}</label>
			<textarea id="product-description" v-model="form.description" rows="3" />
		</div>

		<div class="product-form__actions">
			<NcButton type="primary" :disabled="!isValid" @click="onSave">
				{{ t('pipelinq', 'Save') }}
			</NcButton>
			<NcButton @click="$emit('cancel')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcSelect } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ProductForm',
	components: {
		NcButton,
		NcTextField,
		NcSelect,
	},
	props: {
		product: {
			type: Object,
			default: () => ({}),
		},
	},
	data() {
		return {
			form: {
				name: '',
				description: '',
				sku: '',
				unitPrice: '',
				cost: '',
				category: null,
				type: null,
				status: 'active',
				unit: '',
				taxRate: '21',
			},
			errors: {
				name: '',
				type: '',
				unitPrice: '',
			},
			typeOptions: ['product', 'service'],
			statusOptions: ['active', 'inactive'],
			categories: [],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isValid() {
			const hasName = this.form.name.trim().length > 0
			const hasType = !!this.form.type
			const hasPrice = this.form.unitPrice !== '' && Number(this.form.unitPrice) >= 0
			const noErrors = Object.values(this.errors).every(e => !e)
			return hasName && hasType && hasPrice && noErrors
		},
		categoryOptions() {
			return this.categories.map(c => ({ id: c.id, name: c.name }))
		},
	},
	watch: {
		product: {
			immediate: true,
			handler(val) {
				if (val && Object.keys(val).length > 0) {
					this.populateForm(val)
				}
			},
		},
	},
	async mounted() {
		await this.fetchCategories()
	},
	methods: {
		populateForm(data) {
			this.form = {
				name: data.name || '',
				description: data.description || '',
				sku: data.sku || '',
				unitPrice: data.unitPrice !== undefined ? String(data.unitPrice) : '',
				cost: data.cost !== undefined ? String(data.cost) : '',
				category: data.category || null,
				type: data.type || null,
				status: data.status || 'active',
				unit: data.unit || '',
				taxRate: data.taxRate !== undefined ? String(data.taxRate) : '21',
			}
			this.errors = { name: '', type: '', unitPrice: '' }
		},
		validateField(field) {
			switch (field) {
			case 'name':
				if (!this.form.name.trim()) {
					this.errors.name = t('pipelinq', 'Name is required')
				} else {
					this.errors.name = ''
				}
				break
			case 'type':
				if (!this.form.type) {
					this.errors.type = t('pipelinq', 'Type is required')
				} else {
					this.errors.type = ''
				}
				break
			case 'unitPrice':
				if (this.form.unitPrice === '' || Number(this.form.unitPrice) < 0) {
					this.errors.unitPrice = t('pipelinq', 'Unit price must be 0 or greater')
				} else {
					this.errors.unitPrice = ''
				}
				break
			}
		},
		validateAll() {
			this.validateField('name')
			this.validateField('type')
			this.validateField('unitPrice')
			return this.isValid
		},
		onSave() {
			if (!this.validateAll()) {
				return
			}
			const data = {
				...this.form,
				unitPrice: Number(this.form.unitPrice),
				cost: this.form.cost ? Number(this.form.cost) : null,
				taxRate: this.form.taxRate ? Number(this.form.taxRate) : 21,
			}
			if (this.product?.id) {
				data.id = this.product.id
			}
			this.$emit('save', data)
		},
		async fetchCategories() {
			try {
				const results = await this.objectStore.fetchCollection('productCategory', { _limit: 100 })
				this.categories = results || []
			} catch {
				this.categories = []
			}
		},
	},
}
</script>

<style scoped>
.product-form {
	max-width: 800px;
}

.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}

.form-group textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
}

.form-row {
	display: flex;
	gap: 16px;
}

.form-row .form-group {
	flex: 1;
}

.field-error {
	color: var(--color-error);
	font-size: 12px;
	margin-top: 4px;
}

.product-form__actions {
	display: flex;
	gap: 12px;
	margin-top: 20px;
}
</style>
