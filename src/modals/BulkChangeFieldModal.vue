<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Change one field on the selected clients or contacts. The dialog picks a
  - property and a value, asks OpenRegister for a previewed bulk job, shows how
  - many records change and how many already hold the value, and commits only
  - when the user confirms (ADR-022: OpenRegister owns the write).
  -
  - @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md
  -->
<template>
	<CnDialog
		:name="t('pipelinq', 'Change a field')"
		:eyebrow="n('pipelinq', '%n record selected', '%n records selected', selectedIds.length)"
		size="normal"
		:noClose="committing"
		data-testid="bulk-change-field-dialog"
		@closing="$emit('close')">
		<div class="bulk-change">
			<NcLoadingIcon v-if="loadingSchema" :size="28" />

			<template v-else>
				<NcSelect
					:inputLabel="t('pipelinq', 'Field to change')"
					:options="propertyOptions"
					:modelValue="selectedPropertyOption"
					:clearable="false"
					:disabled="previewing || committing"
					data-testid="bulk-change-property"
					@update:modelValue="onPropertyPick" />

				<div class="bulk-change__value">
					<NcCheckboxRadioSwitch
						v-if="valueKind === 'boolean'"
						type="switch"
						:modelValue="value === true"
						data-testid="bulk-change-value-boolean"
						@update:modelValue="v => (value = v)">
						{{ propertyLabel }}
					</NcCheckboxRadioSwitch>
					<NcSelect
						v-else-if="valueKind === 'enum'"
						:inputLabel="propertyLabel"
						:options="enumOptions"
						:modelValue="value"
						data-testid="bulk-change-value-enum"
						@update:modelValue="v => (value = v)" />
					<NcTextField
						v-else
						:label="propertyLabel"
						:type="valueKind === 'number' ? 'number' : 'text'"
						:modelValue="value === null ? '' : String(value)"
						data-testid="bulk-change-value-text"
						@update:modelValue="v => (value = v)" />
				</div>

				<NcNoteCard v-if="refusal" type="error" data-testid="bulk-change-refusal">
					{{ refusal }}
				</NcNoteCard>

				<div v-else-if="preview" class="bulk-change__summary" data-testid="bulk-change-summary">
					<CnStatusBadge
						:label="n('pipelinq', '%n record will change', '%n records will change', summary.changed)"
						variant="primary" />
					<CnStatusBadge
						v-if="summary.skipped > 0"
						:label="n('pipelinq', '%n already has this value', '%n already have this value', summary.skipped)"
						variant="default" />
				</div>
			</template>
		</div>

		<template #actions>
			<NcButton :disabled="committing" @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!preview || refusal"
				variant="primary"
				:disabled="!canPreview || previewing"
				data-testid="bulk-change-preview"
				@click="runPreview">
				<template #icon>
					<NcLoadingIcon v-if="previewing" :size="18" />
				</template>
				{{ t('pipelinq', 'Preview') }}
			</NcButton>
			<NcButton
				v-else
				variant="primary"
				:disabled="committing || summary.changed === 0"
				data-testid="bulk-change-confirm"
				@click="confirm">
				<template #icon>
					<Check :size="18" />
				</template>
				{{ t('pipelinq', 'Confirm') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog, CnStatusBadge } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import {
	changeSummary,
	commitJob,
	createSetPropertiesJob,
	refusalReason,
	resolveScope,
	writablePropertiesOf,
} from '../services/bulkJobs.js'
import { getSchemaProperties } from '../services/viewService.js'

export default {
	name: 'BulkChangeFieldModal',
	components: {
		Check,
		CnDialog,
		CnStatusBadge,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The selected records, merged in by the bulk action. */
		selectedIds: { type: Array, default: () => [] },
		/** The register slug, from the bulk action's props. */
		register: { type: String, default: '' },
		/** The schema slug, from the bulk action's props. */
		schema: { type: String, default: '' },
		/**
		 * The schema object. The page does not pass it; the dialog loads it on
		 * mount. A test may inject it to skip the fetch.
		 */
		schemaObject: { type: Object, default: null },
	},

	emits: ['close'],

	data() {
		return {
			loadingSchema: true,
			properties: [],
			property: '',
			value: '',
			previewing: false,
			committing: false,
			preview: null,
			refusal: '',
		}
	},

	computed: {
		/** @return {Array<{id: string, label: string}>} The property picker options. */
		propertyOptions() {
			return this.properties.map((p) => ({ id: p.id, label: p.label }))
		},

		/** @return {object|null} The picked property option. */
		selectedPropertyOption() {
			return this.propertyOptions.find((o) => o.id === this.property) ?? null
		},

		/** @return {object|null} The picked property definition. */
		selectedProperty() {
			return this.properties.find((p) => p.id === this.property) ?? null
		},

		/** @return {string} The picked property's label. */
		propertyLabel() {
			return this.selectedProperty ? this.selectedProperty.label : ''
		},

		/** @return {string} How the value is entered: boolean, number, enum or text. */
		valueKind() {
			const p = this.selectedProperty
			if (!p) return 'text'
			if (Array.isArray(p.enum) && p.enum.length > 0) return 'enum'
			if (p.type === 'boolean') return 'boolean'
			if (p.type === 'number' || p.type === 'integer') return 'number'
			return 'text'
		},

		/** @return {Array<string>} The enum options, when the property is one. */
		enumOptions() {
			const p = this.selectedProperty
			return (p && Array.isArray(p.enum)) ? p.enum : []
		},

		/** @return {boolean} Whether a preview can be asked for. */
		canPreview() {
			return this.property !== '' && this.selectedIds.length > 0
		},

		/** @return {{changed: number, skipped: number}} The preview's counts. */
		summary() {
			return changeSummary(this.preview)
		},
	},

	async created() {
		await this.loadSchema()
	},

	methods: {
		/**
		 * Load the schema and list its writable properties, carrying the enum
		 * through so the value input can offer it.
		 *
		 * @return {Promise<void>}
		 */
		async loadSchema() {
			this.loadingSchema = true
			try {
				const schema = this.schemaObject ?? await getSchemaProperties(this.schema)
				const defs = (schema && typeof schema.properties === 'object') ? schema.properties : {}
				this.properties = writablePropertiesOf(schema).map((p) => ({
					...p,
					enum: Array.isArray(defs[p.id]?.enum) ? defs[p.id].enum : null,
				}))
			} catch {
				showError(this.t('pipelinq', 'Could not read the fields of this list.'))
				this.properties = []
			} finally {
				this.loadingSchema = false
			}
		},

		/**
		 * Pick a property, resetting the value and any earlier preview.
		 *
		 * @param {object} option The picked option.
		 * @return {void}
		 */
		onPropertyPick(option) {
			this.property = option ? option.id : ''
			this.value = this.valueKind === 'boolean' ? false : ''
			this.preview = null
			this.refusal = ''
		},

		/**
		 * The value as the schema types it: a number for a number property, the
		 * boolean as is, the text otherwise.
		 *
		 * @return {string|number|boolean} The typed value.
		 */
		typedValue() {
			if (this.valueKind === 'number') {
				const asNumber = Number(this.value)
				return Number.isFinite(asNumber) ? asNumber : this.value
			}
			return this.value
		},

		/**
		 * Ask OpenRegister for a previewed job. Nothing is written. A refusal
		 * shows its reason in the dialog.
		 *
		 * @return {Promise<void>}
		 */
		async runPreview() {
			this.previewing = true
			this.refusal = ''
			this.preview = null
			try {
				const scope = await resolveScope(this.register, this.schema, this.selectedIds[0])
				this.preview = await createSetPropertiesJob({
					register: scope.register,
					schema: scope.schema,
					ids: this.selectedIds,
					properties: { [this.property]: this.typedValue() },
				})
			} catch (error) {
				this.refusal = refusalReason(error, this.t('pipelinq', 'OpenRegister refused this change.'))
			} finally {
				this.previewing = false
			}
		},

		/**
		 * Commit the previewed job, then let the list read the new values.
		 *
		 * @return {Promise<void>}
		 */
		async confirm() {
			if (!this.preview || this.preview.id === undefined) return
			this.committing = true
			try {
				await commitJob(this.preview.id)
				showSuccess(this.n('pipelinq', '%n record changed.', '%n records changed.', this.summary.changed))
				emit('cn:page:refresh', {})
				this.$emit('close')
			} catch (error) {
				this.refusal = refusalReason(error, this.t('pipelinq', 'The change could not be saved.'))
				this.preview = null
			} finally {
				this.committing = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.bulk-change {
	display: flex;
	flex-direction: column;
	gap: 16px;
	min-height: 120px;
}

.bulk-change__summary {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
