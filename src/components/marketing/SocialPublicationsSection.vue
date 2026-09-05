<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - What happened per account, as an in-body section (kind:'section') on the
  - declarative SocialPostDetail page. Self-fetches
  - GET /api/social-posts/{id}/publications.
  -
  - THIS IS WHERE A FAILURE BECOMES VISIBLE. A post to five accounts that
  - reached three is three publications and two failures, each carrying the
  - reason that produced it. Two of the six failure codes can be helped by
  - trying again and four cannot, so the Retry button appears only on the two:
  - a Retry on a dead grant or an unfiled developer application is a button
  - that cannot work, and offering it would send a marketer round a loop
  - instead of to the Reconnect they need.
  -
  - The share path is here too. An account no application may post to shows
  - the prepared text, a copy action and a link into the network's own
  - composer, and the person confirms when they have posted it.
  -
  - @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
  -->
<template>
	<div class="social-publications" data-testid="social-publications">
		<NcLoadingIcon v-if="loading" :size="24" />

		<NcNoteCard v-else-if="error" type="error">{{ error }}</NcNoteCard>

		<template v-else>
			<p v-if="rows.length === 0" class="social-publications__empty">
				{{ t('pipelinq', 'This post has not gone out yet.') }}
			</p>

			<ul v-else class="social-publications__list">
				<li
					v-for="row in rows"
					:key="rowId(row)"
					class="social-publications__row"
					data-testid="social-publication-row">
					<div class="social-publications__identity">
						<strong>{{ networkLabel(row.network) }}</strong>
						<a
							v-if="row.url"
							:href="row.url"
							target="_blank"
							rel="noopener noreferrer">
							{{ t('pipelinq', 'Open what went out') }}
						</a>
					</div>

					<div class="social-publications__state">
						<span :style="{ color: chip(row.status).color }">
							{{ chip(row.status).label }}
						</span>
						<span
							v-if="row.failureReason"
							class="social-publications__reason"
							data-testid="social-publication-reason">
							{{ row.failureReason }}
						</span>
					</div>

					<div class="social-publications__actions">
						<NcButton
							v-if="retryable(row)"
							variant="secondary"
							:disabled="busy === rowId(row)"
							data-testid="social-publication-retry"
							@click="retry(row)">
							{{ t('pipelinq', 'Retry') }}
						</NcButton>
						<NcButton
							v-if="row.status === 'awaiting_share'"
							variant="secondary"
							:disabled="busy === rowId(row)"
							data-testid="social-publication-share"
							@click="openShare(row)">
							{{ t('pipelinq', 'Share this myself') }}
						</NcButton>
					</div>
				</li>
			</ul>

			<section v-if="share" class="social-publications__share">
				<h3>{{ t('pipelinq', 'Post this yourself') }}</h3>
				<p class="social-publications__reason">
					{{
						t(
							'pipelinq',
							'No application may post to this account, so the text is ready for you to post.',
						)
					}}
				</p>
				<textarea
					class="social-publications__prepared"
					rows="5"
					readonly
					data-testid="social-share-body"
					:value="share.body" />
				<div class="social-publications__actions">
					<NcButton variant="secondary" @click="copyPrepared">
						{{ t('pipelinq', 'Copy text') }}
					</NcButton>
					<NcButton
						v-if="share.composerUrl"
						variant="secondary"
						@click="openComposer">
						{{ t('pipelinq', 'Open the composer') }}
					</NcButton>
					<NcButton
						variant="primary"
						data-testid="social-share-confirm"
						@click="confirm">
						{{ t('pipelinq', 'I posted this') }}
					</NcButton>
				</div>
			</section>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import {
	confirmShare,
	fetchPublications,
	fetchShare,
	retryPublication,
} from '../../services/socialApi.js'
import {
	isRetryable,
	networkLimits,
	publicationStatusChip,
} from '../../services/socialNetworks.js'

export default {
	name: 'SocialPublicationsSection',

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
			busy: '',
			error: '',
			rows: [],
			share: null,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * The id this section is bound to, either the prop or the section
		 * context the page host provides.
		 *
		 * @return {string} The post id.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
		 */
		effectiveId() {
			if (this.postId) {
				return this.postId
			}
			const context = this.cnSectionContext || {}
			return context.objectId || context.id || ''
		},

		/**
		 * @param {object} row A publication row.
		 * @return {string} Its id.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
		 */
		rowId(row) {
			return row?.id || row?.uuid || ''
		},

		/**
		 * @param {string} status The publication status.
		 * @return {object} The chip.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
		 */
		chip(status) {
			return publicationStatusChip(status)
		},

		/**
		 * @param {string} network The network.
		 * @return {string} Its label.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
		 */
		networkLabel(network) {
			return networkLimits(network).label
		},

		/**
		 * @param {object} row A publication row.
		 * @return {boolean} Whether a retry is worth offering.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
		 */
		retryable(row) {
			return isRetryable(row)
		},

		/**
		 * @return {Promise<void>} Resolves once the rows are in.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
		 */
		async load() {
			const id = this.effectiveId()
			if (!id) {
				return
			}

			this.loading = true
			this.error = ''
			try {
				this.rows = await fetchPublications(id)
			} catch {
				this.error = t('pipelinq', 'The publications could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} row The publication to try again.
		 * @return {Promise<void>} Resolves once retried.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
		 */
		async retry(row) {
			this.busy = this.rowId(row)
			this.error = ''
			try {
				await retryPublication(this.rowId(row))
				await this.load()
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('pipelinq', 'The retry did not work.')
			} finally {
				this.busy = ''
			}
		},

		/**
		 * @param {object} row The publication whose share is prepared.
		 * @return {Promise<void>} Resolves once the bundle is in.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
		 */
		async openShare(row) {
			this.busy = this.rowId(row)
			this.error = ''
			try {
				this.share = await fetchShare(this.rowId(row))
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('pipelinq', 'The prepared text could not be loaded.')
			} finally {
				this.busy = ''
			}
		},

		/**
		 * Put the prepared text on the clipboard.
		 *
		 * @return {Promise<void>} Resolves once copied.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
		 */
		async copyPrepared() {
			try {
				await navigator.clipboard.writeText(this.share?.body || '')
			} catch {
				this.error = t(
					'pipelinq',
					'The text could not be copied. Select it and copy it by hand.',
				)
			}
		},

		/**
		 * Open the network's own composer in a new tab.
		 *
		 * @return {void}
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
		 */
		openComposer() {
			window.open(this.share?.composerUrl || '', '_blank', 'noopener')
		},

		/**
		 * Record that the owner posted it.
		 *
		 * @return {Promise<void>} Resolves once recorded.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
		 */
		async confirm() {
			this.error = ''
			try {
				await confirmShare(this.share?.publicationId || '')
				this.share = null
				await this.load()
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('pipelinq', 'The share could not be recorded.')
			}
		},
	},
}
</script>

<style scoped>
.social-publications__list {
	list-style: none;
	padding: 0;
}

.social-publications__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 10px 0;
	border-bottom: 1px solid var(--color-border);
	flex-wrap: wrap;
}

.social-publications__identity {
	display: flex;
	flex-direction: column;
}

.social-publications__state {
	display: flex;
	flex-direction: column;
	max-width: 480px;
}

.social-publications__reason {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.social-publications__actions {
	display: flex;
	gap: 8px;
}

.social-publications__prepared {
	width: 100%;
}
</style>
