<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - The ArticleDetail page's Edit form, mounted into CnDetailPage's
  - `form-dialog` slot. It is the page's own schema form minus the title and
  - body, which are written in the dedicated article editor instead: a button
  - at the top opens ArticleEditModal over this form.
  -
  - That editor saves through /api/articles and then announces
  - `cn:page:refresh`, so the page re-reads the article. This form's record
  - updates with it and keeps what was typed here, so a later Save does not
  - put the old title and body back.
  -->
<template>
	<div>
		<CnFormDialog
			v-if="show && schema"
			ref="form"
			:schema="schema"
			:item="item"
			register="pipelinq"
			:dialogTitle="t('pipelinq', 'Edit article')"
			:excludeFields="item ? editorFields : []"
			@confirm="onConfirm"
			@close="close()">
			<template v-if="item" #before-fields>
				<div class="article-detail-form__editor">
					<p class="article-detail-form__editor-text">
						{{ t('pipelinq', 'The title and body are written in the article editor.') }}
					</p>
					<NcButton variant="secondary" @click="editorOpen = true">
						<template #icon>
							<PencilOutline :size="20" />
						</template>
						{{ t('pipelinq', 'Edit title and body') }}
					</NcButton>
				</div>
			</template>
		</CnFormDialog>

		<ArticleEditModal
			v-if="editorOpen && item"
			:article="item"
			@close="editorOpen = false"
			@saved="onEditorSaved" />
	</div>
</template>

<script>
import { CnFormDialog } from '@conduction/nextcloud-vue'
import { emit } from '@nextcloud/event-bus'
import { NcButton } from '@nextcloud/vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import ArticleEditModal from '../modals/ArticleEditModal.vue'

export default {
	name: 'ArticleDetailFormDialog',
	components: {
		ArticleEditModal,
		CnFormDialog,
		NcButton,
		PencilOutline,
	},

	inheritAttrs: false,

	props: {
		/** Whether the detail page has its edit form open. */
		show: {
			type: Boolean,
			default: false,
		},

		/** The article being edited. */
		item: {
			type: Object,
			default: null,
		},

		/** The article schema the form is generated from. */
		schema: {
			type: Object,
			default: null,
		},

		/** Saves through the detail page's own edit path. */
		confirm: {
			type: Function,
			default: null,
		},

		/** Closes the edit form. */
		close: {
			type: Function,
			default: () => {},
		},
	},

	data() {
		return {
			editorOpen: false,
			editorFields: ['title', 'body'],
		}
	},

	watch: {
		show(open) {
			if (!open) {
				this.editorOpen = false
			}
		},
	},

	methods: {
		/**
		 * Save through the page, and show a failure in the form.
		 *
		 * @param {object} formData The form's values.
		 */
		async onConfirm(formData) {
			const result = await this.confirm?.(formData)
			if (result?.error) {
				this.$refs.form?.setResult({ error: result.error })
			}
		},

		onEditorSaved() {
			this.editorOpen = false
			emit('cn:page:refresh', {})
		},
	},
}
</script>

<style scoped>
/* Spans both columns when the form lays its fields out in two. */
.article-detail-form__editor {
	grid-column: 1 / -1;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px 16px;
	margin-bottom: 12px;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.article-detail-form__editor-text {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
