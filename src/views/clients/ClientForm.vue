<template>
	<div class="client-form" data-testid="client-form">
		<div class="form-group">
			<label for="client-name">{{ t('pipelinq', 'Name') }} *</label>
			<NcTextField
				id="client-name"
				label-outside
				:value="form.name"
				:error="!!errors.name"
				:helper-text="errors.name"
				:maxlength="255"
				data-testid="client-name-input"
				@update:value="v => { form.name = v; validateField('name') }" />
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="client-type">{{ t('pipelinq', 'Type') }} *</label>
				<NcSelect
					v-model="form.type"
					input-id="client-type"
					:input-label="t('pipelinq', 'Type')"
					label-outside
					:options="typeOptions"
					:placeholder="t('pipelinq', 'Select type')"
					data-testid="client-type-select"
					@input="validateField('type')" />
				<p v-if="errors.type" class="field-error">
					{{ errors.type }}
				</p>
			</div>
			<div class="form-group">
				<label for="client-email">{{ t('pipelinq', 'Email') }}</label>
				<NcTextField
					id="client-email"
					label-outside
					:value="form.email"
					:error="!!errors.email"
					:helper-text="errors.email"
					type="email"
					data-testid="client-email-input"
					@update:value="v => { form.email = v; validateField('email') }" />
			</div>
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="client-phone">{{ t('pipelinq', 'Phone') }}</label>
				<NcTextField
					id="client-phone"
					label-outside
					:value="form.phone"
					:error="!!errors.phone"
					:helper-text="errors.phone"
					data-testid="client-phone-input"
					@update:value="v => { form.phone = v; validateField('phone') }" />
			</div>
			<div class="form-group">
				<label for="client-website">{{ t('pipelinq', 'Website') }}</label>
				<NcTextField
					id="client-website"
					label-outside
					:value="form.website"
					:error="!!errors.website"
					:helper-text="errors.website"
					data-testid="client-website-input"
					@update:value="v => { form.website = v; validateField('website') }" />
			</div>
		</div>

		<div class="form-group">
			<label for="client-address">{{ t('pipelinq', 'Address') }}</label>
			<NcTextField
				id="client-address"
				label-outside
				:value="form.address"
				data-testid="client-address-input"
				@update:value="v => form.address = v" />
		</div>

		<div class="form-group">
			<label for="client-notes">{{ t('pipelinq', 'Notes') }}</label>
			<textarea id="client-notes"
				v-model="form.notes"
				rows="3"
				data-testid="client-notes-input" />
		</div>

		<div v-if="showActions" class="client-form__actions">
			<NcButton type="primary"
				:disabled="!isValid"
				data-testid="client-form-save"
				@click="onSave">
				{{ t('pipelinq', 'Save') }}
			</NcButton>
			<NcButton data-testid="client-form-cancel" @click="$emit('cancel')">
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

/**
 * @spec openspec/changes/2026-03-20-client-management/tasks.md#task-3.1
 */
const TYPE_MAPPING = {
	person: 'schema:Person',
	organization: 'schema:Organization',
}

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
		/**
		 * Render the built-in Save / Cancel buttons. Set to `false` when the
		 * host supplies its own action buttons (e.g. a parent NcDialog driving
		 * the form via a ref + the `update:valid` event).
		 */
		showActions: {
			type: Boolean,
			default: true,
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
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-27
		 */
		isValid() {
			const hasName = this.form.name.trim().length > 0
			const hasType = !!this.form.type
			const noErrors = Object.values(this.errors).every(e => !e)
			return hasName && hasType && noErrors
		},
	},
	watch: {
		// Surface validity so a host (e.g. a parent NcDialog) can enable or
		// disable its own submit button.
		isValid: {
			immediate: true,
			handler(val) {
				this.$emit('update:valid', val)
			},
		},
		client: {
			immediate: true,
			/**
			 * @param val
			 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-26
			 */
			handler(val) {
				if (val && Object.keys(val).length > 0) {
					this.populateForm(val)
				}
			},
		},
	},
	methods: {
		/**
		 * @param data
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-29
		 */
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
		/**
		 * @param field
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-31
		 */
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
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-30
		 */
		validateAll() {
			this.validateField('name')
			this.validateField('type')
			this.validateField('email')
			this.validateField('phone')
			this.validateField('website')
			return this.isValid
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-28
		 * @spec openspec/changes/2026-03-20-client-management/tasks.md#task-3.1
		 */
		onSave() {
			if (!this.validateAll()) {
				return
			}
			const data = { ...this.form }
			if (this.client?.id) {
				data.id = this.client.id
			}
			data['@type'] = TYPE_MAPPING[data.type] ?? 'schema:Person'
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
