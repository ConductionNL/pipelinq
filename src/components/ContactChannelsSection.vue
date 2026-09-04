<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Client/contact detail in-body section (kind:'section') for
  - contact-channel-details: a compact list of the entity's typed
  - `emails[]`/`phones[]` (kind chips + mailto:/tel: links) and
  - `socialProfiles[]` (network chip + clickable profile link), with add/
  - edit/remove via ContactEmailPhoneModal / ContactSocialProfileModal.
  -
  - Self-fetches the full client/contact object by entityId/entityType (the
  - array fields need the whole object for a PUT — OpenRegister's object
  - endpoint replaces the full representation, so a partial payload would
  - drop every other field). On save, the legacy scalar `email`/`phone`
  - mirror is recomputed from the primary entry of `emails`/`phones` in
  - THIS save path — ADR-031's `x-openregister-calculations` has no
  - documented grammar for "select the array entry where primary == true"
  - (see design.md, Decisions), so the mirror is kept here rather than
  - declaratively. After a successful save, write-back sync is triggered
  - so the linked Nextcloud Contact vCard picks up the new channels too.
  -
  - @spec openspec/changes/contact-channel-details/specs/contacts-sync/spec.md
  -->
<template>
	<div class="contact-channels-section">
		<NcLoadingIcon v-if="loading" :size="24" />

		<template v-else>
			<p
				v-if="preferencesLine"
				class="contact-channels-section__preferences"
				data-testid="contact-channels-preferences">
				{{ preferencesLine }}
			</p>

			<section class="contact-channels-section__group">
				<div class="contact-channels-section__group-header">
					<h4>{{ t('pipelinq', 'Emails') }}</h4>
					<NcButton
						variant="tertiary"
						data-testid="add-email-button"
						@click="openEmailPhoneModal('email', null)">
						<template #icon>
							<Plus :size="18" />
						</template>
						{{ t('pipelinq', 'Add') }}
					</NcButton>
				</div>
				<p
					v-if="emails.length === 0"
					class="contact-channels-section__empty">
					{{ t('pipelinq', 'No email addresses yet.') }}
				</p>
				<ul v-else class="contact-channels-section__list">
					<li
						v-for="(entry, index) in emails"
						:key="'email-' + index"
						class="contact-channels-section__row">
						<span class="contact-channels-section__kind">{{
							kindLabel(entry.kind)
						}}</span>
						<a
							:href="'mailto:' + entry.value"
							class="contact-channels-section__value"
							>{{ entry.value }}</a
						>
						<Star
							v-if="entry.primary"
							:size="16"
							:title="t('pipelinq', 'Primary')" />
						<CheckCircle
							v-if="entry.verified"
							:size="16"
							:title="t('pipelinq', 'Verified')" />
						<NcButton
							variant="tertiary"
							:aria-label="t('pipelinq', 'Edit')"
							@click="openEmailPhoneModal('email', index)">
							<template #icon>
								<Pencil :size="16" />
							</template>
						</NcButton>
						<NcButton
							variant="tertiary"
							:aria-label="t('pipelinq', 'Remove')"
							@click="removeEntry('emails', index)">
							<template #icon>
								<Delete :size="16" />
							</template>
						</NcButton>
					</li>
				</ul>
			</section>

			<section class="contact-channels-section__group">
				<div class="contact-channels-section__group-header">
					<h4>{{ t('pipelinq', 'Phones') }}</h4>
					<NcButton
						variant="tertiary"
						data-testid="add-phone-button"
						@click="openEmailPhoneModal('phone', null)">
						<template #icon>
							<Plus :size="18" />
						</template>
						{{ t('pipelinq', 'Add') }}
					</NcButton>
				</div>
				<p
					v-if="phones.length === 0"
					class="contact-channels-section__empty">
					{{ t('pipelinq', 'No phone numbers yet.') }}
				</p>
				<ul v-else class="contact-channels-section__list">
					<li
						v-for="(entry, index) in phones"
						:key="'phone-' + index"
						class="contact-channels-section__row">
						<span class="contact-channels-section__kind">{{
							kindLabel(entry.kind)
						}}</span>
						<a
							:href="'tel:' + entry.value"
							class="contact-channels-section__value"
							>{{ entry.value }}</a
						>
						<Star
							v-if="entry.primary"
							:size="16"
							:title="t('pipelinq', 'Primary')" />
						<CheckCircle
							v-if="entry.verified"
							:size="16"
							:title="t('pipelinq', 'Verified')" />
						<NcButton
							variant="tertiary"
							:aria-label="t('pipelinq', 'Edit')"
							@click="openEmailPhoneModal('phone', index)">
							<template #icon>
								<Pencil :size="16" />
							</template>
						</NcButton>
						<NcButton
							variant="tertiary"
							:aria-label="t('pipelinq', 'Remove')"
							@click="removeEntry('phones', index)">
							<template #icon>
								<Delete :size="16" />
							</template>
						</NcButton>
					</li>
				</ul>
			</section>

			<section class="contact-channels-section__group">
				<div class="contact-channels-section__group-header">
					<h4>{{ t('pipelinq', 'Social profiles') }}</h4>
					<NcButton
						variant="tertiary"
						data-testid="add-social-button"
						@click="openSocialModal(null)">
						<template #icon>
							<Plus :size="18" />
						</template>
						{{ t('pipelinq', 'Add') }}
					</NcButton>
				</div>
				<p
					v-if="socialProfiles.length === 0"
					class="contact-channels-section__empty">
					{{ t('pipelinq', 'No social profiles yet.') }}
				</p>
				<ul v-else class="contact-channels-section__list">
					<li
						v-for="(profile, index) in socialProfiles"
						:key="'social-' + index"
						class="contact-channels-section__row">
						<span class="contact-channels-section__kind">{{
							networkLabel(profile.network)
						}}</span>
						<a
							v-if="profile.url"
							:href="profile.url"
							target="_blank"
							rel="noopener noreferrer"
							class="contact-channels-section__value"
							>{{ profile.handle || profile.url }}</a
						>
						<span v-else class="contact-channels-section__value">{{
							profile.handle
						}}</span>
						<CheckCircle
							v-if="profile.verified"
							:size="16"
							:title="t('pipelinq', 'Verified')" />
						<span
							v-if="profile.followedByUs"
							class="contact-channels-section__badge"
							>{{ t('pipelinq', 'Followed by us') }}</span
						>
						<span
							v-if="profile.followsUs"
							class="contact-channels-section__badge"
							>{{ t('pipelinq', 'Follows us') }}</span
						>
						<NcButton
							variant="tertiary"
							:aria-label="t('pipelinq', 'Edit')"
							@click="openSocialModal(index)">
							<template #icon>
								<Pencil :size="16" />
							</template>
						</NcButton>
						<NcButton
							variant="tertiary"
							:aria-label="t('pipelinq', 'Remove')"
							@click="removeEntry('socialProfiles', index)">
							<template #icon>
								<Delete :size="16" />
							</template>
						</NcButton>
					</li>
				</ul>
			</section>
		</template>

		<ContactEmailPhoneModal
			v-if="emailPhoneModal.open"
			:entry="emailPhoneModal.entry"
			:channelType="emailPhoneModal.channelType"
			@close="emailPhoneModal.open = false"
			@save="onEmailPhoneSaved" />

		<ContactSocialProfileModal
			v-if="socialModal.open"
			:profile="socialModal.profile"
			@close="socialModal.open = false"
			@save="onSocialProfileSaved" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Star from 'vue-material-design-icons/Star.vue'
import ContactEmailPhoneModal from '../modals/ContactEmailPhoneModal.vue'
import ContactSocialProfileModal from '../modals/ContactSocialProfileModal.vue'
import { writeBack } from '../services/contactSyncApi.js'
import { useObjectStore } from '../store/modules/object.js'

const KIND_LABELS = {
	work: () => t('pipelinq', 'Work'),
	private: () => t('pipelinq', 'Private'),
	mobile: () => t('pipelinq', 'Mobile'),
	whatsapp: () => t('pipelinq', 'WhatsApp'),
	other: () => t('pipelinq', 'Other'),
}

const NETWORK_LABELS = {
	linkedin: 'LinkedIn',
	x: 'X',
	mastodon: 'Mastodon',
	bluesky: 'Bluesky',
	facebook: 'Facebook',
	instagram: 'Instagram',
	threads: 'Threads',
	tiktok: 'TikTok',
	youtube: 'YouTube',
	other: () => t('pipelinq', 'Other'),
}

export default {
	name: 'ContactChannelsSection',
	components: {
		NcButton,
		NcLoadingIcon,
		ContactEmailPhoneModal,
		ContactSocialProfileModal,
		Plus,
		Pencil,
		Delete,
		Star,
		CheckCircle,
	},

	inject: {
		cnSectionContext: { default: null },
	},

	props: {
		/** The client/contact id (token-resolved from `@objectId`). */
		entityId: {
			type: String,
			default: '',
		},

		/** 'client' or 'contact' — the registered object-store type. */
		entityType: {
			type: String,
			required: true,
			validator: (v) => ['client', 'contact'].includes(v),
		},
	},

	data() {
		return {
			loading: false,
			entity: null,
			emailPhoneModal: {
				open: false,
				entry: null,
				index: null,
				channelType: 'email',
			},

			socialModal: { open: false, profile: null, index: null },
		}
	},

	computed: {
		objectStore() {
			return useObjectStore()
		},

		/** The resolved entity id — prop wins, else the injected section context. */
		resolvedId() {
			if (this.entityId) {
				return this.entityId
			}
			const ctx = this.cnSectionContext
			const bag =
				ctx && typeof ctx === 'object' && 'value' in ctx ? ctx.value : ctx
			return (bag && bag.objectId) || ''
		},

		emails() {
			return (this.entity && this.entity.emails) || []
		},

		phones() {
			return (this.entity && this.entity.phones) || []
		},

		socialProfiles() {
			return (this.entity && this.entity.socialProfiles) || []
		},

		/**
		 * A compact "preferred channel / timezone / language" summary
		 * line. These three plain scalar fields are edited through the
		 * ordinary schema-driven form, not here — this is a read-only
		 * summary for context alongside the channel lists.
		 *
		 * @return {string} The summary, or '' when nothing is set.
		 */
		preferencesLine() {
			if (!this.entity) {
				return ''
			}
			const parts = []
			if (this.entity.preferredChannel) {
				parts.push(
					t('pipelinq', 'Prefers {channel}', {
						channel: this.preferredChannelLabel(
							this.entity.preferredChannel,
						),
					}),
				)
			}
			if (this.entity.timezone) {
				parts.push(this.entity.timezone)
			}
			if (this.entity.language) {
				parts.push(this.entity.language.toUpperCase())
			}
			return parts.join(' · ')
		},
	},

	watch: {
		resolvedId: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/**
		 * Fetch the full client/contact object.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/contact-channel-details/specs/contacts-sync/spec.md
		 */
		async load() {
			if (!this.resolvedId) {
				this.entity = null
				return
			}
			this.loading = true
			try {
				this.entity = await this.objectStore.fetchObject(
					this.entityType,
					this.resolvedId,
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {string} kind One of the `kind` enum values.
		 * @return {string} The localised label.
		 */
		kindLabel(kind) {
			const fn = KIND_LABELS[kind]
			return fn ? fn() : kind
		},

		/**
		 * @param {string} channel One of `preferredChannel`'s enum values
		 *   (email/phone/whatsapp/linkedin/x/mastodon/bluesky/other) — a
		 *   different vocabulary from `kind`/`network`, so it gets its own
		 *   label lookup rather than reusing either.
		 * @return {string} The localised or proper-noun label.
		 */
		preferredChannelLabel(channel) {
			if (channel === 'email') {
				return t('pipelinq', 'Email')
			}
			if (channel === 'phone') {
				return t('pipelinq', 'Phone')
			}
			if (channel === 'whatsapp') {
				return t('pipelinq', 'WhatsApp')
			}
			return this.networkLabel(channel)
		},

		/**
		 * @param {string} network One of the `network` enum values.
		 * @return {string} The label (network names are proper nouns, not translated).
		 */
		networkLabel(network) {
			const label = NETWORK_LABELS[network]
			if (typeof label === 'function') {
				return label()
			}
			return label || network
		},

		/**
		 * @param {string} channelType 'email' or 'phone'.
		 * @param {number|null} index The entry index to edit, or null to add.
		 * @return {void}
		 */
		openEmailPhoneModal(channelType, index) {
			const list = channelType === 'email' ? this.emails : this.phones
			this.emailPhoneModal = {
				open: true,
				channelType,
				index,
				entry: index === null ? null : list[index],
			}
		},

		/**
		 * @param {number|null} index The entry index to edit, or null to add.
		 * @return {void}
		 */
		openSocialModal(index) {
			this.socialModal = {
				open: true,
				index,
				profile: index === null ? null : this.socialProfiles[index],
			}
		},

		/**
		 * @param {object} entry The built entry from ContactEmailPhoneModal.
		 * @return {Promise<void>}
		 */
		async onEmailPhoneSaved(entry) {
			const { channelType, index } = this.emailPhoneModal
			const arrayKey = channelType === 'email' ? 'emails' : 'phones'
			const list = [...(this.entity[arrayKey] || [])]

			if (entry.primary) {
				list.forEach((e) => {
					e.primary = false
				})
			}

			if (index === null) {
				list.push(entry)
			} else {
				list.splice(index, 1, entry)
			}

			this.emailPhoneModal.open = false
			await this.persist({ [arrayKey]: list })
		},

		/**
		 * @param {object} profile The built entry from ContactSocialProfileModal.
		 * @return {Promise<void>}
		 */
		async onSocialProfileSaved(profile) {
			const { index } = this.socialModal
			const list = [...(this.entity.socialProfiles || [])]

			if (index === null) {
				list.push(profile)
			} else {
				list.splice(index, 1, profile)
			}

			this.socialModal.open = false
			await this.persist({ socialProfiles: list })
		},

		/**
		 * @param {'emails'|'phones'|'socialProfiles'} arrayKey Which array.
		 * @param {number} index The entry index to drop.
		 * @return {Promise<void>}
		 */
		async removeEntry(arrayKey, index) {
			const list = [...(this.entity[arrayKey] || [])]
			list.splice(index, 1)
			await this.persist({ [arrayKey]: list })
		},

		/**
		 * Merge a channel-array patch onto the full entity and save it.
		 * `email`/`phone` are recomputed from the primary entry of
		 * `emails`/`phones` here — the save path decided in design.md,
		 * since ADR-031 has no declarative "mirror the primary array
		 * entry" grammar. Falls back to the first entry when none is
		 * marked primary, and clears the scalar when the array is empty.
		 *
		 * After a successful save, write-back sync is triggered so the
		 * linked Nextcloud Contact vCard picks up the new channels too
		 * (contacts-sync spec, write-back requirement); a sync failure is
		 * logged server-side and never blocks the save the user sees.
		 *
		 * @param {object} patch The array field(s) being changed.
		 * @return {Promise<void>}
		 * @spec openspec/changes/contact-channel-details/specs/contacts-sync/spec.md
		 */
		async persist(patch) {
			const merged = { ...this.entity, ...patch }

			if ('emails' in patch) {
				const primary =
					merged.emails.find((e) => e.primary) || merged.emails[0]
				merged.email = primary ? primary.value : ''
			}
			if ('phones' in patch) {
				const primary =
					merged.phones.find((e) => e.primary) || merged.phones[0]
				merged.phone = primary ? primary.value : ''
			}

			const saved = await this.objectStore.saveObject(this.entityType, merged)
			if (!saved) {
				const error = this.objectStore.getError?.(this.entityType)
				showError(
					error?.message
						|| t('pipelinq', 'Failed to save contact channels.'),
				)
				return
			}

			this.entity = saved
			showSuccess(t('pipelinq', 'Contact channels saved.'))
			writeBack(this.entityType, this.resolvedId)
		},
	},
}
</script>

<style scoped>
.contact-channels-section {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.contact-channels-section__group-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.contact-channels-section__group-header h4 {
	margin: 0;
	font-weight: 600;
}

.contact-channels-section__empty {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 4px 0;
}

.contact-channels-section__preferences {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0;
}

.contact-channels-section__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.contact-channels-section__row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
}

.contact-channels-section__kind {
	flex-shrink: 0;
	min-width: 64px;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 16px);
	background-color: var(--color-background-dark);
	font-size: 12px;
	text-align: center;
}

.contact-channels-section__value {
	flex: 1;
	color: var(--color-main-text);
	word-break: break-word;
}

.contact-channels-section__badge {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 16px);
	padding: 1px 8px;
}
</style>
