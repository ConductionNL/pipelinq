<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Write one post for several networks. Lives in its own file because every
  - modal does (ADR-004).
  -
  - The one idea worth knowing: the body applies everywhere, and a variant
  - overrides only the fields it carries. That merge is `resolveVariant()` in
  - src/services/socialComposer.js, which is the same rule
  - `SocialPostService::resolveVariant()` applies on the way to a network, so
  - the per-network preview and character count below are produced by the rule
  - that will do the sending rather than by a second guess at it.
  -
  - Submitting puts the post up for APPROVAL, never straight into the queue.
  - Rule 4 of the marketing architecture: agents propose, people dispose, and
  - a publish is a gated action with a recorded human decision.
  -
  - @spec openspec/changes/social-publishing/specs/marketing-ui/spec.md#requirement-a-marketer-composes-one-post-for-several-networks
  -->
<template>
	<NcModal :name="modalTitle" size="large" @close="$emit('close')">
		<div class="social-compose">
			<h2>{{ modalTitle }}</h2>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<label class="social-compose__label" for="social-compose-title">
				{{ t('pipelinq', 'Title') }}
			</label>
			<input
				id="social-compose-title"
				v-model="model.title"
				type="text"
				class="social-compose__input"
				data-testid="social-compose-title"
				:placeholder="t('pipelinq', 'Internal name, never published')" />

			<label class="social-compose__label" for="social-compose-body">
				{{ t('pipelinq', 'Body') }} *
			</label>
			<textarea
				id="social-compose-body"
				v-model="model.body"
				class="social-compose__textarea"
				rows="6"
				data-testid="social-compose-body"
				:placeholder="
					t('pipelinq', 'What this post says on every network')
				" />

			<label class="social-compose__label" for="social-compose-link">
				{{ t('pipelinq', 'Link') }}
			</label>
			<input
				id="social-compose-link"
				v-model="model.link"
				type="text"
				class="social-compose__input"
				:placeholder="
					t(
						'pipelinq',
						'The campaign parameters are added when it goes out',
					)
				" />

			<NcSelect
				v-model="chosenAccounts"
				:options="accountOptions"
				:inputLabel="t('pipelinq', 'Accounts')"
				label="label"
				:multiple="true"
				:reduce="(option) => option.value"
				class="social-compose__accounts" />

			<label class="social-compose__label" for="social-compose-schedule">
				{{ t('pipelinq', 'Scheduled for') }}
			</label>
			<input
				id="social-compose-schedule"
				v-model="model.scheduledFor"
				type="datetime-local"
				class="social-compose__input" />

			<section v-if="fits.length > 0" class="social-compose__variants">
				<h3>{{ t('pipelinq', 'Per network') }}</h3>
				<div
					v-for="fit in fits"
					:key="fit.network"
					class="social-compose__variant">
					<label
						class="social-compose__label"
						:for="'social-compose-variant-' + fit.network">
						{{ fit.label }}
					</label>
					<textarea
						:id="'social-compose-variant-' + fit.network"
						:value="variantBody(fit.network)"
						class="social-compose__textarea"
						rows="3"
						:data-testid="'social-compose-variant-' + fit.network"
						:placeholder="
							t('pipelinq', 'Leave empty to use the body above')
						"
						@input="setVariantBody(fit.network, $event.target.value)" />
					<p
						class="social-compose__count"
						:class="{ 'social-compose__count--over': !fit.fits }"
						:data-testid="'social-compose-count-' + fit.network">
						{{ fit.length }} / {{ fit.limit }}
					</p>
				</div>
			</section>

			<NcNoteCard
				v-if="problems.length > 0"
				type="warning"
				data-testid="social-compose-problems">
				<p v-for="problem in problems" :key="problem">{{ problem }}</p>
			</NcNoteCard>

			<footer class="social-compose__actions">
				<NcButton
					variant="tertiary"
					:disabled="saving"
					@click="$emit('close')">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="secondary"
					:disabled="saving"
					data-testid="social-compose-save"
					@click="save(false)">
					{{ t('pipelinq', 'Save draft') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="saving || !canSubmitPost"
					data-testid="social-compose-submit"
					@click="save(true)">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="16" />
					</template>
					{{ t('pipelinq', 'Submit for approval') }}
				</NcButton>
			</footer>
		</div>
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import {
	createPost,
	fetchAccounts,
	movePost,
	updatePost,
} from '../services/socialApi.js'
import { canSubmit, fitsForNetworks } from '../services/socialComposer.js'

export default {
	name: 'SocialPostComposeModal',

	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcSelect,
	},

	props: {
		/** The post being edited, or null to write a new one. */
		post: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'saved'],

	data() {
		const source = this.post || {}
		return {
			saving: false,
			error: '',
			accounts: [],
			chosenAccounts: Array.isArray(source.accountIds)
				? [...source.accountIds]
				: [],

			model: {
				title: source.title || '',
				body: source.body || '',
				link: source.link || '',
				scheduledFor: source.scheduledFor || '',
				variants: { ...(source.variants || {}) },
				media: Array.isArray(source.media) ? [...source.media] : [],
			},
		}
	},

	computed: {
		/**
		 * @return {string} The modal heading.
		 * @spec openspec/changes/social-publishing/specs/marketing-ui/spec.md#requirement-a-marketer-composes-one-post-for-several-networks
		 */
		modalTitle() {
			return this.post
				? t('pipelinq', 'Edit post')
				: t('pipelinq', 'Compose a post')
		},

		/**
		 * @return {Array<object>} One option per account the composer may pick.
		 * @spec openspec/changes/social-publishing/specs/marketing-ui/spec.md#requirement-a-marketer-composes-one-post-for-several-networks
		 */
		accountOptions() {
			return this.accounts.map((account) => ({
				value: account.id || account.uuid || '',
				label: `${account.displayName || account.handle} (${account.network})`,
			}))
		},

		/**
		 * @return {Array<string>} The distinct networks the chosen accounts live on.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
		 */
		chosenNetworks() {
			const out = []
			for (const account of this.accounts) {
				const id = account.id || account.uuid || ''
				if (!this.chosenAccounts.includes(id)) {
					continue
				}
				if (!out.includes(account.network)) {
					out.push(account.network)
				}
			}
			return out
		},

		/**
		 * @return {Array<object>} The per-network fit, from the shared rule.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
		 */
		fits() {
			return fitsForNetworks(this.model, this.chosenNetworks)
		},

		/**
		 * @return {Array<string>} What stops this post being submitted.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
		 */
		problems() {
			return canSubmit(this.model, this.chosenNetworks).problems
		},

		/**
		 * @return {boolean} Whether the post may go up for approval.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
		 */
		canSubmitPost() {
			return this.problems.length === 0
		},
	},

	mounted() {
		this.loadAccounts()
	},

	methods: {
		/**
		 * The accounts a post may be sent to.
		 *
		 * @return {Promise<void>} Resolves once the list is in.
		 * @spec openspec/changes/social-publishing/specs/marketing-ui/spec.md#requirement-a-marketer-composes-one-post-for-several-networks
		 */
		async loadAccounts() {
			try {
				const answer = await fetchAccounts()
				this.accounts = answer.data.filter(
					(account) => account.active !== false,
				)
			} catch {
				this.error = t('pipelinq', 'The accounts could not be loaded.')
			}
		},

		/**
		 * The variant body for one network, or an empty string.
		 *
		 * @param {string} network The network.
		 * @return {string} The variant body.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
		 */
		variantBody(network) {
			return this.model.variants?.[network]?.body || ''
		},

		/**
		 * Set, or clear, one network's variant body.
		 *
		 * An emptied variant is REMOVED rather than stored as an empty string,
		 * so the network falls back to the post's own body the way the merge
		 * rule intends.
		 *
		 * @param {string} network The network.
		 * @param {string} value The typed text.
		 * @return {void}
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
		 */
		setVariantBody(network, value) {
			const variants = { ...this.model.variants }
			if (String(value || '').trim() === '') {
				delete variants[network]
			} else {
				variants[network] = { ...(variants[network] || {}), body: value }
			}
			this.model = { ...this.model, variants }
		},

		/**
		 * Save the post, and optionally put it up for approval.
		 *
		 * @param {boolean} submit Whether to submit it after saving.
		 * @return {Promise<void>} Resolves once saved.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
		 */
		async save(submit) {
			this.saving = true
			this.error = ''

			const payload = { ...this.model, accountIds: this.chosenAccounts }
			try {
				const existingId = this.post?.id || this.post?.uuid || ''
				let saved = existingId
					? await updatePost(existingId, payload)
					: await createPost(payload)

				const id = saved?.id || saved?.uuid || ''
				if (submit && id) {
					saved = await movePost(id, 'submit')
				}

				this.$emit('saved', saved)
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('pipelinq', 'The post could not be saved.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.social-compose {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.social-compose__label {
	font-weight: bold;
	margin-top: 8px;
}

.social-compose__input,
.social-compose__textarea {
	width: 100%;
}

.social-compose__variant {
	margin-bottom: 12px;
}

.social-compose__count {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 4px 0 0;
}

.social-compose__count--over {
	color: var(--color-error);
	font-weight: bold;
}

.social-compose__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 16px;
}
</style>
