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
		<NcLoadingIcon v-if="loading && !loaded" :size="24" />

		<NcNoteCard v-else-if="error" type="error">{{ error }}</NcNoteCard>

		<template v-else>
			<p v-if="rows.length === 0" class="social-publications__empty">
				{{ t('pipelinq', 'This post has not gone out yet.') }}
			</p>

			<!-- One grid for the whole list, each row a subgrid of it, so the
				columns line up across rows whatever each row's content length. -->
			<ul v-else class="social-publications__list">
				<li
					v-for="row in rows"
					:key="rowId(row)"
					class="social-publications__row"
					data-testid="social-publication-row">
					<span class="social-publications__icon" aria-hidden="true">
						<component :is="networkIcon(row.network)" :size="20" />
					</span>

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
						<span class="social-publications__chip">
							<span
								class="social-publications__dot"
								:style="{ background: chip(row.status).color }" />
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
				<label
					class="social-publications__label"
					for="social-share-prepared-body">
					{{ t('pipelinq', 'Prepared text') }}
				</label>
				<textarea
					id="social-share-prepared-body"
					class="social-publications__prepared"
					rows="5"
					readonly
					data-testid="social-share-body"
					:value="share.body" />
				<div class="social-publications__actions">
					<NcButton variant="secondary" @click="copyPrepared">
						{{ t('pipelinq', 'Copy text') }}
					</NcButton>
					<!-- The network's own composer, in a new tab.
						@spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share -->
					<NcButton
						v-if="share.composerUrl"
						variant="secondary"
						:href="share.composerUrl"
						target="_blank"
						rel="noopener noreferrer">
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
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import {
	confirmShare,
	fetchPublications,
	fetchShare,
	retryPublication,
} from '../../services/socialApi.js'
import { networkIcon } from '../../services/socialNetworkIcons.js'
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
			loaded: false,
			busy: '',
			error: '',
			rows: [],
			share: null,
		}
	},

	/**
	 * Load the publications and follow page refreshes.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	mounted() {
		this.load()
		// An approval in the page header can put the post in line to go out.
		subscribe('cn:page:refresh', this.load)
	},

	beforeUnmount() {
		unsubscribe('cn:page:refresh', this.load)
	},

	methods: {
		/**
		 * The icon a publication's network is shown with.
		 *
		 * @param {string} network The network.
		 * @return {object} Its icon component.
		 *
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
		 */
		networkIcon(network) {
			return networkIcon(network)
		},

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
				this.loaded = true
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
.social-publications {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.social-publications__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

/* The edge columns are `auto`, not fixed: a subgrid row's padding is added to
   its edge tracks, and a fixed track cannot grow to hold it. */
.social-publications__list {
	display: grid;
	grid-template-columns: auto minmax(140px, 1fr) minmax(0, 2fr) auto;
	gap: 8px 16px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.social-publications__row {
	grid-column: 1 / -1;
	display: grid;
	grid-template-columns: subgrid;
	align-items: center;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.social-publications__icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	border-radius: 50%;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.social-publications__identity,
.social-publications__state {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.social-publications__chip {
	display: inline-flex;
	align-items: center;
	align-self: flex-start;
	gap: 6px;
	padding: 2px 10px;
	border-radius: 999px;
	background: var(--color-background-dark);
	font-weight: 600;
	font-size: 0.9em;
}

.social-publications__dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
}

.social-publications__reason {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.social-publications__actions {
	display: flex;
	justify-content: flex-end;
	flex-wrap: wrap;
	gap: 8px;
}

.social-publications__share {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 14px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.social-publications__share h3 {
	margin: 0;
	font-size: 1em;
}

.social-publications__share .social-publications__actions {
	justify-content: flex-start;
}

.social-publications__prepared {
	box-sizing: border-box;
	width: 100%;
}

.social-publications__label {
	display: block;
	font-weight: bold;
}

/* Narrow screens: name beside the icon, status and actions underneath. */
@media (max-width: 720px) {
	.social-publications__list {
		grid-template-columns: auto minmax(0, 1fr);
	}

	.social-publications__row {
		row-gap: 10px;
	}

	.social-publications__state,
	.social-publications__actions {
		grid-column: 2;
	}

	.social-publications__actions {
		justify-content: flex-start;
	}
}
</style>
