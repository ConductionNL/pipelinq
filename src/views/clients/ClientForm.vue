<template>
	<div class="client-form">
		<div class="form-group">
			<label for="client-name">{{ t('pipelinq', 'Name') }} *</label>
			<NcTextField
				id="client-name"
				:value="form.name"
				:error="!!errors.name"
				:helper-text="errors.name"
				:maxlength="255"
				@update:value="v => { form.name = v; validateField('name') }" />
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="client-type">{{ t('pipelinq', 'Type') }} *</label>
				<NcSelect
					input-id="client-type"
					v-model="form.type"
					:options="typeOptions"
					:placeholder="t('pipelinq', 'Select type')"
					@input="validateField('type')" />
				<p v-if="errors.type" class="field-error">{{ errors.type }}</p>
			</div>
			<div class="form-group">
				<label for="client-email">{{ t('pipelinq', 'Email') }}</label>
				<NcTextField
					id="client-email"
					:value="form.email"
					:error="!!errors.email"
					:helper-text="errors.email"
					type="email"
					@update:value="v => { form.email = v; validateField('email') }" />
			</div>
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="client-phone">{{ t('pipelinq', 'Phone') }}</label>
				<NcTextField
					id="client-phone"
					:value="form.phone"
					:error="!!errors.phone"
					:helper-text="errors.phone"
					@update:value="v => { form.phone = v; validateField('phone') }" />
			</div>
			<div class="form-group">
				<label for="client-website">{{ t('pipelinq', 'Website') }}</label>
				<NcTextField
					id="client-website"
					:value="form.website"
					:error="!!errors.website"
					:helper-text="errors.website"
					@update:value="v => { form.website = v; validateField('website') }" />
			</div>
		</div>

		<div class="form-group">
			<label for="client-address">{{ t('pipelinq', 'Address') }}</label>
			<NcTextField
				id="client-address"
				:value="form.address"
				@update:value="v => form.address = v" />
		</div>

		<div class="form-group">
			<label for="client-notes">{{ t('pipelinq', 'Notes') }}</label>
			<textarea id="client-notes" v-model="form.notes" rows="3" />
		</div>

		<div class="client-form__actions">
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

const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const PHONE_REGEX = /^[+]?[\d\s\-().]{7,20}$/
const URL_REGEX = /^https?:\/\/.+\..+/

export default {
	name: 'ClientForm',
	components: {
		NcButton,
		NcTextField,
		NcSelect,
	},
	props: {
		client: {
			type: Object,
			default: () => ({}),
		},
	},
	data() {
		return {
			form: {
				name: '',
				type: null,
				email: '',
				phone: '',
				website: '',
				address: '',
				notes: '',
			},
			errors: {
				name: '',
				type: '',
				email: '',
				phone: '',
				website: '',
			},
			typeOptions: ['person', 'organization'],
		}
	},
	computed: {
		isValid() {
			const hasName = this.form.name.trim().length > 0
			const hasType = !!this.form.type
			const noErrors = Object.values(this.errors).every(e => !e)
			return hasName && hasType && noErrors
		},
	},
	watch: {
		client: {
			immediate: true,
			handler(val) {
				if (val && Object.keys(val).length > 0) {
					this.populateForm(val)
				}
			},
		},
	},
	methods: {
		populateForm(data) {
			this.form = {
				name: data.name || '',
				type: data.type || null,
				email: data.email || '',
				phone: data.phone || '',
				website: data.website || '',
				address: data.address || '',
				notes: data.notes || '',
			}
			// Clear errors when populating
			this.errors = { name: '', type: '', email: '', phone: '', website: '' }
		},
		validateField(field) {
			switch (field) {
			case 'name':
				if (!this.form.name.trim()) {
					this.errors.name = t('pipelinq', 'Name is required')
				} else if (this.form.name.length > 255) {
					this.errors.name = t('pipelinq', 'Name must be at most 255 characters')
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
			case 'email':
				if (this.form.email && !EMAIL_REGEX.test(this.form.email)) {
					this.errors.email = t('pipelinq', 'Invalid email format')
				} else {
					this.errors.email = ''
				}
				break
			case 'phone':
				if (this.form.phone && !PHONE_REGEX.test(this.form.phone)) {
					this.errors.phone = t('pipelinq', 'Invalid phone format')
				} else {
					this.errors.phone = ''
				}
				break
			case 'website':
				if (this.form.website && !URL_REGEX.test(this.form.website)) {
					this.errors.website = t('pipelinq', 'Invalid URL format')
				} else {
					this.errors.website = ''
				}
				break
			}
		},
		validateAll() {
			this.validateField('name')
			this.validateField('type')
			this.validateField('email')
			this.validateField('phone')
			this.validateField('website')
			return this.isValid
		},
		onSave() {
			if (!this.validateAll()) {
				return
			}
			const data = { ...this.form }
			if (this.client?.id) {
				data.id = this.client.id
			}
			this.$emit('save', data)
		},
	},
}
</script>

<style scoped>
.client-form {
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

.client-form__actions {
	display: flex;
	gap: 12px;
	margin-top: 20px;
}
</style>
