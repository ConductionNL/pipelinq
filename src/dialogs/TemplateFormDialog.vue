<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - Campaign template create / edit, mounted into the Templates index page's
  - `form-dialog` slot. It saves through POST / PATCH /api/templates rather
  - than the slot's `confirm`, because only that endpoint runs the compliance
  - check, and then calls the slot's `refresh` so the list shows the result.
  -->
<template>
	<NcDialog
		v-if="show"
		:name="isEditing ? t('pipelinq', 'Edit template') : t('pipelinq', 'New template')"
		:open="true"
		size="large"
		:closeOnClickOutside="false"
		@closing="close()">
		<div class="template-form">
			<NcLoadingIcon v-if="loading" :size="32" class="template-form__loading" />
			<NcNoteCard v-else-if="loadError" type="error">
				{{ loadError }}
			</NcNoteCard>

			<template v-else>
				<section class="template-form__grid">
					<NcTextField
						v-model="model.name"
						:label="t('pipelinq', 'Template name')"
						:placeholder="t('pipelinq', 'Renewal Reminder')"
						required
						class="template-form__name" />
					<!-- The reason sits on a wrapper: a disabled select gets no
					     mouse events, so a `title` on it would never show. -->
					<div
						class="template-form__channel"
						:class="{ 'template-form__channel--locked': isEditing }"
						:title="isEditing ? t('pipelinq', 'The channel of an existing template cannot be changed.') : null">
						<NcSelect
							v-model="channelOption"
							:options="channelOptions"
							:inputLabel="t('pipelinq', 'Channel')"
							label="label"
							:clearable="false"
							:searchable="false"
							:disabled="isEditing" />
					</div>
				</section>

				<section v-if="isEmail" class="template-form__section">
					<h3 class="template-form__heading">
						{{ t('pipelinq', 'Sender') }}
					</h3>
					<div class="template-form__grid">
						<NcTextField
							v-model="model.senderName"
							:label="t('pipelinq', 'Sender name')" />
						<NcTextField
							v-model="model.senderEmail"
							type="email"
							autocomplete="off"
							:label="t('pipelinq', 'Sender email')" />
						<NcTextField
							v-model="model.replyTo"
							type="email"
							autocomplete="off"
							:label="t('pipelinq', 'Reply-to email')" />
					</div>
				</section>

				<section class="template-form__section">
					<div class="template-form__heading-row">
						<h3 class="template-form__heading">
							{{ t('pipelinq', 'Message') }}
						</h3>
						<NcButton
							v-if="isEmail"
							variant="tertiary"
							:pressed="previewing"
							@click="previewing = !previewing">
							<template #icon>
								<CodeTags v-if="previewing" :size="20" />
								<EyeOutline v-else :size="20" />
							</template>
							{{ previewing ? t('pipelinq', 'Edit HTML') : t('pipelinq', 'Preview') }}
						</NcButton>
					</div>
					<NcTextField
						v-if="isEmail"
						v-model="model.subject"
						:label="t('pipelinq', 'Subject')"
						:error="Boolean(fieldErrors.subject)"
						:helperText="fieldErrors.subject || ''" />
					<!-- An empty `sandbox` gives the preview an opaque origin and no
					     scripts, forms or popups, so pasted HTML cannot reach the
					     page around it. -->
					<iframe
						v-if="isEmail && previewing"
						class="template-form__preview"
						sandbox=""
						referrerpolicy="no-referrer"
						:title="t('pipelinq', 'HTML body preview')"
						:srcdoc="previewDocument" />
					<NcTextArea
						v-else
						v-model="model.bodyHtml"
						:label="isEmail ? t('pipelinq', 'HTML body') : t('pipelinq', 'Message body')"
						:error="Boolean(fieldErrors.bodyHtml)"
						:helperText="fieldErrors.bodyHtml || bodyHint"
						rows="10"
						resize="vertical"
						class="template-form__body-html"
						:class="{ 'template-form__body-html--code': isEmail }" />
					<template v-if="isEmail">
						<NcTextArea
							v-model="model.bodyText"
							:label="t('pipelinq', 'Plain-text body')"
							rows="4"
							resize="vertical" />
						<NcTextArea
							v-model="model.footerOverride"
							:label="t('pipelinq', 'Footer override')"
							rows="2"
							resize="vertical" />
					</template>
				</section>

				<section class="template-form__section">
					<h3 class="template-form__heading">
						{{ t('pipelinq', 'Articles') }}
					</h3>
					<NcSelect
						v-model="selectedArticles"
						:options="publishedArticles"
						:inputLabel="t('pipelinq', 'Articles')"
						:multiple="true"
						label="title"
						:loading="articlesLoading"
						:placeholder="t('pipelinq', 'Pick published articles to embed')"
						keepOpen
						class="template-form__articles" />
					<p class="template-form__hint">
						{{ articlesHintText }}
					</p>
					<NcNoteCard v-if="showMarkerWarning" type="warning" class="template-form__note">
						{{ markerWarningText }}
					</NcNoteCard>
				</section>

				<NcNoteCard v-if="saveError" type="error" class="template-form__note">
					{{ saveError }}
				</NcNoteCard>
			</template>
		</div>

		<template #actions>
			<span v-if="saveHint" class="template-form__save-hint">
				{{ saveHint }}
			</span>
			<NcButton variant="tertiary" @click="close()">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canSave" @click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ isEditing ? t('pipelinq', 'Save changes') : t('pipelinq', 'Create template') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import CodeTags from 'vue-material-design-icons/CodeTags.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import { fetchArticles } from '../services/articlesApi.js'
import {
	orderedArticleIds,
	publishedOnly,
	resolveSelectedArticles,
	shouldWarnMissingMarker,
} from '../services/templateArticlePicker.js'

/**
 * @return {object} A blank template.
 */
function blankModel() {
	return {
		name: '',
		channel: 'email',
		subject: '',
		bodyHtml: '',
		bodyText: '',
		senderName: '',
		senderEmail: '',
		replyTo: '',
		footerOverride: '',
		articleIds: [],
	}
}

export default {
	name: 'TemplateFormDialog',
	components: {
		CodeTags,
		EyeOutline,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	inheritAttrs: false,

	props: {
		/** Whether the index page has the dialog open. */
		show: {
			type: Boolean,
			default: false,
		},

		/** The row being edited, or null to create. */
		item: {
			type: Object,
			default: null,
		},

		/** Closes the dialog. */
		close: {
			type: Function,
			default: () => {},
		},

		/** Re-reads the index page's list. */
		refresh: {
			type: Function,
			default: null,
		},
	},

	data() {
		return {
			model: blankModel(),
			loading: false,
			loadError: '',
			saving: false,
			saveError: '',
			// The compliance check returns one message; parseFieldErrors
			// places it on the field it is about.
			fieldErrors: {},
			articles: [],
			articlesLoading: false,
			previewing: false,
		}
	},

	computed: {
		templateId() {
			return this.item?.id || this.item?.['@self']?.id || this.item?.uuid || null
		},

		isEditing() {
			return this.templateId !== null
		},

		isEmail() {
			return this.model.channel === 'email'
		},

		channelOptions() {
			return [
				{ value: 'email', label: this.t('pipelinq', 'Email') },
				{ value: 'sms', label: this.t('pipelinq', 'SMS') },
			]
		},

		/**
		 * The channel decides the fields: email adds subject, sender,
		 * reply-to, plain-text body and footer; SMS does not.
		 *
		 * @spec openspec/changes/marketing-segments-ui-repair/specs/marketing-ui/spec.md#requirement-segments-and-templates-pages-are-reachable-from-the-marketing-menu
		 */
		channelOption: {
			get() {
				return this.channelOptions.find((o) => o.value === this.model.channel) || this.channelOptions[0]
			},

			set(option) {
				this.model.channel = option?.value || 'email'
			},
		},

		/**
		 * Composed here rather than in the template: the token's literal
		 * double braces do not parse inside a mustache.
		 *
		 * @return {string} The hint under the body.
		 */
		bodyHint() {
			return this.isEmail
				? this.t('pipelinq', 'Use {{unsubscribe_link}} and a physical address so the compliance check passes.')
				: ''
		},

		/**
		 * The body as a standalone document for the sandboxed preview. Its
		 * CSP only allows inline styles and images, on top of the sandbox.
		 *
		 * @return {string} The preview document.
		 */
		previewDocument() {
			const csp = "default-src 'none'; style-src 'unsafe-inline'; img-src data: https:; font-src data: https:"
			return '<!DOCTYPE html><html><head><meta charset="utf-8">'
				+ `<meta http-equiv="Content-Security-Policy" content="${csp}">`
				+ '<style>body{margin:16px;font-family:sans-serif;color:#222;background:#fff}</style>'
				+ `</head><body>${this.model.bodyHtml}</body></html>`
		},

		/**
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles
		 * @return {Array<object>} Only published articles may be embedded.
		 */
		publishedArticles() {
			return publishedOnly(this.articles)
		},

		/**
		 * The picked articles in the order `model.articleIds` holds them, so
		 * opening the picker never reorders the embed.
		 *
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles
		 */
		selectedArticles: {
			get() {
				return resolveSelectedArticles(this.model.articleIds, this.articles)
			},

			set(options) {
				this.model.articleIds = orderedArticleIds(options)
			},
		},

		/**
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles
		 * @return {boolean} Articles are picked but the body has no marker to put them at.
		 */
		showMarkerWarning() {
			return shouldWarnMissingMarker(this.model.articleIds, this.model.bodyHtml)
		},

		articlesHintText() {
			return this.t('pipelinq', 'Embedded where the body carries the {{articles}} marker, in the order picked here.')
		},

		markerWarningText() {
			return this.t('pipelinq', 'The body has no {{articles}} marker, so these articles will not appear until you add one.')
		},

		canSave() {
			return this.model.name.trim() !== '' && this.model.bodyHtml.trim() !== '' && !this.saving && !this.loading
		},

		/** @return {string} Why Save is disabled. */
		saveHint() {
			if (this.loading || this.saving || this.loadError) {
				return ''
			}
			if (this.model.name.trim() === '') {
				return this.t('pipelinq', 'Give the template a name.')
			}
			if (this.model.bodyHtml.trim() === '') {
				return this.t('pipelinq', 'Add a message body.')
			}
			return ''
		},
	},

	watch: {
		show: {
			immediate: true,
			handler(open) {
				if (open) {
					this.reset()
				}
			},
		},

		// An error clears once the field it is about is edited.
		'model.bodyHtml': function() {
			this.clearFieldError('bodyHtml')
		},

		'model.subject': function() {
			this.clearFieldError('subject')
		},
	},

	methods: {
		reset() {
			this.model = blankModel()
			this.loadError = ''
			this.saveError = ''
			this.fieldErrors = {}
			this.previewing = false
			this.loadArticles()
			if (this.isEditing) {
				this.loadTemplate()
			}
		},

		clearFieldError(field) {
			if (this.fieldErrors[field]) {
				const { [field]: _, ...rest } = this.fieldErrors
				this.fieldErrors = rest
			}
		},

		/**
		 * @spec openspec/changes/marketing-article-hub/specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles
		 */
		async loadArticles() {
			this.articlesLoading = true
			try {
				this.articles = await fetchArticles()
			} catch {
				this.articles = []
			} finally {
				this.articlesLoading = false
			}
		},

		async loadTemplate() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl(`/apps/pipelinq/api/templates/${this.templateId}`))
				const blank = blankModel()
				this.model = Object.fromEntries(Object.keys(blank).map((key) => [key, data?.[key] ?? blank[key]]))
			} catch (e) {
				this.loadError = e?.response?.data?.error || this.t('pipelinq', 'Could not load this template.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Place the compliance check's message on the field it is about: a
		 * missing unsubscribe link or address on the body, a subject problem
		 * on the subject.
		 *
		 * @param {string} message The error from the API.
		 * @return {object} A `{ bodyHtml?, subject? }` map.
		 * @spec openspec/changes/marketing-segments-ui-repair/specs/marketing-api/spec.md#scenario-template-create-validates-compliance
		 */
		parseFieldErrors(message) {
			const lower = (message || '').toLowerCase()
			if (lower.includes('unsubscribe') || lower.includes('address')) {
				return { bodyHtml: message }
			}
			if (lower.includes('subject')) {
				return { subject: message }
			}
			return {}
		},

		/**
		 * @spec openspec/changes/marketing-segments-ui-repair/specs/marketing-api/spec.md#scenario-template-create-validates-compliance
		 */
		async save() {
			if (!this.canSave) {
				return
			}
			this.saving = true
			this.saveError = ''
			this.fieldErrors = {}
			const payload = {
				name: this.model.name.trim(),
				channel: this.model.channel,
				subject: this.model.subject,
				bodyHtml: this.model.bodyHtml,
				bodyText: this.model.bodyText,
				senderName: this.model.senderName,
				senderEmail: this.model.senderEmail,
				replyTo: this.model.replyTo,
				footerOverride: this.model.footerOverride,
				articleIds: this.model.articleIds,
			}
			try {
				if (this.isEditing) {
					await axios.patch(generateUrl(`/apps/pipelinq/api/templates/${this.templateId}`), payload)
					showSuccess(this.t('pipelinq', 'Template saved.'))
				} else {
					await axios.post(generateUrl('/apps/pipelinq/api/templates'), payload)
					showSuccess(this.t('pipelinq', 'Template created.'))
				}
				this.refresh?.()
				this.close()
			} catch (e) {
				const message = e?.response?.data?.error || this.t('pipelinq', 'Could not save this template.')
				this.fieldErrors = this.parseFieldErrors(message)
				// The body's error is shown on the editor, not the preview.
				if (this.fieldErrors.bodyHtml) {
					this.previewing = false
				}
				// A message shown on its field is not repeated as a banner.
				this.saveError = Object.keys(this.fieldErrors).length ? '' : message
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.template-form {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding-bottom: 8px;
}

.template-form__loading {
	margin: 32px auto;
}

.template-form__grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	align-items: start;
	gap: 12px 16px;
}

.template-form__section {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.template-form__heading {
	margin: 0;
	font-size: 1.1em;
}

/* Lines the select up with the name field beside it. */
.template-form__channel {
	margin-top: 6px;
}

.template-form__channel :deep(.v-select.select),
.template-form__section .template-form__articles {
	width: 100%;
	margin: 0;
}

/* NcSelect barely changes when disabled, so the locked state is drawn here. */
.template-form__channel--locked {
	cursor: not-allowed;
}

.template-form__channel--locked :deep(.v-select.select) {
	pointer-events: none;
	opacity: 0.5;
}

.template-form__heading-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

/* An email renders on white whatever the theme, so the preview does too. */
.template-form__preview {
	width: 100%;
	box-sizing: border-box;
	/* The editor's own height at rows="10", so switching does not jump. */
	height: 216px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-large);
	background: #fff;
}

.template-form__body-html--code :deep(textarea) {
	font-family: var(--font-face-monospace, monospace);
}

.template-form__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.template-form__note {
	margin: 0;
}

.template-form__save-hint {
	margin-inline-end: auto;
	color: var(--color-text-maxcontrast);
}

@media (max-width: 720px) {
	.template-form__grid {
		grid-template-columns: minmax(0, 1fr);
	}
}
</style>
