<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Add/edit dialog for ONE entry of a client/contact's `socialProfiles[]`
  - array (contact-channel-details). A pure form: it emits the built entry
  - back to the caller (ContactChannelsSection), which splices it into the
  - array and persists the whole client/contact object.
  -
  - @spec openspec/changes/contact-channel-details/specs/contact-channel-details/spec.md#requirement-channels-are-added-edited-and-removed-through-dedicated-modals
  -->
<template>
	<NcDialog
		:name="
			isEdit
				? t('pipelinq', 'Edit social profile')
				: t('pipelinq', 'Add social profile')
		"
		:open="true"
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
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'

const URL_REGEX = /^https?:\/\/.+\..+/

export default {
	name: 'ContactSocialProfileModal',
	components: {
		NcDialog,
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
			networkOptions: [
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
			],

			form: {
				network: this.profile?.network || 'linkedin',
				handle: this.profile?.handle || '',
				url: this.profile?.url || '',
				verified: this.profile?.verified || false,
				followedByUs: this.profile?.followedByUs || false,
				followsUs: this.profile?.followsUs || false,
			},

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
		 * @spec openspec/changes/contact-channel-details/specs/contact-channel-details/spec.md#requirement-channels-are-added-edited-and-removed-through-dedicated-modals
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
