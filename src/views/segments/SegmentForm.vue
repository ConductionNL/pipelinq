<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<div class="segment-form">
		<header class="segment-form__header">
			<h2>
				{{
					isEditing
						? t('pipelinq', 'Edit segment')
						: t('pipelinq', 'New segment')
				}}
			</h2>
		</header>

		<NcLoadingIcon v-if="loading" :size="32" />

		<div v-else class="segment-form__body">
			<p v-if="loadError" class="segment-form__error" role="alert">
				{{ loadError }}
			</p>

			<section class="segment-form__panel">
				<label class="segment-form__label" for="segment-form-name">
					{{ t('pipelinq', 'Segment name') }} *
				</label>
				<input
					id="segment-form-name"
					v-model="model.name"
					type="text"
					class="segment-form__input"
					:placeholder="t('pipelinq', 'Inactive Leads')" />

				<label class="segment-form__label" for="segment-form-description">
					{{ t('pipelinq', 'Description') }}
				</label>
				<textarea
					id="segment-form-description"
					v-model="model.description"
					class="segment-form__textarea"
					rows="2" />

				<NcSelect
					v-model="entityTypeOption"
					:options="entityTypeOptions"
					:inputLabel="t('pipelinq', 'Audience') + ' *'"
					label="label"
					:clearable="false"
					:disabled="isEditing"
					class="segment-form__entity-type" />
			</section>

			<section class="segment-form__panel">
				<SegmentBuilder
					v-if="model.entityType"
					v-model="model.rules"
					:entityType="model.entityType"
					:fieldOptions="fieldOptions"
					@validityChange="onValidityChange" />
				<p v-else class="segment-form__hint">
					{{ t('pipelinq', 'Choose an audience to start adding rules.') }}
				</p>
			</section>

			<p v-if="saveError" class="segment-form__error" role="alert">
				{{ saveError }}
			</p>

			<footer class="segment-form__actions">
				<NcButton
					variant="tertiary"
					@click="$router.push({ name: 'Segments' })">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canSave" @click="save">
					{{
						isEditing
							? t('pipelinq', 'Save changes')
							: t('pipelinq', 'Create segment')
					}}
				</NcButton>
			</footer>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import SegmentBuilder from '../../components/SegmentBuilder.vue'

/**
 * Curated field options for the "contact" audience, grounded in the real
 * `contact` schema properties (lib/Settings/pipelinq_register.json). Kept
 * as a static list rather than a live schema-introspection endpoint --
 * that is a materially larger surface than a UI-repair change warrants;
 * see DEFERRED_QUESTIONS in the change's proposal.
 *
 * @type {Array<{value:string,label:string,type:string}>}
 */
const CONTACT_FIELD_OPTIONS = [
	{ value: 'name', label: 'Name', type: 'string' },
	{ value: 'email', label: 'Email', type: 'string' },
	{ value: 'phone', label: 'Phone', type: 'string' },
	{ value: 'role', label: 'Role', type: 'string' },
	{ value: 'marketingConsent', label: 'Marketing consent', type: 'boolean' },
	{ value: 'doNotContact', label: 'Do not contact', type: 'boolean' },
]

/**
 * Curated field options for the "customer" audience (the `client` schema).
 *
 * @type {Array<{value:string,label:string,type:string}>}
 */
const CUSTOMER_FIELD_OPTIONS = [
	{ value: 'name', label: 'Name', type: 'string' },
	{ value: 'type', label: 'Organisation type', type: 'string' },
	{ value: 'email', label: 'Email', type: 'string' },
	{ value: 'phone', label: 'Phone', type: 'string' },
	{ value: 'address', label: 'Address', type: 'string' },
	{ value: 'website', label: 'Website', type: 'string' },
	{ value: 'industry', label: 'Industry', type: 'string' },
]

export default {
	name: 'SegmentForm',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		SegmentBuilder,
	},

	data() {
		return {
			loading: false,
			loadError: '',
			saveError: '',
			saving: false,
			isValidTree: false,
			model: {
				name: '',
				description: '',
				entityType: 'contact',
				rules: { type: 'AND', children: [] },
			},
		}
	},

	computed: {
		/**
		 * Edit mode is determined by a route `:id` param, matching the
		 * PosTransactionForm / ProjectActivityList convention used across
		 * the app for one component serving both New and Edit.
		 *
		 * @return {string|null} The Segment id being edited, or null for New.
		 *
		 * @spec openspec/changes/marketing-segments-ui-repair/specs/marketing-ui/spec.md#requirement-segments-and-templates-pages-are-reachable-from-the-marketing-menu
		 */
		segmentId() {
			return this.$route?.params?.id || null
		},

		/**
		 * @return {boolean} Whether this instance is editing an existing Segment.
		 */
		isEditing() {
			return this.segmentId !== null
		},

		/**
		 * @return {Array<{value:string,label:string}>} The two audience options.
		 *
		 * @spec openspec/changes/marketing-segments-ui-repair/specs/marketing-ui/spec.md#requirement-segments-and-templates-pages-are-reachable-from-the-marketing-menu
		 */
		entityTypeOptions() {
			return [
				{ value: 'contact', label: this.t('pipelinq', 'Contacts') },
				{ value: 'customer', label: this.t('pipelinq', 'Customers') },
			]
		},

		/**
		 * Selected audience as an NcSelect option object.
		 *
		 * @return {object}
		 */
		entityTypeOption: {
			/**
			 * @return {object} The currently selected audience option.
			 *
			 * @spec openspec/changes/marketing-segments-ui-repair/specs/marketing-ui/spec.md#requirement-segments-and-templates-pages-are-reachable-from-the-marketing-menu
			 */
			get() {
				return (
					this.entityTypeOptions.find(
						(o) => o.value === this.model.entityType,
					) || this.entityTypeOptions[0]
				)
			},

			/**
			 * Switching audience resets the rule tree — a "customer" field
			 * does not exist on "contact", so carrying it over would leave
			 * SegmentBuilder holding references to fields the new audience
			 * does not have.
			 *
			 * @param {object} option The NcSelect option just picked.
			 *
			 * @spec openspec/changes/marketing-segments-ui-repair/specs/marketing-ui/spec.md#requirement-segments-and-templates-pages-are-reachable-from-the-marketing-menu
			 */
			set(option) {
				const next = option?.value || 'contact'
				if (next !== this.model.entityType) {
					this.model.entityType = next
					// Switching audience invalidates the rule tree's field
					// references (a "customer" field does not exist on
					// "contact"), so it is reset rather than carried over.
					this.model.rules = { type: 'AND', children: [] }
					this.isValidTree = false
				}
			},
		},

		/**
		 * Field options offered to SegmentBuilder for the selected audience.
		 *
		 * @return {Array<{value:string,label:string,type:string}>}
		 *
		 * @spec openspec/changes/marketing-segments-ui-repair/specs/marketing-ui/spec.md#requirement-segment-builder-ui-composes-rule-trees
		 */
		fieldOptions() {
			return this.model.entityType === 'customer'
				? CUSTOMER_FIELD_OPTIONS
				: CONTACT_FIELD_OPTIONS
		},

		/**
		 * Save is enabled once a name is present, an audience is chosen, and
		 * the rule tree has passed live validation (marketing-ui spec:
		 * "disable save until resolved").
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/specs/marketing-ui/spec.md#requirement-segment-builder-ui-composes-rule-trees
		 */
		canSave() {
			return (
				this.model.name.trim() !== ''
				&& this.model.entityType !== ''
				&& this.isValidTree
				&& !this.saving
			)
		},
	},

	/**
	 * Loads the existing Segment when this instance is mounted in edit mode.
	 *
	 * @return {Promise<void>}
	 *
	 * @spec openspec/changes/marketing-segments-ui-repair/specs/marketing-ui/spec.md#requirement-segments-and-templates-pages-are-reachable-from-the-marketing-menu
	 */
	async created() {
		if (this.isEditing) {
			await this.loadSegment()
		}
	},

	methods: {
		/**
		 * React to SegmentBuilder's validity signal.
		 *
		 * @param {boolean} valid Whether the current rule tree is valid.
		 *
		 * @spec openspec/specs/marketing-ui/spec.md#requirement-segment-builder-ui-composes-rule-trees
		 */
		onValidityChange(valid) {
			this.isValidTree = valid
		},

		/**
		 * Load the Segment being edited.
		 *
		 * @spec openspec/changes/marketing-segments-ui-repair/specs/marketing-ui/spec.md#requirement-segment-builder-ui-composes-rule-trees
		 */
		async loadSegment() {
			this.loading = true
			this.loadError = ''
			try {
				const url = generateUrl(
					`/apps/pipelinq/api/segments/${this.segmentId}`,
				)
				const { data } = await axios.get(url)
				this.model.name = data?.name || ''
				this.model.description = data?.description || ''
				this.model.entityType = data?.entityType || 'contact'
				this.model.rules = data?.rules || { type: 'AND', children: [] }
				// A saved tree is assumed valid until edited again; the
				// builder re-validates on the first change regardless.
				this.isValidTree = true
			} catch (e) {
				this.loadError =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not load this segment.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create or update the Segment.
		 *
		 * @spec openspec/changes/marketing-segments-ui-repair/specs/marketing-api/spec.md#requirement-api-endpoints-crud-and-query
		 */
		async save() {
			if (!this.canSave) {
				return
			}
			this.saving = true
			this.saveError = ''
			const payload = {
				name: this.model.name,
				description: this.model.description,
				entityType: this.model.entityType,
				rules: this.model.rules,
			}
			try {
				if (this.isEditing) {
					const url = generateUrl(
						`/apps/pipelinq/api/segments/${this.segmentId}`,
					)
					await axios.patch(url, payload)
				} else {
					const url = generateUrl('/apps/pipelinq/api/segments')
					await axios.post(url, payload)
				}
				this.$router.push({ name: 'Segments' })
			} catch (e) {
				this.saveError =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not save this segment.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.segment-form {
	max-width: 900px;
	margin: 0 auto;
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.segment-form__header h2 {
	margin: 0;
}

.segment-form__body {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.segment-form__panel {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.segment-form__label {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.segment-form__input,
.segment-form__textarea {
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-darker);
	color: var(--color-main-text);
}

.segment-form__entity-type {
	max-width: 320px;
}

.segment-form__hint {
	color: var(--color-text-lighter);
}

.segment-form__error {
	color: var(--color-error);
	font-weight: 600;
}

.segment-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
