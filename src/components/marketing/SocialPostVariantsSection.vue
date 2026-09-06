<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - What the post actually says on each network, plus the approval step, as an
  - in-body section (kind:'section') on the declarative SocialPostDetail page.
  -
  - NOT a declarative text widget: that widget renders a literal manifest
  - string, and what has to be shown here is the RESOLVED text per network,
  - which is the post's body with that network's variant merged onto it. Only
  - `resolveVariant()` knows that, and it is the same rule the server applies
  - on the way out.
  -
  - NOT `lifecycleActions` either (ADR-062 rule 10). OpenRegister's transition
  - engine would happily flip `status`, but an approval has to RECORD who
  - decided and when, in the post's `approvals` list, stamped from the session.
  -  That is rule 4 of the marketing architecture and the grammar has no field
  - for it.
  -
  - @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
  -->
<template>
	<div class="social-variants" data-testid="social-variants">
		<NcLoadingIcon v-if="loading" :size="24" />

		<NcNoteCard v-else-if="error" type="error">{{ error }}</NcNoteCard>

		<template v-else-if="post">
			<p v-if="post.agentAuthored" class="social-variants__agent">
				{{
					t('pipelinq', 'Written by an agent: {agent}', {
						agent: post.agentAuthoredBy || '',
					})
				}}
			</p>

			<section
				v-for="fit in fits"
				:key="fit.network"
				class="social-variants__network">
				<h3>{{ fit.label }}</h3>
				<p class="social-variants__body">{{ bodyFor(fit.network) }}</p>
				<p class="social-variants__count">
					{{ fit.length }} / {{ fit.limit }}
				</p>
			</section>

			<p v-if="fits.length === 0" class="social-variants__count">
				{{ t('pipelinq', 'This post names no accounts yet.') }}
			</p>

			<footer class="social-variants__actions">
				<NcButton
					v-if="post.status === 'draft'"
					variant="primary"
					:disabled="busy"
					data-testid="social-variants-submit"
					@click="move('submit')">
					{{ t('pipelinq', 'Submit for approval') }}
				</NcButton>
				<NcButton
					v-if="post.status === 'approval'"
					variant="primary"
					:disabled="busy"
					data-testid="social-variants-approve"
					@click="move('approve')">
					{{ t('pipelinq', 'Approve') }}
				</NcButton>
				<NcButton
					v-if="post.status === 'approval'"
					variant="secondary"
					:disabled="busy"
					data-testid="social-variants-reject"
					@click="move('reject')">
					{{ t('pipelinq', 'Reject') }}
				</NcButton>
			</footer>

			<ul v-if="approvals.length > 0" class="social-variants__approvals">
				<li v-for="(entry, index) in approvals" :key="index">
					{{ entry.userId }} · {{ entry.decision }} ·
					{{ entry.decidedAt }}
				</li>
			</ul>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { fetchAccounts, fetchPost, movePost } from '../../services/socialApi.js'
import { fitsForNetworks, resolveVariant } from '../../services/socialComposer.js'

export default {
	name: 'SocialPostVariantsSection',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},

	inject: {
		cnSectionContext: { default: null },
	},

	props: {
		/** The post id, on the post detail page (`@objectId`). */
		postId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: false,
			busy: false,
			error: '',
			post: null,
			accounts: [],
		}
	},

	computed: {
		/**
		 * @return {Array<string>} The networks this post's accounts live on.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
		 */
		networks() {
			const chosen = Array.isArray(this.post?.accountIds)
				? this.post.accountIds
				: []
			const out = []
			for (const account of this.accounts) {
				const id = account.id || account.uuid || ''
				if (chosen.includes(id) && !out.includes(account.network)) {
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
			return fitsForNetworks(this.post || {}, this.networks)
		},

		/**
		 * @return {Array<object>} The decisions taken on this post.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
		 */
		approvals() {
			return Array.isArray(this.post?.approvals) ? this.post.approvals : []
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * The id this section is bound to.
		 *
		 * @return {string} The post id.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
		 */
		effectiveId() {
			if (this.postId) {
				return this.postId
			}
			const context = this.cnSectionContext || {}
			return context.objectId || context.id || ''
		},

		/**
		 * The resolved text one network gets.
		 *
		 * @param {string} network The network.
		 * @return {string} The text.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
		 */
		bodyFor(network) {
			return resolveVariant(this.post || {}, network).body
		},

		/**
		 * @return {Promise<void>} Resolves once the post and accounts are in.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
		 */
		async load() {
			const id = this.effectiveId()
			if (!id) {
				return
			}

			this.loading = true
			this.error = ''
			try {
				const [post, accounts] = await Promise.all([
					fetchPost(id),
					fetchAccounts(),
				])
				this.post = post
				this.accounts = accounts.data
			} catch {
				this.error = t('pipelinq', 'The post could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Submit, approve or reject.
		 *
		 * @param {string} action One of `submit`, `approve`, `reject`.
		 * @return {Promise<void>} Resolves once moved.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
		 */
		async move(action) {
			this.busy = true
			this.error = ''
			try {
				this.post = await movePost(this.effectiveId(), action)
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('pipelinq', 'That did not work.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.social-variants__network {
	margin-bottom: 16px;
}

.social-variants__body {
	white-space: pre-wrap;
}

.social-variants__agent,
.social-variants__count {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.social-variants__actions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}

.social-variants__approvals {
	margin-top: 12px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
