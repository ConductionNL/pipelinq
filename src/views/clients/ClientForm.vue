<template>
	<div class="client-form" data-testid="client-form">
		<div class="form-group">
			<label for="client-name">{{ t('pipelinq', 'Name') }} *</label>
			<NcTextField
				id="client-name"
				labelOutside
				:label="t('pipelinq', 'Name')"
				:modelValue="form.name"
				:error="!!shownErrors.name"
				:helperText="shownErrors.name"
				:maxlength="255"
				data-testid="client-name-input"
				@update:modelValue="(v) => (form.name = v)" />
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="client-type">{{ t('pipelinq', 'Type') }} *</label>
				<NcSelect
					v-model="form.type"
					inputId="client-type"
					:inputLabel="t('pipelinq', 'Type')"
					labelOutside
					:options="typeOptions"
					:reduce="(o) => o.value"
					:placeholder="t('pipelinq', 'Select type')"
					data-testid="client-type-select" />
				<p v-if="shownErrors.type" class="field-error" role="alert">
					{{ shownErrors.type }}
				</p>
			</div>
			<div class="form-group">
				<label for="client-email">{{ t('pipelinq', 'Email') }}</label>
				<NcTextField
					id="client-email"
					labelOutside
					:label="t('pipelinq', 'Email')"
					:modelValue="form.email"
					:error="!!shownErrors.email"
					:helperText="shownErrors.email"
					type="email"
					data-testid="client-email-input"
					@update:modelValue="(v) => (form.email = v)" />
			</div>
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="client-phone">{{ t('pipelinq', 'Phone') }}</label>
				<NcTextField
					id="client-phone"
					labelOutside
					:label="t('pipelinq', 'Phone')"
					:modelValue="form.phone"
					:error="!!shownErrors.phone"
					:helperText="shownErrors.phone"
					data-testid="client-phone-input"
					@update:modelValue="(v) => (form.phone = v)" />
			</div>
			<div class="form-group">
				<label for="client-website">{{ t('pipelinq', 'Website') }}</label>
				<NcTextField
					id="client-website"
					labelOutside
					:label="t('pipelinq', 'Website')"
					:modelValue="form.website"
					:error="!!shownErrors.website"
					:helperText="shownErrors.website"
					data-testid="client-website-input"
					@update:modelValue="(v) => (form.website = v)" />
			</div>
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="client-industry">{{ t('pipelinq', 'Industry') }}</label>
				<NcSelect
					v-model="form.industry"
					inputId="client-industry"
					:inputLabel="t('pipelinq', 'Industry')"
					labelOutside
					multiple
					:options="industryOptions"
					:placeholder="t('pipelinq', 'Pick sectors')"
					data-testid="client-industry-select" />
			</div>
			<div class="form-group">
				<label for="client-account-owner">{{
					t('pipelinq', 'Account owner')
				}}</label>
				<NcSelect
					v-model="form.accountOwner"
					inputId="client-account-owner"
					:inputLabel="t('pipelinq', 'Account owner')"
					labelOutside
					:options="userOptions"
					:reduce="(option) => option.id"
					label="displayName"
					:filterable="false"
					:loading="searchingUsers"
					:placeholder="t('pipelinq', 'Search a user')"
					data-testid="client-account-owner-select"
					@search="searchUsers" />
			</div>
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="client-language">{{
					t('pipelinq', 'Correspondence language')
				}}</label>
				<NcSelect
					v-model="form.correspondenceLanguage"
					inputId="client-language"
					:inputLabel="t('pipelinq', 'Correspondence language')"
					labelOutside
					:options="languageOptions"
					:reduce="(option) => option.id"
					label="label"
					data-testid="client-language-select" />
			</div>
			<div class="form-group">
				<label for="client-timezone">{{ t('pipelinq', 'Timezone') }}</label>
				<NcSelect
					v-model="form.timezone"
					inputId="client-timezone"
					:inputLabel="t('pipelinq', 'Timezone')"
					labelOutside
					:options="timezoneOptions"
					:placeholder="t('pipelinq', 'Select a timezone')"
					data-testid="client-timezone-select" />
			</div>
		</div>

		<div class="form-group">
			<label for="client-address">{{ t('pipelinq', 'Address') }}</label>
			<NcTextField
				id="client-address"
				labelOutside
				:label="t('pipelinq', 'Address')"
				:modelValue="form.address"
				data-testid="client-address-input"
				@update:modelValue="(v) => (form.address = v)" />
		</div>

		<div class="form-group">
			<label for="client-notes">{{ t('pipelinq', 'Notes') }}</label>
			<textarea
				id="client-notes"
				v-model="form.notes"
				rows="3"
				data-testid="client-notes-input" />
		</div>

		<div v-if="showActions" class="client-form__actions">
			<NcButton
				variant="primary"
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
import axios from '@nextcloud/axios'
import { getLanguage, translate } from '@nextcloud/l10n'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import touchedErrorsMixin from '../../mixins/touchedErrorsMixin.js'
import {
	defaultLanguage,
	INDUSTRY_SECTORS,
	industryList,
} from '../../utils/clientFormFields.js'
import { CLIENT_TYPE_LABELS, enumOptions } from '../../utils/enumLabels.js'

const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const PHONE_REGEX = /^[+]?[\d\s\-().]{7,20}$/
const URL_REGEX = /^https?:\/\/.+\..+/

/**
 * @spec openspec/changes/2026-03-20-client-management/tasks.md#task-3.1
 */
/**
 * The signed-in user, or null.
 *
 * @return {{uid: string, displayName: string}|null} The user.
 * @spec exclude reads the session user, no behaviour of its own.
 */
function currentUser() {
	return window.OC?.getCurrentUser?.() || null
}

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

	mixins: [touchedErrorsMixin],

	props: {
		client: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * Render the built-in Save / Cancel buttons. Set to `false` when the
		 * host supplies its own action buttons (e.g. a parent NcDialog driving
		 * the form via a ref + the `update:valid` event).
		 *
		 * Defaults ON deliberately: a host that supplies its own action bar
		 * opts OUT. Inverting the name would make every ordinary use pass a
		 * negative prop just to get the normal form.
		 */
		/** A name to start a new client with, e.g. what was typed in a picker. */
		initialName: {
			type: String,
			default: '',
		},

		showActions: {
			type: Boolean,
			// eslint-disable-next-line vue/no-boolean-default
			default: true,
		},
	},

	emits: ['cancel', 'save', 'update:valid'],

	data() {
		return {
			form: {
				name: this.initialName || '',
				type: null,
				email: '',
				phone: '',
				website: '',
				address: '',
				notes: '',
				industry: [],
				accountOwner: currentUser()?.uid || null,
				correspondenceLanguage: '',
				timezone: null,
			},

			typeOptions: enumOptions(CLIENT_TYPE_LABELS, (text) =>
				translate('pipelinq', text),
			),

			industryOptions: INDUSTRY_SECTORS,
			languages: [],
			userOptions: currentUser()
				? [
						{
							id: currentUser().uid,
							displayName:
								currentUser().displayName || currentUser().uid,
						},
					]
				: [],

			searchingUsers: false,
			timezoneOptions:
				typeof Intl.supportedValuesOf === 'function'
					? Intl.supportedValuesOf('timeZone')
					: [],
		}
	},

	computed: {
		/**
		 * Derived from the form, like LeadForm, so an empty required field shows
		 * its error from the start rather than only after it is edited.
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-31
		 */
		errors() {
			const errors = {}
			if (!this.form.name.trim()) {
				errors.name = t('pipelinq', 'Name is required')
			} else if (this.form.name.length > 255) {
				errors.name = t('pipelinq', 'Name must be at most 255 characters')
			}
			if (!this.form.type) {
				errors.type = t('pipelinq', 'Type is required')
			}
			if (this.form.email && !EMAIL_REGEX.test(this.form.email)) {
				errors.email = t('pipelinq', 'Invalid email format')
			}
			if (this.form.phone && !PHONE_REGEX.test(this.form.phone)) {
				errors.phone = t('pipelinq', 'Invalid phone format')
			}
			if (this.form.website && !URL_REGEX.test(this.form.website)) {
				errors.website = t('pipelinq', 'Invalid URL format')
			}
			return errors
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-27
		 */
		isValid() {
			return Object.keys(this.errors).length === 0
		},

		/**
		 * The instance's languages, labelled in the user's own language.
		 *
		 * @return {Array<{id: string, label: string}>} The options.
		 * @spec openspec/changes/pipelinq-forms-review/specs/client-management/spec.md
		 */
		languageOptions() {
			let names = null
			try {
				names = new Intl.DisplayNames([getLanguage()], { type: 'language' })
			} catch {
				names = null
			}
			return this.languages.map((tag) => {
				let label = tag
				try {
					label = names?.of(tag.replace('_', '-')) || tag
				} catch {
					// A tag Intl does not know keeps its code as the label.
				}
				return { id: tag, label }
			})
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
			 * @param {object} val The incoming value.
			 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-26
			 */
			handler(val) {
				if (val && Object.keys(val).length > 0) {
					this.populateForm(val)
				}
			},
		},
	},

	mounted() {
		this.loadLanguages()
	},

	methods: {
		/**
		 * @param {object} data The contact to load into the form.
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
				industry: industryList(data.industry),
				accountOwner: data.accountOwner || null,
				correspondenceLanguage: data.correspondenceLanguage || '',
				timezone: data.timezone || null,
			}
		},

		/**
		 * Load the languages the instance can write in, and preselect one
		 * for a new client (D2).
		 *
		 * @spec openspec/changes/pipelinq-forms-review/specs/client-management/spec.md
		 */
		async loadLanguages() {
			try {
				const { data } = await axios.get(
					generateUrl('/apps/pipelinq/api/correspondence-languages'),
				)
				this.languages = Array.isArray(data?.languages) ? data.languages : []
				if (!this.form.correspondenceLanguage && !this.client?.id) {
					this.form.correspondenceLanguage = defaultLanguage(
						this.languages,
						data?.instanceDefault || '',
						getLanguage(),
					)
				}
			} catch {
				this.languages = []
			}
		},

		/**
		 * Search Nextcloud users for the account owner picker (D4).
		 *
		 * @param {string} query What the user typed.
		 * @spec openspec/changes/pipelinq-forms-review/specs/client-management/spec.md
		 */
		async searchUsers(query) {
			if (!query || query.length < 2) {
				return
			}
			this.searchingUsers = true
			try {
				const { data } = await axios.get(
					generateOcsUrl('core/autocomplete/get'),
					{
						params: {
							search: query,
							itemType: 'pipelinq',
							itemId: 'client',
							'shareTypes[]': 0,
							limit: 20,
						},
					},
				)
				this.userOptions = (data?.ocs?.data || []).map((user) => ({
					id: user.id,
					displayName: user.label || user.id,
				}))
			} catch {
				// Keep the options there were; the picker stays usable.
			} finally {
				this.searchingUsers = false
			}
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-28
		 * @spec openspec/changes/2026-03-20-client-management/tasks.md#task-3.1
		 */
		onSave() {
			this.markSaveAttempted()
			if (!this.isValid) {
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
