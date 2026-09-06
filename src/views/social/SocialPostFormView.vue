<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - Thin "New post" route wrapper, matching the ArticleNew / SegmentNew /
  - TemplateNew convention: the Social posts index page's header action
  - navigates here, and this view mounts the one composing surface the change
  - owns rather than a second body-and-variants form.
  -
  - There is no declarative create dialog because there is nothing declarative
  - about the composer: per-network variants, a live character count against
  - each network's own limit, and a submit that puts the post up for approval
  - rather than saving it are three things the grammar has no field for.
  -
  - @spec openspec/changes/social-publishing/specs/marketing-ui/spec.md#requirement-a-marketer-composes-one-post-for-several-networks
  -->
<template>
	<div class="social-post-form-view">
		<SocialPostComposeModal @close="onClose" @saved="onSaved" />
	</div>
</template>

<script>
import SocialPostComposeModal from '../../modals/SocialPostComposeModal.vue'

export default {
	name: 'SocialPostFormView',

	components: {
		SocialPostComposeModal,
	},

	methods: {
		/**
		 * The composer was dismissed without saving.
		 *
		 * @return {void}
		 * @spec openspec/changes/social-publishing/specs/marketing-ui/spec.md#requirement-a-marketer-composes-one-post-for-several-networks
		 */
		onClose() {
			this.$router.push({ name: 'SocialPosts' })
		},

		/**
		 * The post was saved. Go to its detail page, where the approval and the
		 * per-account outcomes live.
		 *
		 * @param {object} post The saved post.
		 * @return {void}
		 * @spec openspec/changes/social-publishing/specs/marketing-ui/spec.md#requirement-a-marketer-composes-one-post-for-several-networks
		 */
		onSaved(post) {
			const id = post && (post.id || post.uuid)
			if (id) {
				this.$router.push({ name: 'SocialPostDetail', params: { id } })
				return
			}
			this.$router.push({ name: 'SocialPosts' })
		},
	},
}
</script>
