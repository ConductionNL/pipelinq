<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<NcModal :name="t('pipelinq', 'Missing consent')" @close="$emit('cancel')">
		<div class="missing-consent">
			<h2 class="missing-consent__title">
				{{ t('pipelinq', 'Missing consent') }}
			</h2>
			<p class="missing-consent__intro">
				{{ summary }}
			</p>
			<ul v-if="contacts.length > 0" class="missing-consent__list">
				<li
					v-for="(contact, idx) in displayContacts"
					:key="contact.id || idx">
					<span class="missing-consent__name">
						{{
							contact.name
							|| contact.email
							|| contact.phone
							|| t('pipelinq', 'Unknown contact')
						}}
					</span>
					<span v-if="contact.reason" class="missing-consent__reason">
						{{ contact.reason }}
					</span>
				</li>
			</ul>
			<p v-if="hiddenCount > 0" class="missing-consent__more">
				{{
					n(
						'pipelinq',
						'%n more contact not shown',
						'%n more contacts not shown',
						hiddenCount,
					)
				}}
			</p>

			<div class="missing-consent__actions">
				<NcButton variant="tertiary" @click="$emit('cancel')">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton variant="secondary" @click="$emit('requestConsent')">
					{{ t('pipelinq', 'Request consent') }}
				</NcButton>
				<NcButton variant="primary" @click="$emit('skipAndSend')">
					{{ t('pipelinq', 'Skip and send') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'

const MAX_VISIBLE = 25

export default {
	name: 'MissingConsentModal',
	components: {
		NcModal,
		NcButton,
	},

	props: {
		contacts: {
			type: Array,
			default: () => [],
		},

		channel: {
			type: String,
			default: 'email',
		},
	},

	emits: ['cancel', 'requestConsent', 'skipAndSend'],
	computed: {
		/**
		 * Trimmed list of contacts shown in the modal (avoids huge DOM trees).
		 *
		 * @return {Array<object>}
		 */
		displayContacts() {
			return this.contacts.slice(0, MAX_VISIBLE)
		},

		/**
		 * Count of contacts not shown in the visible list.
		 *
		 * @return {number}
		 */
		hiddenCount() {
			return Math.max(0, this.contacts.length - MAX_VISIBLE)
		},

		/**
		 * Localised summary line for the modal body.
		 *
		 * @return {string}
		 */
		summary() {
			const channel =
				this.channel === 'sms'
					? this.t('pipelinq', 'SMS')
					: this.t('pipelinq', 'email')
			return this.n(
				'pipelinq',
				'%n contact in this segment is missing {channel} consent. Choose how to proceed.',
				'%n contacts in this segment are missing {channel} consent. Choose how to proceed.',
				this.contacts.length,
				{ channel },
			)
		},
	},
}
</script>

<style scoped>
.missing-consent {
	padding: 20px;
	max-width: 560px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.missing-consent__title {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.missing-consent__intro {
	margin: 0;
	color: var(--color-main-text);
}

.missing-consent__list {
	margin: 0;
	padding-inline-start: 20px;
	max-height: 240px;
	overflow-y: auto;
}

.missing-consent__name {
	font-weight: 600;
}

.missing-consent__reason {
	color: var(--color-text-lighter);
	margin-inline-start: 6px;
}

.missing-consent__more {
	margin: 0;
	color: var(--color-text-lighter);
	font-style: italic;
}

.missing-consent__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-top: 8px;
}
</style>
