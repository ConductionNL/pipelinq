<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.

Build an email template from blocks (marketing-block-editor). A palette adds
blocks, by button or by dragging them into the list; the list reorders by
dragging or with Move up and Move down, which also serve the keyboard and
anyone who cannot drag (WCAG 2.2 success criterion 2.5.7). Picking a block
opens its properties. The footer is always last and cannot be removed; its
two compliance tokens are shown as fixed chips the marketer cannot type away.
The HTML is rendered on the server (MailBlockRenderer), never here.

@spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
-->
<template>
	<div class="mail-block-editor">
		<div
			class="mail-block-editor__palette"
			role="group"
			:aria-label="t('pipelinq', 'Add a block')">
			<span class="mail-block-editor__palette-label">{{
				t('pipelinq', 'Add a block')
			}}</span>
			<Draggable
				:list="paletteItems"
				:group="{ name: 'mail-blocks', pull: 'clone', put: false }"
				:clone="cloneFromPalette"
				:sort="false"
				itemKey="type"
				class="mail-block-editor__palette-items"
				@end="onPaletteDrop">
				<template #item="{ element }">
					<NcButton
						variant="secondary"
						:data-testid="'mail-block-add-' + element.type"
						@click="add(element.type)">
						{{ typeLabel(element.type) }}
					</NcButton>
				</template>
			</Draggable>
		</div>

		<Draggable
			:modelValue="blocks"
			:group="{ name: 'mail-blocks', pull: false, put: true }"
			itemKey="id"
			handle=".mail-block-editor__handle"
			:move="canDragTo"
			tag="ol"
			class="mail-block-editor__list"
			:aria-label="t('pipelinq', 'Blocks')"
			@update:modelValue="onListChange">
			<template #item="{ element: block, index }">
				<li
					class="mail-block-editor__block"
					:class="{
						'mail-block-editor__block--selected':
							selectedId === block.id,
					}"
					:data-testid="'mail-block-' + block.type">
					<span
						v-if="block.type !== 'footer'"
						class="mail-block-editor__handle"
						:title="t('pipelinq', 'Drag to reorder')"
						aria-hidden="true"
						>&#x2630;</span
					>
					<button
						type="button"
						class="mail-block-editor__select"
						:aria-pressed="selectedId === block.id ? 'true' : 'false'"
						@click="selectedId = block.id">
						<strong>{{ typeLabel(block.type) }}</strong>
						<span class="mail-block-editor__summary">{{
							summary(block)
						}}</span>
					</button>
					<span
						v-if="block.type !== 'footer'"
						class="mail-block-editor__actions">
						<NcButton
							:ref="'up-' + block.id"
							variant="tertiary"
							:disabled="index === 0"
							:aria-label="
								t('pipelinq', 'Move {block} up', {
									block: typeLabel(block.type),
								})
							"
							@click="move(index, -1)">
							<template #icon>
								<ChevronUp :size="18" />
							</template>
						</NcButton>
						<NcButton
							:ref="'down-' + block.id"
							variant="tertiary"
							:disabled="index >= blocks.length - 2"
							:aria-label="
								t('pipelinq', 'Move {block} down', {
									block: typeLabel(block.type),
								})
							"
							@click="move(index, 1)">
							<template #icon>
								<ChevronDown :size="18" />
							</template>
						</NcButton>
						<NcButton
							variant="tertiary"
							:aria-label="
								t('pipelinq', 'Remove {block}', {
									block: typeLabel(block.type),
								})
							"
							@click="remove(index)">
							<template #icon>
								<Delete :size="18" />
							</template>
						</NcButton>
					</span>
				</li>
			</template>
		</Draggable>

		<section
			v-if="selected"
			class="mail-block-editor__properties"
			:aria-label="t('pipelinq', 'Block properties')">
			<h4>{{ typeLabel(selected.type) }}</h4>
			<template v-if="selected.type === 'heading'">
				<NcTextField
					:modelValue="selected.props.text || ''"
					:label="t('pipelinq', 'Heading text')"
					@update:modelValue="setProp('text', $event)" />
				<label class="mail-block-editor__field">
					<span>{{ t('pipelinq', 'Size') }}</span>
					<select
						:value="String(selected.props.level || 2)"
						@change="setProp('level', Number($event.target.value))">
						<option value="1">{{ t('pipelinq', 'Large') }}</option>
						<option value="2">{{ t('pipelinq', 'Medium') }}</option>
					</select>
				</label>
			</template>
			<template v-else-if="selected.type === 'text'">
				<CnMarkdownEditor
					:modelValue="selected.props.markdown || ''"
					:aria-label="t('pipelinq', 'Text')"
					:rows="8"
					@update:modelValue="setProp('markdown', $event)" />
				<p class="mail-block-editor__hint">
					{{
						t(
							'pipelinq',
							'Bold, italic, links and lists are kept. Anything else is sent as plain text.',
						)
					}}
				</p>
			</template>
			<template v-else-if="selected.type === 'image'">
				<NcTextField
					:modelValue="selected.props.src || ''"
					:label="t('pipelinq', 'Image address (https://)')"
					@update:modelValue="setProp('src', $event)" />
				<NcTextField
					:modelValue="selected.props.alt || ''"
					:label="t('pipelinq', 'Alternative text')"
					:error="!selected.props.alt"
					:helperText="
						selected.props.alt
							? ''
							: t(
									'pipelinq',
									'Describe the image for people who cannot see it.',
								)
					"
					@update:modelValue="setProp('alt', $event)" />
				<NcTextField
					:modelValue="selected.props.href || ''"
					:label="t('pipelinq', 'Link (optional)')"
					@update:modelValue="setProp('href', $event)" />
			</template>
			<template v-else-if="selected.type === 'button'">
				<NcTextField
					:modelValue="selected.props.label || ''"
					:label="t('pipelinq', 'Button text')"
					@update:modelValue="setProp('label', $event)" />
				<NcTextField
					:modelValue="selected.props.href || ''"
					:label="t('pipelinq', 'Link (https://)')"
					@update:modelValue="setProp('href', $event)" />
				<label class="mail-block-editor__field">
					<span>{{ t('pipelinq', 'Colour') }}</span>
					<input
						type="color"
						:value="selected.props.color || defaultColor"
						@input="setProp('color', $event.target.value)" />
				</label>
			</template>
			<template v-else-if="selected.type === 'spacer'">
				<label class="mail-block-editor__field">
					<span>{{ t('pipelinq', 'Height in pixels') }}</span>
					<input
						type="number"
						min="8"
						max="96"
						:value="selected.props.height || 24"
						@input="setProp('height', Number($event.target.value))" />
				</label>
			</template>
			<template v-else-if="selected.type === 'footer'">
				<NcTextArea
					:modelValue="footerText"
					:label="t('pipelinq', 'Footer text')"
					rows="3"
					@update:modelValue="setFooterText($event)" />
				<p class="mail-block-editor__hint">
					{{
						t(
							'pipelinq',
							'The footer always ends with these two, so the mail can be sent:',
						)
					}}
				</p>
				<ul class="mail-block-editor__chips">
					<li>{{ t('pipelinq', 'Your physical address') }}</li>
					<li>{{ t('pipelinq', 'The unsubscribe link') }}</li>
				</ul>
			</template>
			<p v-else class="mail-block-editor__hint">
				{{
					selected.type === 'articles'
						? t('pipelinq', 'The articles picked below appear here.')
						: t('pipelinq', 'This block has nothing to set.')
				}}
			</p>
		</section>
	</div>
</template>

<script>
import { CnMarkdownEditor } from '@conduction/nextcloud-vue'
import { NcButton, NcTextArea, NcTextField } from '@nextcloud/vue'
import draggable from 'vuedraggable'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import {
	ADDABLE_TYPES,
	addBlock,
	FOOTER_TOKENS,
	moveBlock,
	newBlock,
	removeBlock,
	withFooterLast,
} from '../../services/mailBlocks.js'

export default {
	name: 'MailBlockEditor',
	components: {
		ChevronDown,
		ChevronUp,
		CnMarkdownEditor,
		Delete,
		Draggable: draggable,
		NcButton,
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

	data() {
		return {
			selectedId: '',
		}
	},

	computed: {
		/**
		 * The blocks with one footer, last.
		 *
		 * @return {Array<object>} The blocks.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		blocks() {
			return withFooterLast(this.modelValue)
		},

		/**
		 * The palette entries.
		 *
		 * @return {Array<{type: string}>} One per addable type.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		paletteItems() {
			return ADDABLE_TYPES.map((type) => ({ type }))
		},

		/**
		 * The block whose properties are open.
		 *
		 * @return {object|null} The block.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		selected() {
			return this.blocks.find((b) => b.id === this.selectedId) || null
		},

		/**
		 * The footer's own text, without the fixed tokens.
		 *
		 * @return {string} The text.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		footerText() {
			const text = (this.selected && this.selected.props.text) || ''
			return FOOTER_TOKENS.reduce(
				(acc, token) => acc.split(token).join(''),
				text,
			).trim()
		},
	},

	methods: {
		/**
		 * Emit a new block list.
		 *
		 * @param {Array<object>} blocks The blocks.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		emitBlocks(blocks) {
			this.$emit('update:modelValue', withFooterLast(blocks))
		},

		/**
		 * Add a block above the footer and open it.
		 *
		 * @param {string} type The block type.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		add(type) {
			const next = addBlock(this.blocks, type)
			this.selectedId = next[next.length - 2].id
			this.emitBlocks(next)
		},

		/**
		 * A palette drag makes a fresh block.
		 *
		 * @param {{type: string}} item The palette entry.
		 * @return {object} The new block.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		cloneFromPalette(item) {
			return newBlock(item.type)
		},

		/**
		 * Open the block a palette drag dropped.
		 *
		 * @param {object} event The sortable event.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		onPaletteDrop(event) {
			if (
				event
				&& event.to !== event.from
				&& event.item
				&& event.item.__draggable_context
			) {
				this.selectedId =
					event.item.__draggable_context.element?.id || this.selectedId
			}
		},

		/**
		 * A drag in the list: keep the footer last.
		 *
		 * @param {Array<object>} blocks The list after the drag.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		onListChange(blocks) {
			this.emitBlocks(blocks)
		},

		/**
		 * Nothing is dragged onto or below the footer.
		 *
		 * @param {object} event The sortable move event.
		 * @return {boolean} Whether the move is allowed.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		canDragTo(event) {
			const related = event.relatedContext && event.relatedContext.element
			return !(related && related.type === 'footer' && event.willInsertAfter)
		},

		/**
		 * Move a block with the buttons, and keep focus on it.
		 *
		 * @param {number} index The block.
		 * @param {number} delta -1 up, +1 down.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		move(index, delta) {
			const block = this.blocks[index]
			const next = moveBlock(this.blocks, index, delta)
			if (next === this.blocks) {
				return
			}
			this.emitBlocks(next)
			this.$nextTick(() => {
				// Focus the button that was used, or the other one when the
				// block reached the end it was moving towards.
				const newIndex = next.indexOf(block)
				const atTop = newIndex === 0
				const atBottom = newIndex >= next.length - 2
				let direction = delta < 0 ? 'up-' : 'down-'
				if ((delta < 0 && atTop) || (delta > 0 && atBottom)) {
					direction = delta < 0 ? 'down-' : 'up-'
				}
				const ref = this.$refs[direction + block.id]
				const button = Array.isArray(ref) ? ref[0] : ref
				const el = button && (button.$el || button)
				if (el && typeof el.focus === 'function') {
					el.focus()
				}
			})
		},

		/**
		 * Remove a block.
		 *
		 * @param {number} index The block.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		remove(index) {
			if (this.blocks[index] && this.blocks[index].id === this.selectedId) {
				this.selectedId = ''
			}
			this.emitBlocks(removeBlock(this.blocks, index))
		},

		/**
		 * Set one property of the open block.
		 *
		 * @param {string} key The property.
		 * @param {string|number} value The value.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		setProp(key, value) {
			const next = this.blocks.map((b) =>
				b.id === this.selectedId
					? { ...b, props: { ...b.props, [key]: value } }
					: b,
			)
			this.emitBlocks(next)
		},

		/**
		 * Set the footer text; the fixed tokens are added back after it.
		 *
		 * @param {string} text The marketer's text.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		setFooterText(text) {
			const own = FOOTER_TOKENS.reduce(
				(acc, token) => acc.split(token).join(''),
				text || '',
			).trim()
			this.setProp('text', [own, ...FOOTER_TOKENS].filter(Boolean).join('\n'))
		},

		/**
		 * A block type's name.
		 *
		 * @param {string} type The type.
		 * @return {string} The label.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		typeLabel(type) {
			return (
				{
					heading: this.t('pipelinq', 'Heading'),
					text: this.t('pipelinq', 'Text'),
					image: this.t('pipelinq', 'Image'),
					button: this.t('pipelinq', 'Button'),
					divider: this.t('pipelinq', 'Divider'),
					spacer: this.t('pipelinq', 'Spacer'),
					articles: this.t('pipelinq', 'Articles'),
					footer: this.t('pipelinq', 'Footer'),
				}[type] || type
			)
		},

		/**
		 * One line of what a block holds.
		 *
		 * @param {object} block The block.
		 * @return {string} The summary.
		 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
		 */
		summary(block) {
			const p = block.props || {}
			const text = p.text || p.markdown || p.label || p.alt || ''
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

.mail-block-editor__palette {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.mail-block-editor__palette-items {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.mail-block-editor__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.mail-block-editor__block {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.mail-block-editor__block--selected {
	border-color: var(--color-primary-element);
}

.mail-block-editor__handle {
	cursor: grab;
	color: var(--color-text-maxcontrast);
}

.mail-block-editor__select {
	display: flex;
	flex: 1;
	gap: 8px;
	align-items: baseline;
	min-height: 44px;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	text-align: start;
	cursor: pointer;
}

.mail-block-editor__summary {
	overflow: hidden;
	color: var(--color-text-maxcontrast);
	text-overflow: ellipsis;
	white-space: nowrap;
}

.mail-block-editor__actions {
	display: flex;
}

.mail-block-editor__properties {
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
