<template>
	<div class="product-form">
		<div class="form-row">
			<div class="form-group">
				<label for="product-name">{{ t('pipelinq', 'Name') }} *</label>
				<NcTextField
					id="product-name"
					labelOutside
					:label="t('pipelinq', 'Name')"
					:modelValue="form.name"
					:error="!!errors.name"
					:helperText="errors.name"
					:maxlength="255"
					@update:modelValue="
						(v) => {
							form.name = v
							validateField('name')
						}
					" />
			</div>
			<div class="form-group">
				<label for="product-sku">{{ t('pipelinq', 'SKU') }}</label>
				<NcTextField
					id="product-sku"
					labelOutside
					:label="t('pipelinq', 'SKU')"
					:modelValue="form.sku"
					:maxlength="100"
					@update:modelValue="(v) => (form.sku = v)" />
			</div>
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="product-type">{{ t('pipelinq', 'Type') }} *</label>
				<NcSelect
					v-model="form.type"
					inputId="product-type"
					:aria-label-combobox="t('pipelinq', 'Type')"
					:options="typeOptions"
					:placeholder="t('pipelinq', 'Select type')"
					@update:modelValue="validateField('type')" />
				<p v-if="errors.type" class="field-error">
					{{ errors.type }}
				</p>
			</div>
			<div class="form-group">
				<label for="product-status">{{ t('pipelinq', 'Status') }}</label>
				<NcSelect
					v-model="form.status"
					inputId="product-status"
					:aria-label-combobox="t('pipelinq', 'Status')"
					:options="statusOptions"
					:placeholder="t('pipelinq', 'Select status')" />
			</div>
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="product-unitPrice"
					>{{ t('pipelinq', 'Unit Price') }} *</label
				>
				<NcTextField
					id="product-unitPrice"
					labelOutside
					:label="t('pipelinq', 'Unit Price')"
					:modelValue="form.unitPrice"
					:error="!!errors.unitPrice"
					:helperText="errors.unitPrice"
					type="number"
					@update:modelValue="
						(v) => {
							form.unitPrice = v
							validateField('unitPrice')
						}
					" />
			</div>
			<div class="form-group">
				<label for="product-cost">{{ t('pipelinq', 'Cost') }}</label>
				<NcTextField
					id="product-cost"
					labelOutside
					:label="t('pipelinq', 'Cost')"
					:modelValue="form.cost"
					type="number"
					@update:modelValue="(v) => (form.cost = v)" />
			</div>
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="product-unit">{{ t('pipelinq', 'Unit') }}</label>
				<NcTextField
					id="product-unit"
					labelOutside
					:label="t('pipelinq', 'Unit')"
					:modelValue="form.unit"
					:placeholder="t('pipelinq', 'e.g. piece, hour, license')"
					@update:modelValue="(v) => (form.unit = v)" />
			</div>
			<div class="form-group">
				<label for="product-taxRate">{{
					t('pipelinq', 'Tax Rate (%)')
				}}</label>
				<NcTextField
					id="product-taxRate"
					labelOutside
					:label="t('pipelinq', 'Tax Rate (%)')"
					:modelValue="form.taxRate"
					:disabled="!!form.vatClass"
					:helperText="
						form.vatClass
							? t('pipelinq', 'Derived from the selected BTW class')
							: ''
					"
					type="number"
					@update:modelValue="(v) => (form.taxRate = v)" />
			</div>
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="product-btwClass">{{
					t('pipelinq', 'BTW Class')
				}}</label>
				<NcSelect
					v-model="form.vatClass"
					inputId="product-btwClass"
					:inputLabel="t('pipelinq', 'BTW Class')"
					:aria-label-combobox="t('pipelinq', 'BTW Class')"
					:options="btwClassOptions"
					:placeholder="t('pipelinq', 'Select BTW class')"
					label="label"
					:reduce="(opt) => opt.id"
					@update:modelValue="onBtwClassChange" />
			</div>
			<div class="form-group">
				<label for="product-barcode">{{
					t('pipelinq', 'Barcode (EAN/UPC)')
				}}</label>
				<NcTextField
					id="product-barcode"
					labelOutside
					:label="t('pipelinq', 'Barcode (EAN/UPC)')"
					:modelValue="form.barcode"
					:maxlength="64"
					@update:modelValue="(v) => (form.barcode = v)" />
			</div>
		</div>

		<div v-if="form.type === 'service'" class="form-group">
			<label for="product-duration">{{
				t('pipelinq', 'Duration (minutes)')
			}}</label>
			<NcTextField
				id="product-duration"
				labelOutside
				:label="t('pipelinq', 'Duration (minutes)')"
				:modelValue="form.duration"
				type="number"
				@update:modelValue="(v) => (form.duration = v)" />
		</div>

		<div class="form-group">
			<label for="product-category">{{ t('pipelinq', 'Category') }}</label>
			<NcSelect
				v-model="form.category"
				inputId="product-category"
				:aria-label-combobox="t('pipelinq', 'Category')"
				:options="categoryOptions"
				:placeholder="t('pipelinq', 'Select category')"
				label="name"
				:reduce="(opt) => opt.id" />
		</div>

		<div class="form-group">
			<label for="product-description">{{
				t('pipelinq', 'Description')
			}}</label>
			<textarea id="product-description" v-model="form.description" rows="3" />
		</div>

		<div class="product-form__actions">
			<NcButton variant="primary" :disabled="!isValid" @click="onSave">
				{{ t('pipelinq', 'Save') }}
			</NcButton>
			<NcButton @click="$emit('cancel')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
/*
 * @visual exclude unmounted orphan — no e2e can reach it. This file is imported by nothing in src/ (measured: zero references outside itself, against ExportJobsView's three as the positive control), it is named by no manifest component, and it is absent from src/registry.js. The /products/:id page is a declarative type:"detail" page drawn by the manifest renderer, and the ProductDetail.vue that tests/e2e/workflows/product-crud.spec.ts pairs it with does not exist on disk. An unmounted component has no surface to screenshot. openspec/specs/product-catalog/spec.md:592 still describes this file as shipped UI, so deleting it is a product decision rather than a gate fix.
 */
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
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

	emits: ['cancel', 'save'],

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
				vatClass: null,
				barcode: '',
				duration: '',
			},

			errors: {
				name: '',
				type: '',
				unitPrice: '',
			},

			typeOptions: ['product', 'service'],
			statusOptions: ['active', 'inactive'],
			btwClassOptions: [
				{ id: 'high', label: t('pipelinq', 'High (21%)') },
				{ id: 'low', label: t('pipelinq', 'Low (9%)') },
				{ id: 'zero', label: t('pipelinq', 'Zero (0%)') },
				{ id: 'exempt', label: t('pipelinq', 'Exempt') },
			],

			btwRateMap: { hoog: 21, laag: 9, nul: 0, vrijgesteld: 0 },
			categories: [],
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-products-ui/tasks.md#task-18
		 */
		objectStore() {
			return useObjectStore()
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-products-ui/tasks.md#task-17
		 */
		isValid() {
			const hasName = this.form.name.trim().length > 0
			const hasType = !!this.form.type
			const hasPrice =
				this.form.unitPrice !== '' && Number(this.form.unitPrice) >= 0
			const noErrors = Object.values(this.errors).every((e) => !e)
			return hasName && hasType && hasPrice && noErrors
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-products-ui/tasks.md#task-14
		 */
		categoryOptions() {
			return this.categories.map((c) => ({ id: c.id, name: c.name }))
		},
	},

	watch: {
		product: {
			immediate: true,
			/**
			 * @param val
			 * @spec openspec/changes/reverse-2026-05-26-fe-products-ui/tasks.md#task-16
			 */
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
		/**
		 * @param data
		 * @spec openspec/changes/reverse-2026-05-26-fe-products-ui/tasks.md#task-20
		 */
		populateForm(data) {
			this.form = {
				name: data.name || '',
				description: data.description || '',
				sku: data.sku || '',
				unitPrice:
					data.unitPrice !== undefined ? String(data.unitPrice) : '',

				cost: data.cost !== undefined ? String(data.cost) : '',
				category: data.category || null,
				type: data.type || null,
				status: data.status || 'active',
				unit: data.unit || '',
				taxRate: data.taxRate !== undefined ? String(data.taxRate) : '21',
				vatClass: data.vatClass || null,
				barcode: data.barcode || '',
				duration:
					data.duration !== undefined && data.duration !== null
						? String(data.duration)
						: '',
			}
			this.errors = { name: '', type: '', unitPrice: '' }
		},

		/**
		 * Sync taxRate from the selected BTW class (server re-derives on lookup).
		 * @spec openspec/changes/reverse-2026-05-26-fe-products-ui/tasks.md#task-18
		 */
		onBtwClassChange() {
			if (
				this.form.vatClass
				&& this.btwRateMap[this.form.vatClass] !== undefined
			) {
				this.form.taxRate = String(this.btwRateMap[this.form.vatClass])
			}
		},

		/**
		 * @param field
		 * @spec openspec/changes/reverse-2026-05-26-fe-products-ui/tasks.md#task-22
		 */
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
					if (
						this.form.unitPrice === ''
						|| Number(this.form.unitPrice) < 0
					) {
						this.errors.unitPrice = t(
							'pipelinq',
							'Unit price must be 0 or greater',
						)
					} else {
						this.errors.unitPrice = ''
					}
					break
			}
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-products-ui/tasks.md#task-21
		 */
		validateAll() {
			this.validateField('name')
			this.validateField('type')
			this.validateField('unitPrice')
			return this.isValid
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-products-ui/tasks.md#task-19
		 */
		onSave() {
			if (!this.validateAll()) {
				return
			}
			const data = {
				...this.form,
				unitPrice: Number(this.form.unitPrice),
				cost: this.form.cost ? Number(this.form.cost) : null,
				taxRate: this.form.taxRate ? Number(this.form.taxRate) : 21,
				vatClass: this.form.vatClass || null,
				barcode: this.form.barcode || '',
				duration:
					this.form.type === 'service' && this.form.duration !== ''
						? Number(this.form.duration)
						: null,
			}
			if (this.product?.id) {
				data.id = this.product.id
			}
			this.$emit('save', data)
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-products-ui/tasks.md#task-15
		 */
		async fetchCategories() {
			try {
				const results = await this.objectStore.fetchCollection(
					'productCategory',
					{ _limit: 100 },
				)
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
