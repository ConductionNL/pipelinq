<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The SocialPostDetail page's `actionsComponent`: the approval step, in the
  - page header beside Edit. Submit for approval on a draft; Approve and Reject
  - while it waits for approval.
  -
  - It POSTs to /api/social-posts/{id}/{submit|approve|reject} rather than
  - driving `lifecycleActions`, because an approval has to record who decided
  - and when, stamped from the session, which the transition grammar has no
  - field for. Success bumps `cn:page:refresh`, so the page and its sections
  - re-read the post.
  -
  - @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
  -->
<template>
	<div
		v-if="status === 'draft' || status === 'approval'"
		class="social-post-header-actions"
		data-testid="social-post-header-actions">
		<NcButton
			v-if="status === 'draft'"
			variant="primary"
			:disabled="busy"
			data-testid="social-variants-submit"
			@click="move('submit')">
			{{ t('pipelinq', 'Submit for approval') }}
		</NcButton>
		<template v-else>
			<NcButton
				variant="secondary"
				:disabled="busy"
				data-testid="social-variants-reject"
				@click="move('reject')">
				{{ t('pipelinq', 'Reject') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy"
				data-testid="social-variants-approve"
				@click="move('approve')">
				{{ t('pipelinq', 'Approve') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { NcButton } from '@nextcloud/vue'
import { movePost } from '../../services/socialApi.js'

export default {
	name: 'SocialPostHeaderActions',

	components: {
		NcButton,
	},

	props: {
		/** The post, from CnDetailPage's `#actions` slot. */
		object: {
			type: Object,
			default: null,
		},

		/** The post id, from CnDetailPage's `#actions` slot. */
		objectId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			busy: false,
		}
	},

	computed: {
		/** @return {string} The post's status. */
		status() {
			return this.object?.status || ''
		},

		/** @return {string} The post id. */
		postId() {
			return (
				this.objectId || this.object?.id || this.object?.['@self']?.id || ''
			)
		},
	},

	methods: {
		/**
		 * Submit, approve or reject.
		 *
		 * @param {string} action One of `submit`, `approve`, `reject`.
		 * @return {Promise<void>} Resolves once moved.
		 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
		 */
		async move(action) {
			this.busy = true
			try {
				await movePost(this.postId, action)
				emit('cn:page:refresh', {})
			} catch (error) {
				showError(
					error?.response?.data?.error
						|| t('pipelinq', 'That did not work.'),
				)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.social-post-header-actions {
	display: flex;
	gap: 8px;
}
</style>
