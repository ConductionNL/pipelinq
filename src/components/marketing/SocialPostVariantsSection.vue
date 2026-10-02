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
		<NcLoadingIcon v-if="loading && !post" :size="24" />

		<NcNoteCard v-else-if="error" type="error">{{ error }}</NcNoteCard>

		<template v-else-if="post">
			<NcNoteCard
				v-if="post.agentAuthored"
				type="info"
				class="social-variants__agent">
				{{
					t('pipelinq', 'Written by an agent: {agent}', {
						agent: post.agentAuthoredBy || '',
					})
				}}
			</NcNoteCard>

			<article
				v-for="fit in fits"
				:key="fit.network"
				class="social-variants__card">
				<header class="social-variants__card-header">
					<span class="social-variants__icon" aria-hidden="true">
						<component :is="networkIcon(fit.network)" :size="20" />
					</span>
					<h3 class="social-variants__network">{{ fit.label }}</h3>
					<span
						class="social-variants__count"
						:class="{ 'social-variants__count--over': !fit.fits }">
						{{ fit.length }} / {{ fit.limit }}
					</span>
				</header>
				<p class="social-variants__body">{{ bodyFor(fit.network) }}</p>
			</article>

			<template v-if="fits.length === 0">
				<!-- With no account chosen there is no network to resolve the text
					for, so the post's own text is shown as it stands. -->
				<article v-if="post.body" class="social-variants__card">
					<header class="social-variants__card-header">
						<h3 class="social-variants__network">
							{{ t('pipelinq', 'Text') }}
						</h3>
					</header>
					<p class="social-variants__body">{{ post.body }}</p>
				</article>
				<p class="social-variants__hint">
					{{ t('pipelinq', 'This post names no accounts yet.') }}
				</p>
			</template>

			<section v-if="approvals.length > 0" class="social-variants__approvals">
				<h3 class="social-variants__approvals-title">
					{{ t('pipelinq', 'Approvals') }}
				</h3>
				<ul class="social-variants__approvals-list">
					<li
						v-for="(entry, index) in approvals"
						:key="index"
						class="social-variants__approval">
						<span
							class="social-variants__decision"
							:class="'social-variants__decision--' + entry.decision">
							{{ decisionLabel(entry.decision) }}
						</span>
						<span class="social-variants__approval-meta">
							{{ entry.userId }} · {{ formatDate(entry.decidedAt) }}
						</span>
						<span
							v-if="entry.note"
							class="social-variants__approval-note">
							{{ entry.note }}
						</span>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script>
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { fetchAccounts, fetchPost } from '../../services/socialApi.js'
import { fitsForNetworks, resolveVariant } from '../../services/socialComposer.js'
import { networkIcon } from '../../services/socialNetworkIcons.js'

export default {
	name: 'SocialPostVariantsSection',

	components: {
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

	/**
	 * Load the post and follow page refreshes.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	mounted() {
		this.load()
		// The approval step lives in the page header, which bumps this after
		// a move, so the status and the approvals list re-read here.
		subscribe('cn:page:refresh', this.load)
	},

	beforeUnmount() {
		unsubscribe('cn:page:refresh', this.load)
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
		 * The icon a variant's network is shown with.
		 *
		 * @param {string} network The network.
		 * @return {object} Its icon component.
		 *
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
		 */
		networkIcon(network) {
			return networkIcon(network)
		},

		/**
		 * A decision in words.
		 *
		 * @param {string} decision The stored decision.
		 * @return {string} Its label, or the value itself when unknown.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
		 */
		decisionLabel(decision) {
			const labels = {
				approved: t('pipelinq', 'Approved'),
				rejected: t('pipelinq', 'Rejected'),
			}
			return labels[decision] || decision
		},

		/**
		 * Format an approval's timestamp for the reader.
		 *
		 * @param {string} value An ISO timestamp.
		 * @return {string} It in the reader's locale, or as stored when unreadable.
		 *
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
		 */
		formatDate(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? value || '' : date.toLocaleString()
		},
	},
}
</script>

<style scoped>
.social-variants {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.social-variants__agent {
	margin: 0;
}

.social-variants__card {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 14px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.social-variants__card-header {
	display: flex;
	align-items: center;
	gap: 10px;
}

.social-variants__icon {
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	width: 32px;
	height: 32px;
	border-radius: 50%;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.social-variants__network {
	flex: 1;
	margin: 0;
	font-size: 1em;
}

.social-variants__count {
	padding: 2px 10px;
	border-radius: 999px;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	font-variant-numeric: tabular-nums;
}

.social-variants__count--over {
	background: var(--color-error);
	color: var(--color-primary-element-text);
}

.social-variants__body {
	margin: 0;
	white-space: pre-wrap;
}

.social-variants__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.social-variants__approvals {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.social-variants__approvals-title {
	margin: 0;
	font-size: 1em;
}

.social-variants__approvals-list {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.social-variants__approval {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 4px 10px;
}

.social-variants__decision {
	font-weight: 600;
}

.social-variants__decision--approved {
	color: var(--color-success-text, var(--color-success));
}

.social-variants__decision--rejected {
	color: var(--color-error-text, var(--color-error));
}

.social-variants__approval-meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.social-variants__approval-note {
	flex-basis: 100%;
	font-size: 0.9em;
}
</style>
