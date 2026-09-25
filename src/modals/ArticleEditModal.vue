<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Create or edit an article. Lives in its own file because every modal does
  - (ADR-004). The body is written with `CnMarkdownEditor` (markdown is the
  - article's one storage format, design.md "The body is markdown, and only
  - markdown") and the hero image is picked from Nextcloud Files with the
  - native picker rather than typed as a bare path, the same
  - `getFilePickerBuilder` pattern the library's own `CnFilesWidgetForm` uses.
  -
  - @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
  -->
<template>
	<NcDialog
		:name="modalTitle"
		:open="true"
		size="large"
		:closeOnClickOutside="false"
		@closing="$emit('close')">
		<div class="article-edit">
			<NcNoteCard v-if="error" type="error" class="article-edit__note">
				{{ error }}
			</NcNoteCard>

			<section class="article-edit__section">
				<NcTextField
					id="article-edit-title"
					v-model="model.title"
					:label="t('pipelinq', 'Title')"
					:placeholder="t('pipelinq', 'Headline of the article')"
					required />
				<NcTextArea
					id="article-edit-summary"
					v-model="model.summary"
					:label="t('pipelinq', 'Summary')"
					:placeholder="t('pipelinq', 'One or two sentences, shown on the card')"
					rows="2"
					resize="vertical" />
				<div class="article-edit__body">
					<span class="article-edit__label">{{ t('pipelinq', 'Body') }}</span>
					<CnMarkdownEditor
						v-model="model.body"
						:aria-label="t('pipelinq', 'Article body')"
						:rows="14" />
				</div>
			</section>

			<section class="article-edit__section">
				<h3 class="article-edit__heading">
					{{ t('pipelinq', 'Details') }}
				</h3>
				<div class="article-edit__hero">
					<NcTextField
						id="article-edit-hero"
						v-model="model.heroImage"
						:label="t('pipelinq', 'Hero image')"
						:placeholder="t('pipelinq', 'Files path, or an absolute URL')" />
					<NcButton variant="secondary" @click="openHeroPicker">
						<template #icon>
							<FolderImage :size="20" />
						</template>
						{{ t('pipelinq', 'Browse…') }}
					</NcButton>
				</div>
				<div class="article-edit__grid">
					<NcTextField
						id="article-edit-slug"
						v-model="model.slug"
						:label="t('pipelinq', 'Slug')"
						:placeholder="t('pipelinq', 'Derived from the title when left empty')" />
					<!-- Lines the select up with the text field beside it. -->
					<div class="article-edit__language">
						<NcSelect
							v-model="languageOption"
							:options="languageOptions"
							:inputLabel="t('pipelinq', 'Language')"
							label="label"
							:reduce="(option) => option.value"
							:clearable="false"
							:searchable="false" />
					</div>
					<NcTextField
						id="article-edit-tags"
						v-model="tagsText"
						:label="t('pipelinq', 'Tags')"
						:placeholder="t('pipelinq', 'Comma-separated, such as release, product')" />
					<NcTextField
						id="article-edit-portal-ref"
						v-model="model.portalPageRef"
						:label="t('pipelinq', 'Portal page')"
						:placeholder="t('pipelinq', 'Filled in once the public page exists')" />
				</div>
			</section>
		</div>

		<template #actions>
			<NcButton
				variant="tertiary"
				:disabled="saving"
				@click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!canSave"
				data-testid="article-edit-save"
				@click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{
					isEditing
						? t('pipelinq', 'Save changes')
						: t('pipelinq', 'Create article')
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { CnMarkdownEditor } from '@conduction/nextcloud-vue'
import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import FolderImage from 'vue-material-design-icons/FolderImage.vue'
import { createArticle, updateArticle } from '../services/articlesApi.js'

import '@nextcloud/dialogs/style.css'

const LANGUAGE_OPTIONS = [
	{ value: 'nl', label: 'Nederlands' },
	{ value: 'en', label: 'English' },
]

export default {
	name: 'ArticleEditModal',

	components: {
		CnMarkdownEditor,
		FolderImage,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** The article being edited, or null to create a new one. */
		article: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'saved'],

	data() {
		const source = this.article || {}
		return {
			saving: false,
			error: '',
			model: {
				title: source.title || '',
				slug: source.slug || '',
				summary: source.summary || '',
				body: source.body || '',
				heroImage: source.heroImage || '',
				language: source.language || 'nl',
				portalPageRef: source.portalPageRef || '',
			},

			tagsText: Array.isArray(source.tags) ? source.tags.join(', ') : '',
			links: Array.isArray(source.links)
				? source.links.map((link) => ({ ...link }))
				: [],
		}
	},

	computed: {
		/**
		 * @return {boolean} Whether this instance is editing an existing article.
		 */
		isEditing() {
			return Boolean(this.article)
		},

		/**
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
		 * @return {string} The modal title.
		 */
		modalTitle() {
			return this.isEditing
				? this.t('pipelinq', 'Edit article')
				: this.t('pipelinq', 'New article')
		},

		/**
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
		 * @return {Array<{value: string, label: string}>} The two language options.
		 */
		languageOptions() {
			return LANGUAGE_OPTIONS
		},

		/**
		 * The selected language as an NcSelect option value.
		 *
		 * @return {string}
		 */
		languageOption: {
			/**
			 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
			 * @return {string} The current language code.
			 */
			get() {
				return this.model.language
			},

			/**
			 * @param {string} value The language code just picked.
			 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
			 */
			set(value) {
				this.model.language = value || 'nl'
			},
		},

		/**
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
		 * @return {boolean} Whether the form has enough to attempt a save.
		 */
		canSave() {
			return this.model.title.trim() !== '' && !this.saving
		},
	},

	methods: {
		/**
		 * Open the Nextcloud Files picker restricted to images, and store the
		 * picked node's path.
		 *
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
		 * @return {Promise<void>} Resolves once a pick has been applied, or declined.
		 */
		async openHeroPicker() {
			const picker = getFilePickerBuilder(
				this.t('pipelinq', 'Choose a hero image'),
			)
				.setMultiSelect(false)
				.setMimeTypeFilter([
					'image/png',
					'image/jpeg',
					'image/webp',
					'image/gif',
				])
				.allowDirectories(false)
				.addButton({
					label: this.t('pipelinq', 'Choose'),
					type: 'primary',
					callback: () => {},
				})
				.build()
			try {
				const nodes = await picker.pickNodes()
				const node = Array.isArray(nodes) ? nodes[0] : nodes
				if (node) {
					this.model.heroImage = node.path
				}
			} catch (e) {
				if (e instanceof FilePickerClosed) {
					return
				}
				console.error('Hero image picker failed', e)
				this.error = this.t(
					'pipelinq',
					'The file picker could not be opened.',
				)
			}
		},

		/**
		 * Build the payload from the model, splitting the tags text field
		 * back into an array.
		 *
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
		 * @return {object} The payload the API expects.
		 */
		buildPayload() {
			const tags = this.tagsText
				.split(',')
				.map((tag) => tag.trim())
				.filter((tag) => tag !== '')
			return {
				...this.model,
				tags,
				links: this.links,
			}
		},

		/**
		 * Create or update the article.
		 *
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
		 * @return {Promise<void>} Resolves when the caller has been told.
		 */
		async save() {
			if (!this.canSave) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				const payload = this.buildPayload()
				const saved = this.isEditing
					? await updateArticle(
							this.article.id || this.article.uuid || this.article['@self']?.id,
							payload,
						)
					: await createArticle(payload)
				this.$emit('saved', saved)
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('pipelinq', 'This article could not be saved.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.article-edit {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding-bottom: 8px;
}

.article-edit__note {
	margin: 0;
}

.article-edit__section {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.article-edit__section + .article-edit__section {
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.article-edit__heading {
	margin: 0;
	font-size: 1.1em;
}

.article-edit__body {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.article-edit__label {
	color: var(--color-text-maxcontrast);
}

.article-edit__hero {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.article-edit__hero > :first-child {
	flex: 1;
}

.article-edit__grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	align-items: start;
	gap: 12px 16px;
}

.article-edit__language {
	margin-top: 6px;
}

.article-edit__language :deep(.v-select.select) {
	width: 100%;
	margin: 0;
}

@media (max-width: 720px) {
	.article-edit__grid {
		grid-template-columns: minmax(0, 1fr);
	}
}
</style>
