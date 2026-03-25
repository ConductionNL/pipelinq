<template>
	<div class="contact-relationships">
		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else-if="relationships.length === 0" class="contact-relationships__empty">
			<p>{{ t('pipelinq', 'No relationships yet.') }}</p>
		</div>

		<div v-else>
			<div v-for="group in groupedRelationships" :key="group.category" class="relationship-group">
				<h4 class="relationship-group__title">
					{{ group.category }}
				</h4>
				<div class="viewTableContainer">
					<table class="viewTable">
						<thead>
							<tr>
								<th>{{ t('pipelinq', 'Name') }}</th>
								<th>{{ t('pipelinq', 'Relationship') }}</th>
								<th>{{ t('pipelinq', 'Status') }}</th>
								<th />
							</tr>
						</thead>
						<tbody>
							<tr
								v-for="rel in group.items"
								:key="rel.id"
								class="viewTableRow"
								:class="{ 'relationship--ended': isEnded(rel) }"
								@click="navigateToEntity(rel)">
								<td>{{ getEntityName(rel.toContact) }}</td>
								<td>{{ rel.type }}</td>
								<td>
									<span v-if="isEnded(rel)" class="relationship-status relationship-status--ended">
										{{ t('pipelinq', 'Ended') }}
									</span>
									<span v-else class="relationship-status relationship-status--active">
										{{ t('pipelinq', 'Active') }}
									</span>
								</td>
								<td class="relationship-actions" @click.stop>
									<NcButton type="tertiary" @click="editRelationship(rel)">
										{{ t('pipelinq', 'Edit') }}
									</NcButton>
									<NcButton type="tertiary" @click="removeRelationship(rel)">
										{{ t('pipelinq', 'Remove') }}
									</NcButton>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>

		<div class="contact-relationships__footer">
			<NcButton type="secondary" @click="showAddDialog = true">
				{{ t('pipelinq', 'Add relationship') }}
			</NcButton>
		</div>

		<!-- Add relationship dialog -->
		<div v-if="showAddDialog" class="create-overlay" @click.self="showAddDialog = false">
			<div class="create-dialog">
				<div class="create-dialog__header">
					<h3>{{ editingRelationship ? t('pipelinq', 'Edit relationship') : t('pipelinq', 'Add relationship') }}</h3>
					<NcButton type="tertiary" @click="closeDialog">
						&times;
					</NcButton>
				</div>
				<div class="create-dialog__body">
					<div v-if="!editingRelationship" class="form-group">
						<label>{{ t('pipelinq', 'Related entity') }} *</label>
						<NcSelect
							v-model="addForm.toContact"
							:options="entityOptions"
							:placeholder="t('pipelinq', 'Search contacts and clients...')"
							label="name"
							:reduce="opt => opt.id"
							@search="searchEntities" />
					</div>
					<div class="form-group">
						<label>{{ t('pipelinq', 'Relationship type') }} *</label>
						<NcSelect
							v-model="addForm.type"
							:options="typeOptions"
							:placeholder="t('pipelinq', 'Select type...')"
							label="label"
							:reduce="opt => opt.value"
							@input="onTypeSelect" />
					</div>
					<div class="form-group">
						<label>{{ t('pipelinq', 'Notes') }}</label>
						<textarea v-model="addForm.notes" rows="2" />
					</div>
					<div class="form-row">
						<div class="form-group">
							<label>{{ t('pipelinq', 'Start date') }}</label>
							<input v-model="addForm.startDate" type="date">
						</div>
						<div class="form-group">
							<label>{{ t('pipelinq', 'End date') }}</label>
							<input v-model="addForm.endDate" type="date">
						</div>
					</div>
					<div class="form-group">
						<label>{{ t('pipelinq', 'Strength') }}</label>
						<NcSelect
							v-model="addForm.strength"
							:options="strengthOptions"
							:placeholder="t('pipelinq', 'Select strength...')"
							label="label"
							:reduce="opt => opt.value" />
					</div>
					<div class="form-actions">
						<NcButton
							type="primary"
							:disabled="!addForm.toContact || !addForm.type"
							@click="saveRelationship">
							{{ editingRelationship ? t('pipelinq', 'Save') : t('pipelinq', 'Add') }}
						</NcButton>
						<NcButton @click="closeDialog">
							{{ t('pipelinq', 'Cancel') }}
						</NcButton>
					</div>
				</div>
			</div>
		</div>

		<!-- Delete confirmation dialog -->
		<NcDialog
			v-if="showDeleteDialog"
			:name="t('pipelinq', 'Remove relationship')"
			@closing="showDeleteDialog = false">
			<p>
				{{ t('pipelinq', 'Remove the relationship between {from} and {to}?', {
					from: entityName,
					to: getEntityName(deletingRelationship?.toContact),
				}) }}
			</p>
			<template #actions>
				<NcButton @click="showDeleteDialog = false">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton type="error" @click="confirmRemove">
					{{ t('pipelinq', 'Remove') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { useObjectStore } from '../store/modules/object.js'

const DEFAULT_RELATIONSHIP_TYPES = [
	{ value: 'partner', inverse: 'partner', category: 'Familie', label: 'Partner', symmetric: true },
	{ value: 'ouder', inverse: 'kind', category: 'Familie', label: 'Ouder', symmetric: false },
	{ value: 'kind', inverse: 'ouder', category: 'Familie', label: 'Kind', symmetric: false },
	{ value: 'broer/zus', inverse: 'broer/zus', category: 'Familie', label: 'Broer/Zus', symmetric: true },
	{ value: 'werkgever', inverse: 'werknemer', category: 'Professioneel', label: 'Werkgever', symmetric: false },
	{ value: 'werknemer', inverse: 'werkgever', category: 'Professioneel', label: 'Werknemer', symmetric: false },
	{ value: 'collega', inverse: 'collega', category: 'Professioneel', label: 'Collega', symmetric: true },
	{ value: 'contactpersoon', inverse: 'organisatie', category: 'Professioneel', label: 'Contactpersoon', symmetric: false },
	{ value: 'organisatie', inverse: 'contactpersoon', category: 'Professioneel', label: 'Organisatie', symmetric: false },
	{ value: 'moederorganisatie', inverse: 'dochterorganisatie', category: 'Organisatie', label: 'Moederorganisatie', symmetric: false },
	{ value: 'dochterorganisatie', inverse: 'moederorganisatie', category: 'Organisatie', label: 'Dochterorganisatie', symmetric: false },
	{ value: 'mentor', inverse: 'mentee', category: 'Professioneel', label: 'Mentor', symmetric: false },
	{ value: 'mentee', inverse: 'mentor', category: 'Professioneel', label: 'Mentee', symmetric: false },
]

export default {
	name: 'ContactRelationships',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcSelect,
	},
	props: {
		entityId: {
			type: String,
			required: true,
		},
		entityType: {
			type: String,
			required: true,
			validator: (value) => ['contact', 'client'].includes(value),
		},
		entityName: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			relationships: [],
			entityNameCache: {},
			loading: false,
			showAddDialog: false,
			showDeleteDialog: false,
			editingRelationship: null,
			deletingRelationship: null,
			entityOptions: [],
			searchTimeout: null,
			addForm: {
				toContact: null,
				type: null,
				notes: '',
				startDate: '',
				endDate: '',
				strength: 'medium',
			},
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		typeOptions() {
			return DEFAULT_RELATIONSHIP_TYPES.map(t => ({
				value: t.value,
				label: `${t.label} (${t.category})`,
				inverse: t.inverse,
				category: t.category,
				symmetric: t.symmetric,
			}))
		},
		strengthOptions() {
			return [
				{ value: 'strong', label: t('pipelinq', 'Strong') },
				{ value: 'medium', label: t('pipelinq', 'Medium') },
				{ value: 'weak', label: t('pipelinq', 'Weak') },
			]
		},
		groupedRelationships() {
			const groups = {}
			for (const rel of this.relationships) {
				const cat = rel.category || t('pipelinq', 'Other')
				if (!groups[cat]) {
					groups[cat] = { category: cat, items: [] }
				}
				groups[cat].items.push(rel)
			}
			// Sort: Organisatie first for client entity type, Familie first for contacts
			const order = this.entityType === 'client'
				? ['Organisatie', 'Professioneel', 'Familie', 'CRM Rol']
				: ['Familie', 'Professioneel', 'Organisatie', 'CRM Rol']
			return Object.values(groups).sort((a, b) => {
				const ai = order.indexOf(a.category)
				const bi = order.indexOf(b.category)
				return (ai === -1 ? 99 : ai) - (bi === -1 ? 99 : bi)
			})
		},
	},
	async mounted() {
		await this.fetchRelationships()
	},
	methods: {
		async fetchRelationships() {
			this.loading = true
			try {
				const items = await this.objectStore.fetchCollection('relationship', {
					_limit: 100,
					fromContact: this.entityId,
				})
				this.relationships = items || []
				// Pre-cache entity names
				for (const rel of this.relationships) {
					if (rel.toContact && !this.entityNameCache[rel.toContact]) {
						this.loadEntityName(rel.toContact, rel.toType || 'contact')
					}
				}
			} catch {
				this.relationships = []
			} finally {
				this.loading = false
			}
		},
		async loadEntityName(entityId, entityType) {
			try {
				const type = entityType === 'client' ? 'client' : 'contact'
				const entity = await this.objectStore.fetchObject(type, entityId)
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
		isEnded(rel) {
			if (!rel.endDate) {
				return false
			}
			return new Date(rel.endDate) < new Date()
		},
		navigateToEntity(rel) {
			const type = rel.toType === 'client' ? 'ClientDetail' : 'ContactDetail'
			this.$router.push({ name: type, params: { id: rel.toContact } })
		},
		onTypeSelect(typeValue) {
			const typeObj = DEFAULT_RELATIONSHIP_TYPES.find(t => t.value === typeValue)
			if (typeObj) {
				this.addForm._inverse = typeObj.inverse
				this.addForm._category = typeObj.category
				this.addForm._symmetric = typeObj.symmetric
			}
		},
		async searchEntities(query) {
			if (this.searchTimeout) {
				clearTimeout(this.searchTimeout)
			}
			if (!query || query.length < 2) {
				this.entityOptions = []
				return
			}
			this.searchTimeout = setTimeout(async () => {
				try {
					const [contacts, clients] = await Promise.all([
						this.objectStore.fetchCollection('contact', { _search: query, _limit: 10 }),
						this.objectStore.fetchCollection('client', { _search: query, _limit: 10 }),
					])
					this.entityOptions = [
						...(contacts || []).map(c => ({ id: c.id, name: c.name + ' (contact)', entityType: 'contact' })),
						...(clients || []).map(c => ({ id: c.id, name: c.name + ' (client)', entityType: 'client' })),
					].filter(e => e.id !== this.entityId)
				} catch {
					this.entityOptions = []
				}
			}, 300)
		},
		async saveRelationship() {
			if (!this.addForm.toContact || !this.addForm.type) {
				return
			}

			// Check for duplicate
			if (!this.editingRelationship) {
				const existing = this.relationships.find(
					r => r.toContact === this.addForm.toContact && r.type === this.addForm.type,
				)
				if (existing) {
					showError(t('pipelinq', 'This relationship already exists'))
					return
				}
			}

			const typeObj = DEFAULT_RELATIONSHIP_TYPES.find(t => t.value === this.addForm.type)
			const inverseType = typeObj ? typeObj.inverse : this.addForm.type
			const category = typeObj ? typeObj.category : ''
			const toEntityOption = this.entityOptions.find(e => e.id === this.addForm.toContact)
			const toEntityType = toEntityOption ? toEntityOption.entityType : 'contact'

			try {
				if (this.editingRelationship) {
					// Update existing relationship
					await this.objectStore.saveObject('relationship', {
						id: this.editingRelationship.id,
						type: this.addForm.type,
						inverseType,
						category,
						notes: this.addForm.notes,
						startDate: this.addForm.startDate || null,
						endDate: this.addForm.endDate || null,
						strength: this.addForm.strength,
					})
					// Update inverse if it exists
					if (this.editingRelationship._inverseId) {
						await this.objectStore.saveObject('relationship', {
							id: this.editingRelationship._inverseId,
							type: inverseType,
							inverseType: this.addForm.type,
							category,
							notes: this.addForm.notes,
							startDate: this.addForm.startDate || null,
							endDate: this.addForm.endDate || null,
							strength: this.addForm.strength,
						})
					}
				} else {
					// Create primary relationship
					await this.objectStore.saveObject('relationship', {
						fromContact: this.entityId,
						toContact: this.addForm.toContact,
						fromType: this.entityType,
						toType: toEntityType,
						type: this.addForm.type,
						inverseType,
						category,
						notes: this.addForm.notes,
						startDate: this.addForm.startDate || null,
						endDate: this.addForm.endDate || null,
						strength: this.addForm.strength,
					})

					// Create inverse relationship
					await this.objectStore.saveObject('relationship', {
						fromContact: this.addForm.toContact,
						toContact: this.entityId,
						fromType: toEntityType,
						toType: this.entityType,
						type: inverseType,
						inverseType: this.addForm.type,
						category,
						notes: this.addForm.notes,
						startDate: this.addForm.startDate || null,
						endDate: this.addForm.endDate || null,
						strength: this.addForm.strength,
					})
				}

				showSuccess(t('pipelinq', 'Relationship saved'))
				this.closeDialog()
				await this.fetchRelationships()
			} catch (e) {
				showError(e.message || t('pipelinq', 'Failed to save relationship'))
			}
		},
		editRelationship(rel) {
			this.editingRelationship = { ...rel }
			// Find the inverse relationship to track it
			const inverseRels = this.relationships.filter(
				r => r.toContact === this.entityId && r.fromContact === rel.toContact,
			)
			if (inverseRels.length > 0) {
				this.editingRelationship._inverseId = inverseRels[0].id
			}

			this.addForm = {
				toContact: rel.toContact,
				type: rel.type,
				notes: rel.notes || '',
				startDate: rel.startDate || '',
				endDate: rel.endDate || '',
				strength: rel.strength || 'medium',
			}
			this.showAddDialog = true
		},
		removeRelationship(rel) {
			this.deletingRelationship = rel
			this.showDeleteDialog = true
		},
		async confirmRemove() {
			if (!this.deletingRelationship) {
				return
			}

			try {
				// Delete the primary relationship
				await this.objectStore.deleteObject('relationship', this.deletingRelationship.id)

				// Find and delete inverse relationship
				const inverseRels = await this.objectStore.fetchCollection('relationship', {
					_limit: 10,
					fromContact: this.deletingRelationship.toContact,
					toContact: this.entityId,
				})
				for (const inv of (inverseRels || [])) {
					await this.objectStore.deleteObject('relationship', inv.id)
				}

				showSuccess(t('pipelinq', 'Relationship removed'))
				this.showDeleteDialog = false
				this.deletingRelationship = null
				await this.fetchRelationships()
			} catch (e) {
				showError(e.message || t('pipelinq', 'Failed to remove relationship'))
			}
		},
		closeDialog() {
			this.showAddDialog = false
			this.editingRelationship = null
			this.addForm = {
				toContact: null,
				type: null,
				notes: '',
				startDate: '',
				endDate: '',
				strength: 'medium',
			}
		},
	},
}
</script>

<style scoped>
.contact-relationships__empty {
	color: var(--color-text-maxcontrast);
	padding: 12px 0;
	text-align: center;
}

.contact-relationships__footer {
	margin-top: 12px;
}

.relationship-group {
	margin-bottom: 16px;
}

.relationship-group__title {
	margin: 0 0 8px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.relationship--ended {
	opacity: 0.6;
}

.relationship-status {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 12px;
	font-weight: 600;
}

.relationship-status--active {
	background: #dcfce7;
	color: #166534;
}

.relationship-status--ended {
	background: #fee2e2;
	color: #991b1b;
}

.relationship-actions {
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
	cursor: pointer;
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

.form-group textarea,
.form-group input[type='date'] {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
}

.form-row {
	display: flex;
	gap: 12px;
}

.form-row .form-group {
	flex: 1;
}

.form-actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
}
</style>
