<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.

Build an email template from blocks (marketing-block-editor). The list is
nextcloud-vue CnBlockListEditor: a palette adds blocks, the list reorders by
dragging or with Move up and Move down (WCAG 2.2 success criterion 2.5.7),
and pressing a block's name opens its properties. This component draws the
properties of each mail block type. The footer sits under the list, always
last and never removed; its two compliance tokens are shown as fixed chips
the marketer cannot type away. The HTML is rendered on the server
(MailBlockRenderer), never here.

@spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
-->
<template>
	<div class="mail-block-editor">
		<CnBlockListEditor
			:modelValue="body"
			:types="types"
			:labels="labels"
			collapsible
			@update:modelValue="emitBlocks($event, footer)">
			<template #summary="{ block }">
				{{ summary(block) }}
			</template>
			<template #block-heading="{ block, update }">
				<NcTextField
					:modelValue="block.props.text || ''"
					:label="t('pipelinq', 'Heading text')"
					@update:modelValue="update(withProps(block, { text: $event }))" />
				<label class="mail-block-editor__field">
					<span>{{ t('pipelinq', 'Size') }}</span>
					<select
						:value="String(block.props.level || 2)"
						@change="update(withProps(block, { level: Number($event.target.value) }))">
						<option value="1">{{ t('pipelinq', 'Large') }}</option>
						<option value="2">{{ t('pipelinq', 'Medium') }}</option>
					</select>
				</label>
			</template>
			<template #block-text="{ block, update }">
				<CnMarkdownEditor
					:modelValue="block.props.markdown || ''"
					:aria-label="t('pipelinq', 'Text')"
					:rows="8"
					@update:modelValue="update(withProps(block, { markdown: $event }))" />
				<p class="mail-block-editor__hint">
					{{ t('pipelinq', 'Bold, italic, links and lists are kept. Anything else is sent as plain text.') }}
				</p>
			</template>
			<template #block-image="{ block, update }">
				<NcTextField
					:modelValue="block.props.src || ''"
					:label="t('pipelinq', 'Image address (https://)')"
					@update:modelValue="update(withProps(block, { src: $event }))" />
				<NcTextField
					:modelValue="block.props.alt || ''"
					:label="t('pipelinq', 'Alternative text')"
					:error="!block.props.alt"
					:helperText="block.props.alt ? '' : t('pipelinq', 'Describe the image for people who cannot see it.')"
					@update:modelValue="update(withProps(block, { alt: $event }))" />
				<NcTextField
					:modelValue="block.props.href || ''"
					:label="t('pipelinq', 'Link (optional)')"
					@update:modelValue="update(withProps(block, { href: $event }))" />
			</template>
			<template #block-button="{ block, update }">
				<NcTextField
					:modelValue="block.props.label || ''"
					:label="t('pipelinq', 'Button text')"
					@update:modelValue="update(withProps(block, { label: $event }))" />
				<NcTextField
					:modelValue="block.props.href || ''"
					:label="t('pipelinq', 'Link (https://)')"
					@update:modelValue="update(withProps(block, { href: $event }))" />
				<label class="mail-block-editor__field">
					<span>{{ t('pipelinq', 'Colour') }}</span>
					<input
						type="color"
						:value="block.props.color || defaultColor"
						@input="update(withProps(block, { color: $event.target.value }))">
				</label>
			</template>
			<template #block-spacer="{ block, update }">
				<label class="mail-block-editor__field">
					<span>{{ t('pipelinq', 'Height in pixels') }}</span>
					<input
						type="number"
						min="8"
						max="96"
						:value="block.props.height || 24"
						@input="update(withProps(block, { height: Number($event.target.value) }))">
				</label>
			</template>
			<template #block="{ block }">
				<p class="mail-block-editor__hint">
					{{ block.type === 'articles'
						? t('pipelinq', 'The articles picked below appear here.')
						: t('pipelinq', 'This block has nothing to set.') }}
				</p>
			</template>
		</CnBlockListEditor>

		<section
			class="mail-block-editor__footer"
			:aria-label="t('pipelinq', 'Footer')"
			data-testid="mail-block-footer">
			<h4>{{ t('pipelinq', 'Footer') }}</h4>
			<NcTextArea
				:modelValue="footerText"
				:label="t('pipelinq', 'Footer text')"
				rows="3"
				@update:modelValue="setFooterText" />
			<p class="mail-block-editor__hint">
				{{ t('pipelinq', 'The footer always ends with these two, so the mail can be sent:') }}
			</p>
			<ul class="mail-block-editor__chips">
				<li>{{ t('pipelinq', 'Your physical address') }}</li>
				<li>{{ t('pipelinq', 'The unsubscribe link') }}</li>
			</ul>
		</section>
	</div>
</template>

<script>
import { CnBlockListEditor, CnMarkdownEditor } from '@conduction/nextcloud-vue'
import { NcTextArea, NcTextField } from '@nextcloud/vue'
import {
	ADDABLE_TYPES,
	FOOTER_TOKENS,
	newBlock,
	withFooterLast,
} from '../../services/mailBlocks.js'

/**
 * A text without the footer's fixed tokens.
 *
 * @param {string} text The text.
 * @return {string} The text the marketer typed.
 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
 */
function withoutTokens(text) {
	return FOOTER_TOKENS.reduce((acc, token) => acc.split(token).join(''), text || '').trim()
}

export default {
	name: 'MailBlockEditor',
	components: {
		CnBlockListEditor,
		CnMarkdownEditor,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** The blocks, footer last. */
		modelValue: {
			type: Array,
			default: () => [],
		},

		/** The theming colour a button gets by default. */
		defaultColor: {
			type: String,
			default: '#00679e',
		},
	},

	emits: ['update:modelValue'],

	computed: {
		/**
		 * The blocks above the footer, which the list edits.
		 *
		 * @return {Array<object>} The blocks.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		body() {
			return withFooterLast(this.modelValue).slice(0, -1)
		},

		/**
		 * The footer block, always present.
		 *
		 * @return {object} The footer.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		footer() {
			return withFooterLast(this.modelValue).at(-1)
		},

		/**
		 * The palette: one entry per addable type, each made with empty content.
		 *
		 * @return {Array<{type: string, label: string, create: () => object}>} The types.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		types() {
			return ADDABLE_TYPES.map((type) => ({
				type,
				label: this.typeLabel(type),
				create: () => ({ props: newBlock(type).props }),
			}))
		},

		/**
		 * The list's own words, where the mail editor says it differently.
		 *
		 * @return {object} The labels.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		labels() {
			return { add: this.t('pipelinq', 'Add a block'), list: this.t('pipelinq', 'Blocks') }
		},

		/**
		 * The footer's own text, without the fixed tokens.
		 *
		 * @return {string} The text.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		footerText() {
			return withoutTokens(this.footer.props?.text)
		},
	},

	methods: {
		/**
		 * Emit the list with the footer last.
		 *
		 * @param {Array<object>} body The blocks above the footer.
		 * @param {object} footer The footer.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		emitBlocks(body, footer) {
			this.$emit('update:modelValue', [...body, footer])
		},

		/**
		 * The change that sets properties of a block, for the list's `update`.
		 *
		 * @param {object} block The block.
		 * @param {object} changed The properties and their new values.
		 * @return {{props: object}} The changed fields.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		withProps(block, changed) {
			return { props: { ...block.props, ...changed } }
		},

		/**
		 * Set the footer text; the fixed tokens are added back after it.
		 *
		 * @param {string} text The marketer's text.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		setFooterText(text) {
			const joined = [withoutTokens(text), ...FOOTER_TOKENS].filter(Boolean).join('\n')
			this.emitBlocks(this.body, { ...this.footer, ...this.withProps(this.footer, { text: joined }) })
		},

		/**
		 * A block type's name.
		 *
		 * @param {string} type The type.
		 * @return {string} The label.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		typeLabel(type) {
			return {
				heading: this.t('pipelinq', 'Heading'),
				text: this.t('pipelinq', 'Text'),
				image: this.t('pipelinq', 'Image'),
				button: this.t('pipelinq', 'Button'),
				divider: this.t('pipelinq', 'Divider'),
				spacer: this.t('pipelinq', 'Spacer'),
				articles: this.t('pipelinq', 'Articles'),
			}[type] || type
		},

		/**
		 * One line of what a block holds.
		 *
		 * @param {object} block The block.
		 * @return {string} The summary.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		summary(block) {
			const text = ['text', 'markdown', 'label', 'alt'].map((key) => block.props?.[key]).find(Boolean) || ''
			return String(text).split('{{')[0].slice(0, 60)
		},
	},
}
</script>

<style scoped>
.mail-block-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.mail-block-editor__footer {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.mail-block-editor__field {
	display: flex;
	align-items: center;
	gap: 8px;
}

.mail-block-editor__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.mail-block-editor__chips {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.mail-block-editor__chips li {
	padding: 2px 10px;
	border-radius: 12px;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}
</style>
