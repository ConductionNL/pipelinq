<template>
	<div class="client-detail">
		<div class="client-detail__header">
			<NcButton @click="$emit('navigate', 'clients')">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2 v-if="!isNew">
				{{ clientData.name || t('pipelinq', 'Client') }}
			</h2>
			<h2 v-else>
				{{ t('pipelinq', 'New client') }}
			</h2>
		</div>

		<NcLoadingIcon v-if="loading" />

		<div v-else class="client-detail__form">
			<div class="form-group">
				<label>{{ t('pipelinq', 'Name') }}</label>
				<NcTextField :value="form.name" @update:value="v => form.name = v" />
			</div>
			<div class="form-row">
				<div class="form-group">
					<label>{{ t('pipelinq', 'Type') }}</label>
					<NcSelect
						v-model="form.type"
						:options="typeOptions"
						:placeholder="t('pipelinq', 'Type')" />
				</div>
				<div class="form-group">
					<label>{{ t('pipelinq', 'Email') }}</label>
					<NcTextField :value="form.email" @update:value="v => form.email = v" />
				</div>
			</div>
			<div class="form-row">
				<div class="form-group">
					<label>{{ t('pipelinq', 'Phone') }}</label>
					<NcTextField :value="form.phone" @update:value="v => form.phone = v" />
				</div>
				<div class="form-group">
					<label>{{ t('pipelinq', 'Address') }}</label>
					<NcTextField :value="form.address" @update:value="v => form.address = v" />
				</div>
			</div>
			<div class="form-group">
				<label>{{ t('pipelinq', 'Notes') }}</label>
				<textarea v-model="form.notes" rows="3" />
			</div>

			<div class="client-detail__actions">
				<NcButton type="primary" @click="save">
					{{ t('pipelinq', 'Save') }}
				</NcButton>
				<NcButton v-if="!isNew" type="error" @click="confirmDelete">
					{{ t('pipelinq', 'Delete') }}
				</NcButton>
			</div>
		</div>

		<!-- Requests section -->
		<div v-if="!isNew && !loading" class="client-detail__requests">
			<div class="section-header">
				<h3>{{ t('pipelinq', 'Requests') }}</h3>
				<NcButton @click="createRequest">
					{{ t('pipelinq', 'New request') }}
				</NcButton>
			</div>

			<div v-if="requests.length === 0" class="section-empty">
				<p>{{ t('pipelinq', 'No requests found') }}</p>
			</div>
			<table v-else class="section-table">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Title') }}</th>
						<th>{{ t('pipelinq', 'Status') }}</th>
						<th>{{ t('pipelinq', 'Priority') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="request in requests"
						:key="request.id"
						class="section-row"
						@click="$emit('navigate', 'request-detail', request.id)">
						<td>{{ request.title || '-' }}</td>
						<td>{{ request.status || '-' }}</td>
						<td>{{ request.priority || '-' }}</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Contacts section -->
		<div v-if="!isNew && !loading" class="client-detail__contacts">
			<h3>{{ t('pipelinq', 'Contacts') }}</h3>

			<div v-if="contacts.length === 0" class="section-empty">
				<p>{{ t('pipelinq', 'No contacts found') }}</p>
			</div>
			<table v-else class="section-table">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Name') }}</th>
						<th>{{ t('pipelinq', 'Email') }}</th>
						<th>{{ t('pipelinq', 'Phone') }}</th>
						<th>{{ t('pipelinq', 'Role') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="contact in contacts" :key="contact.id">
						<td>{{ contact.name || '-' }}</td>
						<td>{{ contact.email || '-' }}</td>
						<td>{{ contact.phone || '-' }}</td>
						<td>{{ contact.role || '-' }}</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcTextField, NcSelect } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ClientDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
		NcSelect,
	},
	props: {
		clientId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			form: {
				name: '',
				email: '',
				phone: '',
				type: 'person',
				address: '',
				notes: '',
			},
			requests: [],
			contacts: [],
			typeOptions: ['person', 'organization'],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isNew() {
			return !this.clientId || this.clientId === 'new'
		},
		loading() {
			return this.objectStore.isLoading('client')
		},
		clientData() {
			if (this.isNew) return {}
			return this.objectStore.getObject('client', this.clientId) || {}
		},
	},
	async mounted() {
		if (!this.isNew) {
			await this.objectStore.fetchObject('client', this.clientId)
			this.populateForm()
			await this.fetchRelated()
		}
	},
	methods: {
		populateForm() {
			const data = this.clientData
			this.form = {
				name: data.name || '',
				email: data.email || '',
				phone: data.phone || '',
				type: data.type || 'person',
				address: data.address || '',
				notes: data.notes || '',
			}
		},
		async save() {
			const objectData = { ...this.form }
			if (!this.isNew) {
				objectData.id = this.clientId
			}

			const result = await this.objectStore.saveObject('client', objectData)
			if (result) {
				if (this.isNew) {
					this.$emit('navigate', 'client-detail', result.id)
				}
			}
		},
		async confirmDelete() {
			if (confirm(t('pipelinq', 'Are you sure you want to delete this?'))) {
				const success = await this.objectStore.deleteObject('client', this.clientId)
				if (success) {
					this.$emit('navigate', 'clients')
				}
			}
		},
		async fetchRelated() {
			const allRequests = await this.objectStore.fetchCollection('request', {
				_limit: 50,
				client: this.clientId,
			})
			this.requests = allRequests || []

			const allContacts = await this.objectStore.fetchCollection('contact', {
				_limit: 50,
				client: this.clientId,
			})
			this.contacts = allContacts || []
		},
		createRequest() {
			// Navigate to request creation with pre-linked client
			this.$emit('navigate', 'request-detail', `new?client=${this.clientId}`)
		},
	},
}
</script>

<style scoped>
.client-detail {
	padding: 20px;
	max-width: 800px;
}

.client-detail__header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 20px;
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

.client-detail__actions {
	display: flex;
	gap: 12px;
	margin-top: 20px;
}

.client-detail__requests,
.client-detail__contacts {
	margin-top: 40px;
	border-top: 1px solid var(--color-border);
	padding-top: 20px;
}

.section-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.section-table {
	width: 100%;
	border-collapse: collapse;
}

.section-table th,
.section-table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.section-row {
	cursor: pointer;
}

.section-row:hover {
	background: var(--color-background-hover);
}

.section-empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 20px;
}
</style>
