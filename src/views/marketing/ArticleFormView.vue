<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - Thin "New article" route wrapper (marketing-article-hub), matching the
  - SegmentNew / TemplateNew / BlastNew convention: the Articles index page's
  - header action navigates here, and this view mounts the one editing
  - surface the change owns — ArticleEditModal — rather than duplicating a
  - second body/hero-image form. `CnMarkdownEditor` and the Nextcloud Files
  - picker live in that modal alone (ADR-004: every modal lives in its own
  - file); there is no separate declarative create dialog because the
  - bespoke ArticleService create path (author stamp, slug derivation, the
  - ADR-088 agent-mark refusal) has no declarative equivalent — see
  - design.md "The REST surface is four methods wide".
  -
  - @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
  -->
<template>
	<div class="article-form-view">
		<ArticleEditModal @close="onClose" @saved="onSaved" />
	</div>
</template>

<script>
import ArticleEditModal from '../../modals/ArticleEditModal.vue'

export default {
	name: 'ArticleFormView',

	components: {
		ArticleEditModal,
	},

	methods: {
		/**
		 * The modal was dismissed without saving.
		 *
		 * @return {void}
		 */
		onClose() {
			this.$router.push({ name: 'Articles' })
		},

		/**
		 * The article was created. Go straight to its detail page — that is
		 * where the lifecycle actions (submit for review, publish) live.
		 *
		 * @param {object} article The created article.
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
		 * @return {void}
		 */
		onSaved(article) {
			const id = article && (article.id || article.uuid)
			if (id) {
				this.$router.push({ name: 'ArticleDetail', params: { id } })
				return
			}
			this.$router.push({ name: 'Articles' })
		},
	},
}
</script>
