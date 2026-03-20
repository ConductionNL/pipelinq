<template>
	<div class="form-builder">
		<div class="builder-header">
			<h2>{{ isNew ? t('pipelinq', 'New Form') : t('pipelinq', 'Edit Form') }}</h2>
		</div>

		<NcLoadingIcon v-if="loading" />

		<div v-else class="builder-form">
			<div class="form-group">
				<label>{{ t('pipelinq', 'Form name') }} *</label>
				<input v-model="form.name" type="text" class="form-input"
					:placeholder="t('pipelinq', 'Contact form')">
			</div>

			<div class="form-group">
				<label>{{ t('pipelinq', 'Success message') }}</label>
				<input v-model="form.successMessage" type="text" class="form-input"
					:placeholder="t('pipelinq', 'Thank you for your submission.')">
			</div>

			<div class="form-group">
				<label>{{ t('pipelinq', 'Notify user') }}</label>
				<input v-model="form.notifyUser" type="text" class="form-input"
					:placeholder="t('pipelinq', 'Nextcloud username')">
			</div>

			<div class="form-group">
				<NcCheckboxRadioSwitch :checked.sync="form.isActive" type="switch">
					{{ t('pipelinq', 'Active') }}
				</NcCheckboxRadioSwitch>
			</div>

			<!-- Fields -->
			<div class="form-group">
				<label>{{ t('pipelinq', 'Form Fields') }}</label>
				<div v-for="(field, index) in form.fields" :key="index" class="field-card">
					<div class="field-row">
						<input v-model="field.name" type="text" :placeholder="t('pipelinq', 'Field name')"
							class="field-input">
						<input v-model="field.label" type="text" :placeholder="t('pipelinq', 'Label')"
							class="field-input">
						<NcSelect v-model="field.type"
							:options="fieldTypeOptions"
							label="label"
							:reduce="opt => opt.value"
							class="field-type" />
						<NcCheckboxRadioSwitch :checked.sync="field.required" type="switch">
							{{ t('pipelinq', 'Required') }}
						</NcCheckboxRadioSwitch>
						<NcButton type="tertiary" @click="removeField(index)">
							<template #icon>
								<Delete :size="20" />
							</template>
						</NcButton>
					</div>
					<div class="field-details">
						<input v-model="field.placeholder" type="text"
							:placeholder="t('pipelinq', 'Placeholder text')" class="field-input">
						<div class="field-mapping">
							<label>{{ t('pipelinq', 'Map to') }}:</label>
							<NcSelect v-model="mappingFor[index]"
								:options="mappingOptions"
								label="label"
								:reduce="opt => opt.value"
								:placeholder="t('pipelinq', 'Entity property')" />
						</div>
					</div>
					<div v-if="field.type === 'select'" class="field-options">
						<label>{{ t('pipelinq', 'Options (comma-separated)') }}</label>
						<input v-model="field.optionsText" type="text"
							:placeholder="t('pipelinq', 'Option 1, Option 2, Option 3')">
					</div>
				</div>
				<NcButton type="secondary" @click="addField">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('pipelinq', 'Add field') }}
				</NcButton>
			</div>

			<div class="form-actions">
				<NcButton type="primary" :disabled="!form.name" @click="save">
					{{ t('pipelinq', 'Save form') }}
				</NcButton>
				<NcButton type="secondary" @click="$router.push({ name: 'Forms' })">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSelect, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { useObjectStore } from '../../store/store.js'
import Plus from 'vue-material-design-icons/Plus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'FormBuilder',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcCheckboxRadioSwitch,
		Plus,
		Delete,
	},
	props: {
		formId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			loading: false,
			form: {
				name: '',
				fields: [],
				isActive: true,
				successMessage: '',
				notifyUser: '',
				fieldMappings: {},
			},
			mappingFor: [],
		}
	},
	computed: {
		isNew() {
			return !this.formId
		},
		fieldTypeOptions() {
			return [
				{ value: 'text', label: this.t('pipelinq', 'Text') },
				{ value: 'textarea', label: this.t('pipelinq', 'Textarea') },
				{ value: 'email', label: this.t('pipelinq', 'Email') },
				{ value: 'phone', label: this.t('pipelinq', 'Phone') },
				{ value: 'select', label: this.t('pipelinq', 'Dropdown') },
				{ value: 'checkbox', label: this.t('pipelinq', 'Checkbox') },
				{ value: 'file', label: this.t('pipelinq', 'File upload') },
				{ value: 'hidden', label: this.t('pipelinq', 'Hidden') },
			]
		},
		mappingOptions() {
			return [
				{ value: 'contact.name', label: this.t('pipelinq', 'Contact: Name') },
				{ value: 'contact.email', label: this.t('pipelinq', 'Contact: Email') },
				{ value: 'contact.phone', label: this.t('pipelinq', 'Contact: Phone') },
				{ value: 'lead.title', label: this.t('pipelinq', 'Lead: Title') },
				{ value: 'lead.notes', label: this.t('pipelinq', 'Lead: Notes') },
				{ value: 'lead.source', label: this.t('pipelinq', 'Lead: Source') },
			]
		},
	},
	mounted() {
		if (this.formId) {
			this.loadForm()
		}
	},
	methods: {
		async loadForm() {
			this.loading = true
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.fetchObject('intakeForm', this.formId)
				if (result) {
					this.form = { ...result }
					this.parseMappings()
				}
			} catch (e) {
				console.error('Failed to load form', e)
			} finally {
				this.loading = false
			}
		},
		parseMappings() {
			const mappings = this.form.fieldMappings || {}
			this.mappingFor = (this.form.fields || []).map((f) => {
				const m = mappings[f.name]
				return m ? m.entity + '.' + m.property : null
			})
		},
		buildMappings() {
			const result = {}
			const fields = this.form.fields || []
			for (let i = 0; i < fields.length; i++) {
				const mapping = this.mappingFor[i]
				if (mapping) {
					const [entity, property] = mapping.split('.')
					result[fields[i].name] = { entity, property }
				}
			}
			return result
		},
		addField() {
			this.form.fields.push({
				name: '',
				label: '',
				type: 'text',
				required: false,
				placeholder: '',
				options: [],
				optionsText: '',
			})
			this.mappingFor.push(null)
		},
		removeField(index) {
			this.form.fields.splice(index, 1)
			this.mappingFor.splice(index, 1)
		},
		async save() {
			this.form.fieldMappings = this.buildMappings()

			// Convert optionsText to options array for select fields
			for (const field of this.form.fields) {
				if (field.type === 'select' && field.optionsText) {
					field.options = field.optionsText.split(',').map(o => o.trim()).filter(Boolean)
				}
			}

			const objectStore = useObjectStore()
			await objectStore.saveObject('intakeForm', this.form)
			this.$router.push({ name: 'Forms' })
		},
	},
}
</script>

<style scoped>
.form-builder {
	padding: 20px;
	max-width: 900px;
}

.builder-form {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.form-group > label {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.form-input {
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.field-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	margin-bottom: 8px;
}

.field-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.field-input {
	flex: 1;
	padding: 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.field-type {
	min-width: 120px;
}

.field-details {
	display: flex;
	gap: 8px;
	margin-top: 8px;
	align-items: center;
}

.field-mapping {
	display: flex;
	align-items: center;
	gap: 4px;
	min-width: 250px;
}

.field-options {
	margin-top: 8px;
}

.field-options input {
	width: 100%;
	padding: 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.form-actions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}
</style>
