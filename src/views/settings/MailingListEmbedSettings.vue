<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Admin settings section handing a marketer the signup form to paste into a
  - page. The snippet posts straight at the public subscribe endpoint, so it
  - needs no script and no CORS, and it carries the honeypot field the endpoint
  - checks.
  -
  - It also states the one operational fact about signed links that is easy to
  - learn the hard way: the unsubscribe links printed into already-sent mail
  - are signed with a per-instance key, and the preference centre is the
  - recovery path if that key is ever lost.
  -
  - @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
  -->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'Mailing list signup form')"
		:description="
			t(
				'pipelinq',
				'Paste this form into a page to let people subscribe. They receive a link and are only added once they open it.',
			)
		">
		<NcSelect
			v-model="selected"
			:options="listOptions"
			:inputLabel="t('pipelinq', 'Mailing list')"
			:placeholder="t('pipelinq', 'Choose a list')"
			label="label"
			:loading="loading" />

		<NcNoteCard v-if="selected && !selected.publicSignup" type="warning">
			{{
				t(
					'pipelinq',
					'This list does not accept public signup, so the form will be refused. Turn on public signup on the list first.',
				)
			}}
		</NcNoteCard>

		<label v-if="snippet" for="mailing-list-embed-snippet">
			{{ t('pipelinq', 'Signup form') }}
		</label>
		<textarea
			v-if="snippet"
			id="mailing-list-embed-snippet"
			class="mailing-list-embed__snippet"
			rows="9"
			readonly
			:value="snippet" />

		<p class="mailing-list-embed__note">
			{{
				t(
					'pipelinq',
					'Unsubscribe links are signed with a key held by this instance. Keep it: a lost key breaks every unsubscribe link already sent. The preference centre link is the way back if that happens.',
				)
			}}
		</p>
	</NcSettingsSection>
</template>

<script>
import { NcNoteCard, NcSelect, NcSettingsSection } from '@nextcloud/vue'
import { fetchMailingLists } from '../../services/mailingListApi.js'
import { embedSnippet } from '../../services/subscriptionState.js'

export default {
	name: 'MailingListEmbedSettings',

	components: {
		NcNoteCard,
		NcSelect,
		NcSettingsSection,
	},

	data() {
		return {
			loading: false,
			lists: [],
			selected: null,
		}
	},

	computed: {
		/**
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
		 * @return {Array<object>} The lists, shaped for NcSelect.
		 */
		listOptions() {
			return this.lists.map((list) => ({
				id: this.idOf(list),
				label: list.name || this.idOf(list),
				publicSignup: Boolean(list.publicSignup),
			}))
		},

		/**
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
		 * @return {string} The snippet for the chosen list, or nothing.
		 */
		snippet() {
			if (!this.selected) {
				return ''
			}
			return embedSnippet(window.location.origin, this.selected.id)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load the lists to choose from.
		 *
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
		 * @return {Promise<void>} Resolves when the picker is filled.
		 */
		async load() {
			this.loading = true
			try {
				this.lists = await fetchMailingLists()
			} catch {
				this.lists = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} list A mailing list payload.
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
		 * @return {string} The list id, whichever key carries it.
		 */
		idOf(list) {
			if (!list) {
				return ''
			}
			const self = list['@self'] || {}
			return String(list.id || list.uuid || self.id || self.uuid || '')
		},
	},
}
</script>

<style scoped>
.mailing-list-embed__snippet {
	width: 100%;
	font-family: monospace;
	margin-block-start: 0.5rem;
}

.mailing-list-embed__note {
	color: var(--color-text-maxcontrast);
	margin-block-start: 1rem;
}
</style>
