<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - Segment create / edit, mounted into the Segments index page's
  - `form-dialog` slot. It saves through POST / PATCH /api/segments rather than
  - the slot's `confirm`, because only that endpoint validates the rule tree,
  - and then calls the slot's `refresh` so the list shows the result.
  -->
<template>
	<NcDialog
		v-if="show"
		:name="isEditing ? t('pipelinq', 'Edit segment') : t('pipelinq', 'New segment')"
		:open="true"
		size="large"
		:closeOnClickOutside="false"
		@closing="close()">
		<div class="segment-form">
			<NcLoadingIcon v-if="loading" :size="32" class="segment-form__loading" />
			<NcNoteCard v-else-if="loadError" type="error">
				{{ loadError }}
			</NcNoteCard>

			<template v-else>
				<section class="segment-form__details">
					<NcTextField
						v-model="model.name"
						:label="t('pipelinq', 'Segment name')"
						:placeholder="t('pipelinq', 'Inactive Leads')"
						required
						class="segment-form__name" />
					<!-- The reason sits on a wrapper: a disabled select gets no
					     mouse events, so a `title` on it would never show. -->
					<div
						class="segment-form__audience"
						:class="{ 'segment-form__audience--locked': isEditing }"
						:title="isEditing ? t('pipelinq', 'The audience of an existing segment cannot be changed.') : null">
						<NcSelect
							v-model="entityTypeOption"
							:options="entityTypeOptions"
							:inputLabel="t('pipelinq', 'Audience')"
							label="label"
							:clearable="false"
							:searchable="false"
							:disabled="isEditing" />
						<p v-if="audienceHint" class="segment-form__hint">
							{{ audienceHint }}
						</p>
					</div>
					<NcTextArea
						v-model="model.description"
						:label="t('pipelinq', 'Description')"
						resize="vertical"
						rows="2"
						class="segment-form__description" />
				</section>

				<section class="segment-form__rules">
					<SegmentBuilder
						:key="model.entityType"
						v-model="model.rules"
						:entityType="model.entityType"
						:fieldOptions="fieldOptions"
						@statusChange="rulesStatus = $event" />
				</section>

				<NcNoteCard v-if="saveError" type="error" class="segment-form__save-error">
					{{ saveError }}
				</NcNoteCard>
			</template>
		</div>

		<template #actions>
			<span v-if="saveHint" class="segment-form__save-hint">
				{{ saveHint }}
			</span>
			<NcButton variant="tertiary" @click="close()">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canSave" @click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ isEditing ? t('pipelinq', 'Save changes') : t('pipelinq', 'Create segment') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import SegmentBuilder from '../components/SegmentBuilder.vue'
import { fieldOptionsFor } from '../services/segmentFieldOptions.js'

/**
 * @return {object} A blank segment.
 */
function blankModel() {
	return {
		name: '',
		description: '',
		entityType: 'contact',
		rules: { type: 'AND', children: [] },
	}
}

export default {
	name: 'SegmentFormDialog',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
		SegmentBuilder,
	},

	inheritAttrs: false,

	props: {
		/** Whether the index page has the dialog open. */
		show: {
			type: Boolean,
			default: false,
		},

		/** The row being edited, or null to create. */
		item: {
			type: Object,
			default: null,
		},

		/** Closes the dialog. */
		close: {
			type: Function,
			default: () => {},
		},

		/** Re-reads the index page's list. */
		refresh: {
			type: Function,
			default: null,
		},
	},

	data() {
		return {
			model: blankModel(),
			loading: false,
			loadError: '',
			saving: false,
			saveError: '',
			rulesStatus: 'empty',
		}
	},

	computed: {
		segmentId() {
			return this.item?.id || this.item?.['@self']?.id || this.item?.uuid || null
		},

		isEditing() {
			return this.segmentId !== null
		},

		entityTypeOptions() {
			return [
				{ value: 'contact', label: this.t('pipelinq', 'Contacts') },
				{ value: 'customer', label: this.t('pipelinq', 'Customers') },
			]
		},

		entityTypeOption: {
			get() {
				return this.entityTypeOptions.find((o) => o.value === this.model.entityType) || this.entityTypeOptions[0]
			},

			/**
			 * Switch audience. The rules are cleared, because their fields
			 * belong to the other audience's schema.
			 *
			 * @param {object} option The picked option.
			 */
			set(option) {
				const next = option?.value || 'contact'
				if (next !== this.model.entityType) {
					this.model.entityType = next
					this.model.rules = { type: 'AND', children: [] }
				}
			},
		},

		audienceHint() {
			if (!this.isEditing && this.model.rules.children?.length) {
				return this.t('pipelinq', 'Changing the audience clears the rules.')
			}
			return ''
		},

		fieldOptions() {
			return fieldOptionsFor(this.model.entityType)
		},

		/**
		 * @return {boolean} A name, and rules the server has accepted.
		 * @spec openspec/specs/marketing-ui/spec.md#requirement-segment-builder-ui-composes-rule-trees
		 */
		canSave() {
			return this.model.name.trim() !== '' && this.rulesStatus === 'valid' && !this.saving && !this.loading
		},

		/** @return {string} Why Save is disabled, when the reason is not already on screen. */
		saveHint() {
			if (this.loading || this.saving || this.loadError) {
				return ''
			}
			if (this.model.name.trim() === '') {
				return this.t('pipelinq', 'Give the segment a name.')
			}
			if (this.rulesStatus === 'empty') {
				return this.t('pipelinq', 'Add at least one condition.')
			}
			if (this.rulesStatus === 'incomplete') {
				return this.t('pipelinq', 'Complete or remove the unfinished conditions.')
			}
			return ''
		},
	},

	watch: {
		show: {
			immediate: true,
			handler(open) {
				if (open) {
					this.reset()
				}
			},
		},
	},

	methods: {
		reset() {
			this.model = blankModel()
			this.loadError = ''
			this.saveError = ''
			this.rulesStatus = 'empty'
			if (this.isEditing) {
				this.loadSegment()
			}
		},

		async loadSegment() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl(`/apps/pipelinq/api/segments/${this.segmentId}`))
				this.model = {
					name: data?.name || '',
					description: data?.description || '',
					entityType: data?.entityType || 'contact',
					rules: data?.rules || { type: 'AND', children: [] },
				}
			} catch (e) {
				this.loadError = e?.response?.data?.error || this.t('pipelinq', 'Could not load this segment.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @spec openspec/changes/marketing-segments-ui-repair/specs/marketing-api/spec.md#requirement-api-endpoints-crud-and-query
		 */
		async save() {
			if (!this.canSave) {
				return
			}
			this.saving = true
			this.saveError = ''
			const payload = {
				name: this.model.name.trim(),
				description: this.model.description,
				entityType: this.model.entityType,
				rules: this.model.rules,
			}
			try {
				if (this.isEditing) {
					await axios.patch(generateUrl(`/apps/pipelinq/api/segments/${this.segmentId}`), payload)
					showSuccess(this.t('pipelinq', 'Segment saved.'))
				} else {
					await axios.post(generateUrl('/apps/pipelinq/api/segments'), payload)
					showSuccess(this.t('pipelinq', 'Segment created.'))
				}
				this.refresh?.()
				this.close()
			} catch (e) {
				this.saveError = e?.response?.data?.error || this.t('pipelinq', 'Could not save this segment.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.segment-form {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding-bottom: 8px;
}

.segment-form__loading {
	margin: 32px auto;
}

.segment-form__details {
	display: grid;
	grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
	align-items: start;
	gap: 12px 16px;
}

.segment-form__description {
	grid-column: 1 / -1;
}

/* Lines the select up with the name field beside it. */
.segment-form__audience {
	margin-top: 6px;
}

.segment-form__audience :deep(.v-select.select) {
	width: 100%;
	margin: 0;
}

/* NcSelect barely changes when disabled, so the locked state is drawn here. */
.segment-form__audience--locked {
	cursor: not-allowed;
}

.segment-form__audience--locked :deep(.v-select.select) {
	pointer-events: none;
	opacity: 0.5;
}

.segment-form__hint {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.segment-form__rules {
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.segment-form__save-error {
	margin: 0;
}

.segment-form__save-hint {
	margin-inline-end: auto;
	color: var(--color-text-maxcontrast);
}

@media (max-width: 720px) {
	.segment-form__details {
		grid-template-columns: minmax(0, 1fr);
	}
}
</style>
