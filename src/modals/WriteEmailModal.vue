<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Write email (messaging-saved-replies-and-resend, D7). Opened from the
  - Write email button next to an address in ContactChannelsSection. The agent
  - types a subject and picks a saved reply for email; Open in Mail opens the
  - Nextcloud Mail composer in a new tab with the address, subject and text
  - filled in. pipelinq sends no mail itself. When the Mail app is not enabled
  - (`dependency_statuses.mail.enabled`), the same values go out as a plain
  - `mailto:` link.
  -->
<template>
	<CnDialog
		width="form"
		:name="t('pipelinq', 'Write email')"
		data-testid="write-email-modal"
		@closing="$emit('close')">
		<div class="write-email">
			<p class="write-email__to">
				{{ t('pipelinq', 'To {address}', { address }) }}
			</p>
			<NcTextField v-model="subject" :label="t('pipelinq', 'Subject')" />
			<SavedReplyPicker
				channel="email"
				:language="language"
				:values="placeholderValues"
				@pick="body = $event" />
			<NcTextArea
				v-model="body"
				:label="t('pipelinq', 'Message')"
				resize="vertical" />
			<p class="write-email__hint">
				{{
					mailEnabled
						? t(
								'pipelinq',
								'The email opens in Mail, where you check it and send it.',
							)
						: t(
								'pipelinq',
								'The Mail app is not enabled, so the email opens in your own mail program.',
							)
				}}
			</p>
		</div>
		<template #actions>
				<NcButton @click="$emit('close')">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="write-email-open"
					@click="open">
					{{
						mailEnabled
							? t('pipelinq', 'Open in Mail')
							: t('pipelinq', 'Open in your mail program')
					}}
				</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcTextArea, NcTextField } from '@nextcloud/vue'
import SavedReplyPicker from '../components/SavedReplyPicker.vue'
import { composeUrl } from '../services/savedReplies.js'

export default {
	name: 'WriteEmailModal',
	components: {
		NcButton,
		CnDialog,
		NcTextArea,
		NcTextField,
		SavedReplyPicker,
	},

	props: {
		/** The recipient address. */
		address: {
			type: String,
			required: true,
		},

		/** Placeholder values for a saved reply, e.g. `{'contact.name': 'Jan'}`. */
		placeholderValues: {
			type: Object,
			default: () => ({}),
		},

		/** The party's correspondence language; its saved replies sort first. */
		language: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			subject: '',
			body: '',
		}
	},

	computed: {
		/**
		 * Whether the Nextcloud Mail app is enabled.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/messaging-saved-replies/spec.md#requirement-an-agent-starts-an-email-from-a-saved-reply-req-msr-005
		 */
		mailEnabled() {
			let statuses
			try {
				statuses = loadState('pipelinq', 'dependency_statuses', {}) || {}
			} catch {
				statuses = {}
			}
			return !!(statuses.mail && statuses.mail.enabled)
		},

		/**
		 * The URL Open in Mail opens.
		 *
		 * @return {string}
		 * @spec openspec/specs/messaging-saved-replies/spec.md#requirement-an-agent-starts-an-email-from-a-saved-reply-req-msr-005
		 */
		url() {
			return composeUrl(
				this.address,
				this.subject.trim(),
				this.body,
				this.mailEnabled,
				generateUrl,
			)
		},
	},

	methods: {
		t,

		/**
		 * Open the composer (new tab) or the mail program, then close.
		 *
		 * @spec openspec/specs/messaging-saved-replies/spec.md#requirement-an-agent-starts-an-email-from-a-saved-reply-req-msr-005
		 */
		open() {
			if (this.mailEnabled) {
				window.open(this.url, '_blank', 'noopener')
			} else {
				window.location.href = this.url
			}
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.write-email {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.write-email__title {
	margin: 0;
	font-size: 1.2em;
}

.write-email__to,
.write-email__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.write-email__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
