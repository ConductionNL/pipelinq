<template>
	<div class="lead-contact-roles">
		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else-if="contactRoles.length === 0" class="lead-contact-roles__empty">
			<p>{{ t('pipelinq', 'No contact roles assigned.') }}</p>
		</div>

		<div v-else class="viewTableContainer">
			<table class="viewTable">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Contact') }}</th>
						<th>{{ t('pipelinq', 'Role') }}</th>
						<th>{{ t('pipelinq', 'Notes') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="role in sortedContactRoles" :key="role.id" class="viewTableRow">
						<td>
							<a
								class="contact-link"
								@click.prevent="$router.push({ name: 'ContactDetail', params: { id: role.toContact } })">
								{{ getEntityName(role.toContact) }}
							</a>
						</td>
						<td>
							<span class="role-badge" :class="'role-badge--' + role.type">
								{{ getRoleLabel(role.type) }}
							</span>
						</td>
						<td>{{ role.notes || '-' }}</td>
						<td class="role-actions" @click.stop>
							<NcButton type="tertiary" @click="removeRole(role)">
								{{ t('pipelinq', 'Remove') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="lead-contact-roles__footer">
			<NcButton type="secondary" @click="showAddDialog = true">
				{{ t('pipelinq', 'Add contact role') }}
			</NcButton>
		</div>

		<!-- Add role dialog -->
		<div v-if="showAddDialog" class="create-overlay" @click.self="showAddDialog = false">
			<div class="create-dialog">
				<div class="create-dialog__header">
					<h3>{{ t('pipelinq', 'Add contact role') }}</h3>
					<NcButton type="tertiary" @click="showAddDialog = false">
						&times;
					</NcButton>
				</div>
				<div class="create-dialog__body">
					<div class="form-group">
						<label>{{ t('pipelinq', 'Contact') }} *</label>
						<NcSelect
							v-model="addForm.toContact"
							:options="contactOptions"
							:placeholder="t('pipelinq', 'Search contacts...')"
							label="name"
							:reduce="opt => opt.id"
							@search="searchContacts" />
					</div>
					<div class="form-group">
						<label>{{ t('pipelinq', 'Role') }} *</label>
						<NcSelect
							v-model="addForm.type"
							:options="roleOptions"
							:placeholder="t('pipelinq', 'Select role...')"
							label="label"
							:reduce="opt => opt.value" />
					</div>
					<div class="form-group">
						<label>{{ t('pipelinq', 'Notes') }}</label>
						<textarea v-model="addForm.notes" rows="2" />
					</div>
					<div class="form-actions">
						<NcButton
							type="primary"
							:disabled="!addForm.toContact || !addForm.type"
							@click="addRole">
							{{ t('pipelinq', 'Add') }}
						</NcButton>
						<NcButton @click="showAddDialog = false">
							{{ t('pipelinq', 'Cancel') }}
						</NcButton>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { useObjectStore } from '../store/modules/object.js'

const CRM_ROLES = [
	{ value: 'beslisser', label: 'Beslisser (Decision Maker)', order: 1 },
	{ value: 'beinvloeder', label: 'Beinvloeder (Influencer)', order: 2 },
	{ value: 'gatekeeper', label: 'Gatekeeper', order: 3 },
	{ value: 'kampioen', label: 'Kampioen (Champion)', order: 4 },
	{ value: 'evaluator', label: 'Evaluator', order: 5 },
	{ value: 'gebruiker', label: 'Gebruiker (End User)', order: 6 },
]

export default {
	name: 'LeadContactRoles',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
	},
	props: {
		leadId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			contactRoles: [],
			entityNameCache: {},
			loading: false,
			showAddDialog: false,
			contactOptions: [],
			searchTimeout: null,
			addForm: {
				toContact: null,
				type: null,
				notes: '',
			},
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		roleOptions() {
			return CRM_ROLES
		},
		sortedContactRoles() {
			return [...this.contactRoles].sort((a, b) => {
				const aOrder = CRM_ROLES.find(r => r.value === a.type)?.order || 99
				const bOrder = CRM_ROLES.find(r => r.value === b.type)?.order || 99
				return aOrder - bOrder
			})
		},
	},
	async mounted() {
		await this.fetchRoles()
	},
	methods: {
		async fetchRoles() {
			this.loading = true
			try {
				const items = await this.objectStore.fetchCollection('relationship', {
					_limit: 50,
					fromContact: this.leadId,
					category: 'CRM Rol',
				})
				this.contactRoles = items || []
				for (const role of this.contactRoles) {
					if (role.toContact && !this.entityNameCache[role.toContact]) {
						this.loadEntityName(role.toContact)
					}
				}
			} catch {
				this.contactRoles = []
			} finally {
				this.loading = false
			}
		},
		async loadEntityName(entityId) {
			try {
				const entity = await this.objectStore.fetchObject('contact', entityId)
				if (entity) {
					this.$set(this.entityNameCache, entityId, entity.name || entityId)
				} else {
					this.$set(this.entityNameCache, entityId, t('pipelinq', '[Deleted]'))
				}
			} catch {
				this.$set(this.entityNameCache, entityId, entityId)
			}
		},
		getEntityName(entityId) {
			return this.entityNameCache[entityId] || entityId || '-'
		},
		getRoleLabel(roleType) {
			const role = CRM_ROLES.find(r => r.value === roleType)
			return role ? role.label : roleType
		},
		async searchContacts(query) {
			if (this.searchTimeout) {
				clearTimeout(this.searchTimeout)
			}
			if (!query || query.length < 2) {
				this.contactOptions = []
				return
			}
			this.searchTimeout = setTimeout(async () => {
				try {
					const contacts = await this.objectStore.fetchCollection('contact', { _search: query, _limit: 10 })
					this.contactOptions = (contacts || []).map(c => ({ id: c.id, name: c.name || c.id }))
				} catch {
					this.contactOptions = []
				}
			}, 300)
		},
		async addRole() {
			if (!this.addForm.toContact || !this.addForm.type) {
				return
			}

			try {
				await this.objectStore.saveObject('relationship', {
					fromContact: this.leadId,
					toContact: this.addForm.toContact,
					fromType: 'lead',
					toType: 'contact',
					type: this.addForm.type,
					inverseType: this.addForm.type,
					category: 'CRM Rol',
					notes: this.addForm.notes || '',
					strength: 'medium',
				})

				showSuccess(t('pipelinq', 'Contact role added'))
				this.showAddDialog = false
				this.addForm = { toContact: null, type: null, notes: '' }
				await this.fetchRoles()
			} catch (e) {
				showError(e.message || t('pipelinq', 'Failed to add contact role'))
			}
		},
		async removeRole(role) {
			if (!confirm(t('pipelinq', 'Remove this contact role from the lead?'))) {
				return
			}

			try {
				await this.objectStore.deleteObject('relationship', role.id)
				showSuccess(t('pipelinq', 'Contact role removed'))
				await this.fetchRoles()
			} catch (e) {
				showError(e.message || t('pipelinq', 'Failed to remove contact role'))
			}
		},
	},
}
</script>

<style scoped>
.lead-contact-roles__empty {
	color: var(--color-text-maxcontrast);
	padding: 12px 0;
	text-align: center;
}

.lead-contact-roles__footer {
	margin-top: 12px;
}

.contact-link {
	font-weight: bold;
	color: var(--color-primary);
	cursor: pointer;
}

.contact-link:hover {
	text-decoration: underline;
}

.role-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 12px;
	font-weight: 600;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.role-badge--beslisser {
	background: #dbeafe;
	color: #1e40af;
}

.role-badge--beinvloeder {
	background: #fef3c7;
	color: #92400e;
}

.role-badge--gatekeeper {
	background: #fce7f3;
	color: #9d174d;
}

.role-badge--kampioen {
	background: #dcfce7;
	color: #166534;
}

.role-actions {
	display: flex;
	gap: 4px;
}

.viewTableContainer {
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	overflow: hidden;
	box-shadow: 0 2px 4px var(--color-box-shadow);
	border: 1px solid var(--color-border);
}

.viewTable {
	width: 100%;
	border-collapse: collapse;
}

.viewTable th,
.viewTable td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.viewTable th {
	background-color: var(--color-background-dark);
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.viewTableRow {
	transition: background-color 0.2s ease;
}

.viewTableRow:hover {
	background: var(--color-background-hover);
}

/* Dialog styles */
.create-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10000;
}

.create-dialog {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	box-shadow: 0 4px 24px rgba(0, 0, 0, 0.2);
	width: 500px;
	max-width: 90vw;
	max-height: 85vh;
	overflow-y: auto;
}

.create-dialog__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 16px 20px;
	border-bottom: 1px solid var(--color-border);
}

.create-dialog__header h3 {
	margin: 0;
}

.create-dialog__body {
	padding: 20px;
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

.form-group textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
}

.form-actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
}
</style>
