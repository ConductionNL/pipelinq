<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2024 Conduction B.V. -->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'Tender Types')"
		:description="t('pipelinq', 'Configure the payment methods available at the POS checkout and their GL accounts')">
		<NcLoadingIcon v-if="loading" />

		<div v-else class="tender-types">
			<div v-for="type in tenderTypes" :key="type.id" class="tender-item">
				<div v-if="editingId !== type.id" class="tender-item__display">
					<div class="tender-item__info">
						<span class="tender-title">{{ type.name }}</span>
						<span class="tender-code">{{ type.code }}</span>
						<span class="tender-meta">{{ t('pipelinq', 'GL Account') }}: {{ type.glAccount }}</span>
						<span v-if="type.isActive === false" class="inactive-tag">{{ t('pipelinq', 'Inactive') }}</span>
					</div>
					<div class="tender-item__actions">
						<NcButton @click="startEdit(type)">
							{{ t('pipelinq', 'Edit') }}
						</NcButton>
						<NcButton type="error" @click="remove(type)">
							{{ t('pipelinq', 'Delete') }}
						</NcButton>
					</div>
				</div>

				<div v-else class="tender-item__edit">
					<div class="edit-field">
						<label>{{ t('pipelinq', 'Name') }}</label>
						<input v-model="editForm.name" type="text">
					</div>
					<div class="edit-field">
						<label>{{ t('pipelinq', 'Code') }}</label>
						<input v-model="editForm.code" type="text" disabled>
					</div>
					<div class="edit-field">
						<label>{{ t('pipelinq', 'GL Account') }}</label>
						<input v-model="editForm.glAccount" type="text">
					</div>
					<div class="edit-field">
						<label>
							<input v-model="editForm.requiresReference" type="checkbox">
							{{ t('pipelinq', 'Requires reference') }}
						</label>
					</div>
					<div class="edit-field">
						<label>
							<input v-model="editForm.requiresPin" type="checkbox">
							{{ t('pipelinq', 'Requires PIN') }}
						</label>
					</div>
					<div class="edit-field">
						<label>
							<input v-model="editForm.allowsChange" type="checkbox">
							{{ t('pipelinq', 'Allows change') }}
						</label>
					</div>
					<div class="edit-field">
						<label>
							<input v-model="editForm.isActive" type="checkbox">
							{{ t('pipelinq', 'Active') }}
						</label>
					</div>
					<div class="edit-field">
						<label>{{ t('pipelinq', 'Sort order') }}</label>
						<input v-model.number="editForm.sortOrder" type="number">
					</div>
					<div class="edit-actions">
						<NcButton @click="cancelEdit">
							{{ t('pipelinq', 'Cancel') }}
						</NcButton>
						<NcButton type="primary" :disabled="saving" @click="saveEdit">
							{{ t('pipelinq', 'Save') }}
						</NcButton>
					</div>
				</div>
			</div>

			<div v-if="tenderTypes.length === 0" class="tender-empty">
				{{ t('pipelinq', 'No tender types yet.') }}
			</div>

			<div class="tender-create">
				<h4>{{ t('pipelinq', 'Create Tender Type') }}</h4>
				<div class="edit-field">
					<label>{{ t('pipelinq', 'Name') }}</label>
					<input v-model="createForm.name" type="text">
				</div>
				<div class="edit-field">
					<label>{{ t('pipelinq', 'Code') }}</label>
					<input v-model="createForm.code" type="text">
				</div>
				<div class="edit-field">
					<label>{{ t('pipelinq', 'GL Account') }}</label>
					<input v-model="createForm.glAccount" type="text">
				</div>
				<div class="edit-field">
					<label>
						<input v-model="createForm.requiresReference" type="checkbox">
						{{ t('pipelinq', 'Requires reference') }}
					</label>
				</div>
				<div class="edit-field">
					<label>
						<input v-model="createForm.allowsChange" type="checkbox">
						{{ t('pipelinq', 'Allows change') }}
					</label>
				</div>
				<NcButton type="primary" :disabled="saving || !canCreate" @click="create">
					{{ t('pipelinq', 'Create Tender Type') }}
				</NcButton>
			</div>
		</div>
	</NcSettingsSection>
</template>

<script>
import { NcSettingsSection, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'

export default {
	name: 'TenderTypeManager',
	components: {
		NcSettingsSection,
		NcButton,
		NcLoadingIcon,
	},
	data() {
		return {
			tenderTypes: [],
			loading: false,
			saving: false,
			editingId: null,
			editForm: {},
			createForm: this.emptyCreateForm(),
		}
	},
	computed: {
		/**
		 * Whether the create form has the required fields.
		 *
		 * @return {boolean} Whether create is allowed.
		 */
		canCreate() {
			return this.createForm.name.trim() !== ''
				&& this.createForm.code.trim() !== ''
				&& this.createForm.glAccount.trim() !== ''
		},
	},
	async mounted() {
		await this.load()
	},
	methods: {
		/**
		 * An empty create form.
		 *
		 * @return {object} The blank form.
		 */
		emptyCreateForm() {
			return {
				name: '',
				code: '',
				glAccount: '',
				requiresReference: false,
				allowsChange: false,
			}
		},
		/**
		 * Load all tender types (active and inactive) for management.
		 */
		async load() {
			this.loading = true
			try {
				const response = await fetch(
					generateUrl('/apps/pipelinq/api/pos/tender-types'),
					{ headers: { requesttoken: OC.requestToken, 'OCS-APIREQUEST': 'true' } },
				)
				const data = await response.json().catch(() => ({}))
				this.tenderTypes = Array.isArray(data.tenderTypes) ? data.tenderTypes : []
			} catch (e) {
				this.tenderTypes = []
			} finally {
				this.loading = false
			}
		},
		/**
		 * Begin editing a tender type.
		 *
		 * @param {object} type The tender type.
		 */
		startEdit(type) {
			this.editingId = type.id || type.uuid
			this.editForm = {
				name: type.name || '',
				code: type.code || '',
				glAccount: type.glAccount || '',
				requiresReference: !!type.requiresReference,
				requiresPin: !!type.requiresPin,
				allowsChange: !!type.allowsChange,
				isActive: type.isActive !== false,
				sortOrder: type.sortOrder || 0,
			}
		},
		/**
		 * Cancel editing.
		 */
		cancelEdit() {
			this.editingId = null
			this.editForm = {}
		},
		/**
		 * Persist an edit.
		 */
		async saveEdit() {
			this.saving = true
			try {
				const response = await fetch(
					generateUrl(`/apps/pipelinq/api/pos/tender-types/${this.editingId}`),
					{
						method: 'PUT',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
						body: JSON.stringify(this.editForm),
					},
				)
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					showError(data.error || t('pipelinq', 'Could not save the tender type.'))
					return
				}
				showSuccess(t('pipelinq', 'Tender type saved.'))
				this.cancelEdit()
				await this.load()
			} catch (e) {
				showError(t('pipelinq', 'Could not save the tender type.'))
			} finally {
				this.saving = false
			}
		},
		/**
		 * Create a new tender type.
		 */
		async create() {
			if (!this.canCreate) {
				return
			}
			this.saving = true
			try {
				const response = await fetch(
					generateUrl('/apps/pipelinq/api/pos/tender-types'),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
						body: JSON.stringify(this.createForm),
					},
				)
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					showError(data.error || t('pipelinq', 'Could not create the tender type.'))
					return
				}
				showSuccess(t('pipelinq', 'Tender type created.'))
				this.createForm = this.emptyCreateForm()
				await this.load()
			} catch (e) {
				showError(t('pipelinq', 'Could not create the tender type.'))
			} finally {
				this.saving = false
			}
		},
		/**
		 * Delete a tender type, surfacing the active-references conflict.
		 *
		 * @param {object} type The tender type.
		 */
		async remove(type) {
			if (!window.confirm(t('pipelinq', 'Delete this tender type?'))) {
				return
			}
			try {
				const response = await fetch(
					generateUrl(`/apps/pipelinq/api/pos/tender-types/${type.id || type.uuid}`),
					{
						method: 'DELETE',
						headers: { requesttoken: OC.requestToken, 'OCS-APIREQUEST': 'true' },
					},
				)
				if (!response.ok) {
					const data = await response.json().catch(() => ({}))
					showError(data.error || t('pipelinq', 'Could not delete the tender type.'))
					return
				}
				showSuccess(t('pipelinq', 'Tender type deleted.'))
				await this.load()
			} catch (e) {
				showError(t('pipelinq', 'Could not delete the tender type.'))
			}
		},
	},
}
</script>

<style scoped>
.tender-types {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.tender-item {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 10px 12px;
}

.tender-item__display {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
}

.tender-item__info {
	display: flex;
	gap: 12px;
	align-items: baseline;
	flex-wrap: wrap;
}

.tender-title {
	font-weight: bold;
}

.tender-code {
	font-family: monospace;
	color: var(--color-text-maxcontrast);
}

.tender-meta {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.inactive-tag {
	color: var(--color-warning);
	font-size: 12px;
}

.edit-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 8px;
}

.edit-field input[type="text"],
.edit-field input[type="number"] {
	max-width: 320px;
}

.edit-actions {
	display: flex;
	gap: 8px;
}

.tender-create {
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
}

.tender-empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
