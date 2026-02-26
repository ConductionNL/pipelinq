<template>
	<div class="contact-form">
		<div class="form-group">
			<label for="contact-name">{{ t('pipelinq', 'Name') }} *</label>
			<NcTextField
				id="contact-name"
				:value="form.name"
				:error="!!errors.name"
				:helper-text="errors.name"
				:maxlength="255"
				@update:value="v => { form.name = v; validateField('name') }" />
		</div>

		<div class="form-group">
			<label for="contact-client">{{ t('pipelinq', 'Client') }} *</label>
			<NcSelect
				input-id="contact-client"
				v-model="selectedClient"
				:options="clientOptions"
				:placeholder="t('pipelinq', 'Search for a client...')"
				label="name"
				:reduce="c => c.id"
				@search="searchClients"
				@input="validateField('client')" />
			<p v-if="errors.client" class="field-error">{{ errors.client }}</p>
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="contact-role">{{ t('pipelinq', 'Role') }}</label>
				<NcTextField
					id="contact-role"
					:value="form.role"
					@update:value="v => form.role = v" />
			</div>
			<div class="form-group">
				<label for="contact-email">{{ t('pipelinq', 'Email') }}</label>
				<NcTextField
					id="contact-email"
					:value="form.email"
					:error="!!errors.email"
					:helper-text="errors.email"
					type="email"
					@update:value="v => { form.email = v; validateField('email') }" />
			</div>
		</div>

		<div class="form-group">
			<label for="contact-phone">{{ t('pipelinq', 'Phone') }}</label>
			<NcTextField
				id="contact-phone"
				:value="form.phone"
				:error="!!errors.phone"
				:helper-text="errors.phone"
				@update:value="v => { form.phone = v; validateField('phone') }" />
		</div>

		<div class="contact-form__actions">
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

const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const PHONE_REGEX = /^[+]?[\d\s\-().]{7,20}$/

export default {
	name: 'ContactForm',
	components: {
		NcButton,
		NcTextField,
		NcSelect,
	},
	props: {
		contact: {
			type: Object,
			default: () => ({}),
		},
		preSelectedClient: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			form: {
				name: '',
				client: null,
				role: '',
				email: '',
				phone: '',
			},
			errors: {
				name: '',
				client: '',
				email: '',
				phone: '',
			},
			selectedClient: null,
			clientOptions: [],
			searchTimeout: null,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isValid() {
			const hasName = this.form.name.trim().length > 0
			const hasClient = !!this.selectedClient
			const noErrors = Object.values(this.errors).every(e => !e)
			return hasName && hasClient && noErrors
		},
	},
	watch: {
		contact: {
			immediate: true,
			handler(val) {
				if (val && Object.keys(val).length > 0) {
					this.populateForm(val)
				}
			},
		},
		selectedClient(val) {
			this.form.client = val
		},
	},
	async mounted() {
		await this.loadInitialClients()
		if (this.preSelectedClient) {
			this.selectedClient = this.preSelectedClient
			await this.ensureClientInOptions(this.preSelectedClient)
		}
	},
	methods: {
		populateForm(data) {
			this.form = {
				name: data.name || '',
				client: data.client || null,
				role: data.role || '',
				email: data.email || '',
				phone: data.phone || '',
			}
			this.selectedClient = data.client || null
			if (data.client) {
				this.ensureClientInOptions(data.client)
			}
			this.errors = { name: '', client: '', email: '', phone: '' }
		},
		async loadInitialClients() {
			const clients = await this.objectStore.fetchCollection('client', { _limit: 50 })
			this.clientOptions = (clients || []).map(c => ({ id: c.id, name: c.name || c.id }))
		},
		async ensureClientInOptions(clientId) {
			if (!this.clientOptions.find(c => c.id === clientId)) {
				try {
					const client = await this.objectStore.fetchObject('client', clientId)
					if (client) {
						this.clientOptions.push({ id: client.id, name: client.name || client.id })
					}
				} catch {
					// Client not found
				}
			}
		},
		searchClients(query) {
			clearTimeout(this.searchTimeout)
			this.searchTimeout = setTimeout(async () => {
				if (query.length > 0) {
					const results = await this.objectStore.fetchCollection('client', {
						_search: query,
						_limit: 20,
					})
					this.clientOptions = (results || []).map(c => ({ id: c.id, name: c.name || c.id }))
				} else {
					await this.loadInitialClients()
				}
			}, 300)
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
			case 'client':
				if (!this.selectedClient) {
					this.errors.client = t('pipelinq', 'Client is required')
				} else {
					this.errors.client = ''
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
			}
		},
		validateAll() {
			this.validateField('name')
			this.validateField('client')
			this.validateField('email')
			this.validateField('phone')
			return this.isValid
		},
		onSave() {
			if (!this.validateAll()) {
				return
			}
			const data = { ...this.form }
			if (this.contact?.id) {
				data.id = this.contact.id
			}
			this.$emit('save', data)
		},
	},
}
</script>

<style scoped>
.contact-form {
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

.contact-form__actions {
	display: flex;
	gap: 12px;
	margin-top: 20px;
}
</style>
