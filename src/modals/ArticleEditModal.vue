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
	<NcModal :name="modalTitle" size="large" @close="$emit('close')">
		<div class="article-edit">
			<h2>{{ modalTitle }}</h2>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<label class="article-edit__label" for="article-edit-title">
				{{ t('pipelinq', 'Title') }} *
			</label>
			<input
				id="article-edit-title"
				v-model="model.title"
				type="text"
				class="article-edit__input"
				:placeholder="t('pipelinq', 'Headline of the article')" />

			<label class="article-edit__label" for="article-edit-slug">
				{{ t('pipelinq', 'Slug') }}
			</label>
			<input
				id="article-edit-slug"
				v-model="model.slug"
				type="text"
				class="article-edit__input"
				:placeholder="
					t('pipelinq', 'Derived from the title when left empty')
				" />

			<label class="article-edit__label" for="article-edit-summary">
				{{ t('pipelinq', 'Summary') }}
			</label>
			<textarea
				id="article-edit-summary"
				v-model="model.summary"
				class="article-edit__textarea"
				rows="2"
				:placeholder="
					t('pipelinq', 'One or two sentences, shown on the card')
				" />

			<span class="article-edit__label">{{ t('pipelinq', 'Body') }}</span>
			<CnMarkdownEditor
				v-model="model.body"
				:aria-label="t('pipelinq', 'Article body')"
				:rows="12" />

			<label class="article-edit__label" for="article-edit-hero">
				{{ t('pipelinq', 'Hero image') }}
			</label>
			<div class="article-edit__hero-row">
				<input
					id="article-edit-hero"
					v-model="model.heroImage"
					type="text"
					class="article-edit__input"
					:placeholder="t('pipelinq', 'Files path, or an absolute URL')" />
				<NcButton variant="secondary" @click="openHeroPicker">
					{{ t('pipelinq', 'Browse…') }}
				</NcButton>
			</div>

			<NcSelect
				v-model="languageOption"
				:options="languageOptions"
				:inputLabel="t('pipelinq', 'Language')"
				label="label"
				:reduce="(option) => option.value"
				:clearable="false"
				class="article-edit__language" />

			<label class="article-edit__label" for="article-edit-tags">
				{{ t('pipelinq', 'Tags') }}
			</label>
			<input
				id="article-edit-tags"
				v-model="tagsText"
				type="text"
				class="article-edit__input"
				:placeholder="
					t('pipelinq', 'Comma-separated, such as release, product')
				" />

			<label class="article-edit__label" for="article-edit-portal-ref">
				{{ t('pipelinq', 'Portal page') }}
			</label>
			<input
				id="article-edit-portal-ref"
				v-model="model.portalPageRef"
				type="text"
				class="article-edit__input"
				:placeholder="
					t('pipelinq', 'Filled in once the public page exists')
				" />

			<footer class="article-edit__actions">
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
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="16" />
					</template>
					{{
						isEditing
							? t('pipelinq', 'Save changes')
							: t('pipelinq', 'Create article')
					}}
				</NcButton>
			</footer>
		</div>
	</NcModal>
</template>

<script>
import { CnMarkdownEditor } from '@conduction/nextcloud-vue'
import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import {
	NcButton,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
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
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcSelect,
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
							this.article.id || this.article.uuid,
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
	padding: 1.5rem;
	display: flex;
	flex-direction: column;
	gap: 0.4rem;
	max-height: 80vh;
	overflow-y: auto;
}

.article-edit__label {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin-block-start: 0.5rem;
}

.article-edit__input,
.article-edit__textarea {
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-darker);
	color: var(--color-main-text);
	font-family: inherit;
	width: 100%;
	box-sizing: border-box;
}

.article-edit__hero-row {
	display: flex;
	gap: 0.5rem;
	align-items: center;
}

.article-edit__hero-row .article-edit__input {
	flex: 1;
}

.article-edit__language {
	max-width: 320px;
}

.article-edit__actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.5rem;
	margin-block-start: 1rem;
}
</style>
