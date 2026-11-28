<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Add/edit dialog for ONE entry of a client/contact's `socialProfiles[]`
  - array (contact-channel-details). A pure form: it emits the built entry
  - back to the caller (ContactChannelsSection), which splices it into the
  - array and persists the whole client/contact object.
  -
  - @spec openspec/specs/contact-channel-details/spec.md#requirement-channels-are-added-edited-and-removed-through-dedicated-modals
  -->
<template>
	<CnDialog
		width="form"
		:name="
			isEdit
				? t('pipelinq', 'Edit social profile')
				: t('pipelinq', 'Add social profile')
		"
		size="normal"
		@closing="$emit('close')">
		<div class="contact-social-profile-modal">
			<NcSelect
				v-model="form.network"
				inputId="social-network"
				:inputLabel="t('pipelinq', 'Network')"
				:aria-label-combobox="t('pipelinq', 'Network')"
				:options="networkOptions"
				:clearable="false" />
			<NcTextField
				:label="t('pipelinq', 'Handle')"
				:modelValue="form.handle"
				:placeholder="t('pipelinq', 'Without the leading @')"
				@update:modelValue="(v) => (form.handle = v)" />
			<NcTextField
				:label="t('pipelinq', 'Profile URL')"
				:modelValue="form.url"
				:error="!!error"
				:helperText="error"
				@update:modelValue="
					(v) => {
						form.url = v
						error = ''
					}
				" />
			<NcCheckboxRadioSwitch v-model="form.verified" type="switch">
				{{ t('pipelinq', 'Verified') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="form.followedByUs" type="switch">
				{{ t('pipelinq', 'Followed by us') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="form.followsUs" type="switch">
				{{ t('pipelinq', 'Follows us') }}
			</NcCheckboxRadioSwitch>
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" @click="submit">
				{{ t('pipelinq', 'Save') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'

const URL_REGEX = /^https?:\/\/.+\..+/

/** The networks a profile can be on, in picker order. */
const NETWORK_OPTIONS = [
	'linkedin',
	'x',
	'mastodon',
	'bluesky',
	'facebook',
	'instagram',
	'threads',
	'tiktok',
	'youtube',
	'other',
]

/** The profile form's fields in form order, each with its empty value. */
const PROFILE_DEFAULTS = {
	network: 'linkedin',
	handle: '',
	url: '',
	verified: false,
	followedByUs: false,
	followsUs: false,
}

/**
 * The profile form filled from a stored profile, a missing field taking
 * its empty value.
 *
 * @param {object|null} profile The profile being edited, or none.
 * @return {object} The form.
 */
function profileForm(profile) {
	const form = {}
	for (const [field, empty] of Object.entries(PROFILE_DEFAULTS)) {
		form[field] = profile?.[field] || empty
	}
	return form
}

export default {
	name: 'ContactSocialProfileModal',
	components: {
		CnDialog,
		NcButton,
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
	},

	props: {
		/** The profile being edited, or null to add a new one. */
		profile: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'save'],

	data() {
		return {
			networkOptions: [...NETWORK_OPTIONS],

			form: profileForm(this.profile),

			error: '',
		}
	},

	computed: {
		isEdit() {
			return !!this.profile
		},
	},

	methods: {
		/**
		 * Validate and emit the built entry; the parent owns persistence.
		 *
		 * @return {void}
		 * @spec openspec/specs/contact-channel-details/spec.md#requirement-channels-are-added-edited-and-removed-through-dedicated-modals
		 */
		submit() {
			const handle = (this.form.handle || '').trim()
			const url = (this.form.url || '').trim()

			if (!handle && !url) {
				this.error = t('pipelinq', 'A handle or a profile URL is required')
				return
			}
			if (url && !URL_REGEX.test(url)) {
				this.error = t('pipelinq', 'Invalid URL format')
				return
			}

			this.$emit('save', {
				network: this.form.network,
				handle,
				url,
				verified: !!this.form.verified,
				followedByUs: !!this.form.followedByUs,
				followsUs: !!this.form.followsUs,
			})
		},
	},
}
</script>

<style scoped>
.contact-social-profile-modal {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0;
}
</style>
