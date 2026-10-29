<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - POS tender type admin list (pos-split-tender REQ-PST-001).
  -
  - @spec openspec/changes/pos-split-tender/tasks.md#8.1
  -->
<template>
	<div class="pos-tender-type-list">
		<!-- No heading of its own: the host NcSettingsSection (PosTenderTypeManager,
		     on the admin page) already names and describes this surface. -->
		<NcLoadingIcon v-if="loading" :size="32" />

		<!-- One row per tender type, as the board draws it, with a sort and a
		     filter in every head; adding one is the section's title-row button
		     (PosTenderTypeManager). -->
		<CnDataTable
			v-else-if="tenderTypes.length > 0"
			class="pos-tender-type-list__table"
			data-testid="pos-tender-types"
			:columns="columns"
			:schema="tableSchema"
			:rows="tableRows"
			:rowClass="() => 'pos-tender-type-list__row'"
			filterable
			localHeaders>
			<template #column-isActive="{ row }">
				<span class="cn-status-badge" :class="row.isActive ? 'cn-status-badge--success' : 'cn-status-badge--default'">
					{{ row.isActive ? t('pipelinq', 'Active') : t('pipelinq', 'Inactive') }}
				</span>
			</template>
			<template #actions-header>
				<span class="hidden-visually">{{ t('pipelinq', 'Actions') }}</span>
			</template>
			<template #row-actions="{ row }">
				<span class="pos-tender-type-list__row-actions">
					<NcButton @click="editType(row.type)">
						{{ t('pipelinq', 'Edit') }}
					</NcButton>
					<NcButton variant="error" @click="deleteType(row.type)">
						{{ t('pipelinq', 'Delete') }}
					</NcButton>
				</span>
			</template>
		</CnDataTable>

		<p v-else class="pos-tender-type-list__empty">
			{{ t('pipelinq', 'No tender types defined yet.') }}
		</p>

		<p v-if="errorMessage" class="pos-tender-type-list__error" role="alert">
			{{ errorMessage }}
		</p>

		<PosTenderTypeFormDialog
			v-if="showForm"
			:tenderType="editingType"
			@close="onFormClose"
			@saved="onFormSaved" />
		<ConfirmDialog
			v-if="pendingDeleteType"
			:name="t('pipelinq', 'Delete tender type')"
			:message="deleteTypeMessage"
			:confirmLabel="t('pipelinq', 'Delete')"
			@confirm="performDeleteType"
			@cancel="pendingDeleteType = null" />
	</div>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import ConfirmDialog from '../../dialogs/ConfirmDialog.vue'
import PosTenderTypeFormDialog from '../../modals/PosTenderTypeFormDialog.vue'

export default {
	name: 'PosTenderTypeList',
	components: {
		CnDataTable,
		ConfirmDialog,
		NcButton,
		NcLoadingIcon,
		PosTenderTypeFormDialog,
	},

	data() {
		return {
			tenderTypes: [],
			loading: false,
			showForm: false,
			editingType: null,
			errorMessage: '',
			pendingDeleteType: null,
		}
	},

	computed: {
		/**
		 * The table's columns, every one sortable and filterable from its head.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/pos-split-tender/tasks.md#8.1
		 */
		columns() {
			return [
				{ key: 'name', label: t('pipelinq', 'Name') },
				{ key: 'code', label: t('pipelinq', 'Code') },
				{ key: 'glAccount', label: t('pipelinq', 'GL account') },
				{ key: 'flags', label: t('pipelinq', 'Flags') },
				{ key: 'isActive', label: t('pipelinq', 'Active') },
			]
		},

		/**
		 * The fields the heads sort and filter on.
		 *
		 * @return {object}
		 */
		tableSchema() {
			return {
				properties: {
					name: { type: 'string' },
					code: { type: 'string' },
					glAccount: { type: 'string' },
					flags: { type: 'string' },
					isActive: { type: 'boolean' },
				},
			}
		},

		/**
		 * One row per tender type, its flags in words, the type itself kept for
		 * the row actions.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/pos-split-tender/tasks.md#8.1
		 */
		tableRows() {
			return this.tenderTypes.map((type) => ({
				id: this.idOf(type) || type.code,
				name: type.name,
				code: type.code,
				glAccount: type.glAccount || '—',
				flags: this.flagsOf(type) || '—',
				isActive: !!type.isActive,
				type,
			}))
		},

		/**
		 * Built here rather than inline in the template so the t() key stays
		 * byte-identical to the one the old window.confirm used. Escaping the
		 * quotes as &quot; in the attribute would have minted a NEW key and
		 * orphaned every existing translation of this string.
		 *
		 * @return {string} The confirmation message.
		 *
		 * @spec openspec/changes/pos-split-tender/tasks.md#8.1
		 */
		deleteTypeMessage() {
			if (!this.pendingDeleteType) {
				return ''
			}
			return t(
				'pipelinq',
				'Delete tender type "{name}"? Active tenders referencing this type block deletion.',
				{ name: this.pendingDeleteType.name },
			)
		},
	},

	async mounted() {
		await this.refresh()
	},

	methods: {
		/**
		 * The flags a tender type carries, as one comma line for the table.
		 *
		 * @param {object} type The tender type.
		 * @return {string} The flags, or '' when none is set.
		 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.1
		 */
		flagsOf(type) {
			return [
				type.allowsChange ? t('pipelinq', 'Change') : '',
				type.requiresReference ? t('pipelinq', 'Ref') : '',
				type.requiresPin ? t('pipelinq', 'PIN') : '',
			].filter(Boolean).join(', ')
		},

		async refresh() {
			this.loading = true
			this.errorMessage = ''
			try {
				const url = generateUrl('/apps/pipelinq/api/pos/tender-types')
				const response = await axios.get(url)
				const results = response?.data?.results || []
				this.tenderTypes = results
					.slice()
					.sort((a, b) => (a.sortOrder || 0) - (b.sortOrder || 0))
			} catch (error) {
				this.errorMessage =
					error?.response?.data?.error
					|| t('pipelinq', 'Failed to load tender types')
			} finally {
				this.loading = false
			}
		},

		createNew() {
			this.editingType = null
			this.showForm = true
		},

		/**
		 * Open the edit form for a tender type.
		 *
		 * @param {object} type The tender type to edit.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/pos-split-tender/tasks.md#8.1
		 */
		editType(type) {
			this.editingType = { ...type }
			this.showForm = true
		},

		/**
		 * Open the delete confirmation for a tender type.
		 *
		 * @param {object} type The tender type to delete.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/pos-split-tender/tasks.md#8.1
		 */
		deleteType(type) {
			this.pendingDeleteType = type
		},

		/**
		 * Delete the pending tender type once the dialog confirms.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/pos-split-tender/tasks.md#8.1
		 */
		async performDeleteType() {
			const type = this.pendingDeleteType
			this.pendingDeleteType = null
			if (!type) {
				return
			}
			try {
				const id = this.idOf(type)
				const url = generateUrl('/apps/pipelinq/api/pos/tender-types/{id}', {
					id,
				})
				await axios.delete(url)
				showSuccess(t('pipelinq', 'Tender type deleted'))
				await this.refresh()
			} catch (error) {
				const msg =
					error?.response?.data?.error
					|| t('pipelinq', 'Failed to delete tender type')
				showError(msg)
			}
		},

		onFormClose() {
			this.showForm = false
			this.editingType = null
		},

		async onFormSaved() {
			this.showForm = false
			this.editingType = null
			await this.refresh()
		},

		idOf(type) {
			if (type?.['@self']?.id) {
				return type['@self'].id
			}
			return type?.id || type?.uuid || ''
		},
	},
}
</script>

<style scoped>
.pos-tender-type-list {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

/* Cells take the board look's settings-table rhythm; the table only spans
   the card. */
.pos-tender-type-list__table {
	width: 100%;
	border-collapse: collapse;
}

.pos-tender-type-list__row-actions {
	white-space: nowrap;
}

.pos-tender-type-list__row-actions > * + * {
	margin-inline-start: 8px;
}

.pos-tender-type-list__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.pos-tender-type-list__error {
	color: var(--color-error);
}
</style>
